<?php

namespace App\Services\Identity;

use App\Exceptions\IdentityMfaEnrollmentCompletedException;
use App\Exceptions\IdentityProtocolException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use JsonException;
use Throwable;

final class IdTokenValidator
{
    public function __construct(private OidcDiscovery $discovery) {}

    /**
     * @return array<string, mixed>
     */
    public function validate(string $idToken, string $expectedNonce, string $accessToken): array
    {
        try {
            if (strlen($idToken) > 32768 || strlen($expectedNonce) > 512 || strlen($accessToken) > 16384) {
                throw new IdentityProtocolException;
            }

            $header = $this->decodeHeader($idToken);
            $algorithm = $this->allowedAlgorithm($header);
            $keyId = $this->requiredString($header, 'kid');
            $payload = $this->decodeAndVerify($idToken, $keyId, $algorithm);
            $claims = (array) $payload;

            $this->validateClaims($claims, $expectedNonce, $accessToken, $algorithm);

            return $claims;
        } catch (IdentityProtocolException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new IdentityProtocolException($exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeHeader(string $idToken): array
    {
        $segments = explode('.', $idToken);

        if (count($segments) !== 3 || $segments[0] === '') {
            throw new IdentityProtocolException;
        }

        try {
            $decoded = JWT::urlsafeB64Decode($segments[0]);
            $header = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new IdentityProtocolException($exception);
        }

        if (! is_array($header)) {
            throw new IdentityProtocolException;
        }

        return $header;
    }

    /**
     * @param  array<string, mixed>  $header
     */
    private function allowedAlgorithm(array $header): string
    {
        $algorithm = $this->requiredString($header, 'alg');
        $allowedAlgorithms = config('services.keycloak.allowed_algorithms');

        if (! is_array($allowedAlgorithms) || ! in_array($algorithm, $allowedAlgorithms, true)) {
            throw new IdentityProtocolException;
        }

        if (! in_array($algorithm, ['RS256', 'RS384', 'RS512', 'PS256', 'ES256', 'ES384'], true)) {
            throw new IdentityProtocolException;
        }

        return $algorithm;
    }

    private function decodeAndVerify(string $idToken, string $keyId, string $algorithm): object
    {
        $keys = $this->matchingKeys($this->discovery->jwks(), $keyId, $algorithm);

        if ($keys === []) {
            $keys = $this->matchingKeys($this->discovery->jwks(true), $keyId, $algorithm);
        }

        if (count($keys) !== 1) {
            throw new IdentityProtocolException;
        }

        $leeway = $this->clockSkew();

        $previousLeeway = JWT::$leeway;

        try {
            JWT::$leeway = $leeway;

            return JWT::decode($idToken, JWK::parseKeySet(['keys' => $keys], $algorithm));
        } finally {
            JWT::$leeway = $previousLeeway;
        }
    }

    /**
     * @param  array{keys: list<array<string, mixed>>}  $jwks
     * @return list<array<string, mixed>>
     */
    private function matchingKeys(array $jwks, string $keyId, string $algorithm): array
    {
        return array_values(array_filter(
            $jwks['keys'],
            function (array $key) use ($keyId, $algorithm): bool {
                return ($key['kid'] ?? null) === $keyId
                    && ($key['use'] ?? 'sig') === 'sig'
                    && ($key['alg'] ?? $algorithm) === $algorithm
                    && $this->keyMatchesAlgorithm($key, $algorithm)
                    && $this->keyAllowsVerification($key);
            },
        ));
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function validateClaims(array $claims, string $expectedNonce, string $accessToken, string $algorithm): void
    {
        $issuer = $this->requiredString($claims, 'iss');
        $subject = $this->requiredString($claims, 'sub');
        $nonce = $this->requiredString($claims, 'nonce');
        $expiresAt = $this->requiredNumeric($claims, 'exp');
        $issuedAt = $this->requiredNumeric($claims, 'iat');
        $authenticatedAt = $this->requiredNumeric($claims, 'auth_time');

        if (array_key_exists('nbf', $claims)) {
            $this->requiredNumeric($claims, 'nbf');
        }

        if (
            $subject === '' ||
            ! hash_equals($this->discovery->issuer(), $issuer) ||
            ! hash_equals($expectedNonce, $nonce) ||
            $issuedAt > time() + $this->clockSkew() ||
            $issuedAt > $expiresAt ||
            $authenticatedAt > $issuedAt + $this->clockSkew()
        ) {
            throw new IdentityProtocolException;
        }

        $this->validateAudience($claims);
        $this->validateAuthenticationContext($claims);

        if (array_key_exists('at_hash', $claims)) {
            $this->validateAccessTokenHash($claims['at_hash'], $accessToken, $algorithm);
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function validateAudience(array $claims): void
    {
        $clientId = config('services.keycloak.client_id');

        if (! is_string($clientId) || $clientId === '') {
            throw new IdentityProtocolException;
        }

        $audienceClaim = $claims['aud'] ?? null;
        $audiences = is_string($audienceClaim) ? [$audienceClaim] : $audienceClaim;

        if (! is_array($audiences) || $audiences === [] || count(array_filter($audiences, 'is_string')) !== count($audiences)) {
            throw new IdentityProtocolException;
        }

        if (! in_array($clientId, $audiences, true)) {
            throw new IdentityProtocolException;
        }

        $authorizedParty = $claims['azp'] ?? null;

        if ((count($audiences) > 1 || $authorizedParty !== null) && $authorizedParty !== $clientId) {
            throw new IdentityProtocolException;
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function validateAuthenticationContext(array $claims): void
    {
        $requiredAcrValues = config('services.keycloak.required_acr_values', []);
        $requiredAmrValues = config('services.keycloak.required_amr_values', []);
        $acr = $claims['acr'] ?? null;
        $amr = $claims['amr'] ?? [];

        if (! is_array($requiredAcrValues) || ! is_array($requiredAmrValues) || ! is_array($amr)) {
            throw new IdentityProtocolException;
        }

        if ($requiredAcrValues !== [] && (! is_string($acr) || ! in_array($acr, $requiredAcrValues, true))) {
            throw new IdentityProtocolException;
        }

        foreach ($requiredAcrValues as $requiredAcrValue) {
            if (! is_string($requiredAcrValue) || $requiredAcrValue === '') {
                throw new IdentityProtocolException;
            }
        }

        foreach ($requiredAmrValues as $requiredMethod) {
            if (! is_string($requiredMethod) || ! in_array($requiredMethod, $amr, true)) {
                if ($requiredMethod === 'otp' && in_array('pwd', $amr, true)) {
                    throw new IdentityMfaEnrollmentCompletedException;
                }

                throw new IdentityProtocolException;
            }
        }
    }

    private function validateAccessTokenHash(mixed $claim, string $accessToken, string $algorithm): void
    {
        if (! is_string($claim) || $claim === '' || $accessToken === '') {
            throw new IdentityProtocolException;
        }

        $hashAlgorithm = match (substr($algorithm, -3)) {
            '256' => 'sha256',
            '384' => 'sha384',
            '512' => 'sha512',
            default => throw new IdentityProtocolException,
        };

        $digest = hash($hashAlgorithm, $accessToken, true);
        $expected = rtrim(strtr(base64_encode(substr($digest, 0, intdiv(strlen($digest), 2))), '+/', '-_'), '=');

        if (! hash_equals($expected, $claim)) {
            throw new IdentityProtocolException;
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function requiredString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new IdentityProtocolException;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function requiredNumeric(array $values, string $key): int|float
    {
        $value = $values[$key] ?? null;

        if (! is_int($value) && ! is_float($value)) {
            throw new IdentityProtocolException;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $key
     */
    private function keyMatchesAlgorithm(array $key, string $algorithm): bool
    {
        return match ($algorithm) {
            'RS256', 'RS384', 'RS512', 'PS256' => ($key['kty'] ?? null) === 'RSA'
                && is_string($key['n'] ?? null)
                && strlen(JWT::urlsafeB64Decode($key['n'])) >= 256,
            'ES256' => ($key['kty'] ?? null) === 'EC' && ($key['crv'] ?? null) === 'P-256',
            'ES384' => ($key['kty'] ?? null) === 'EC' && ($key['crv'] ?? null) === 'P-384',
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $key
     */
    private function keyAllowsVerification(array $key): bool
    {
        $operations = $key['key_ops'] ?? null;

        return $operations === null || (is_array($operations) && in_array('verify', $operations, true));
    }

    private function clockSkew(): int
    {
        $leeway = config('services.keycloak.clock_skew_seconds');

        if (! is_int($leeway) || $leeway < 0 || $leeway > 300) {
            throw new IdentityProtocolException;
        }

        return $leeway;
    }
}
