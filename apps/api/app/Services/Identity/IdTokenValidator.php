<?php

namespace App\Services\Identity;

use App\Exceptions\IdentityMfaEnrollmentCompletedException;
use App\Exceptions\IdentityProtocolException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * Verifies signed OpenID Connect ID tokens against trusted issuer metadata and policy.
 */
final class IdTokenValidator
{
    /**
     * Create an ID token validator backed by trusted OIDC discovery data.
     */
    public function __construct(private OidcDiscovery $discovery) {}

    /**
     * Verify an ID token and return its validated claims.
     *
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
            Log::warning('ID token validation failed', [
                'claims' => $this->redactClaims($claims ?? []),
                'expected_nonce' => $expectedNonce,
                'issuer' => $this->discovery->issuer(),
                'client_id' => config('services.keycloak.client_id'),
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('ID token verification threw', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw new IdentityProtocolException($exception);
        }
    }

    /**
     * Extract the validation-relevant claims for a local diagnostic log without
     * persisting signature material or bearer tokens.
     *
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     */
    private function redactClaims(array $claims): array
    {
        $selected = [];

        foreach (['iss', 'sub', 'aud', 'azp', 'nonce', 'acr', 'amr', 'exp', 'iat', 'auth_time', 'nbf'] as $claim) {
            if (array_key_exists($claim, $claims)) {
                $selected[$claim] = $claims[$claim];
            }
        }

        $selected['has_at_hash'] = array_key_exists('at_hash', $claims);

        return $selected;
    }

    /**
     * Decode and validate the untrusted JWT header before selecting a signing key.
     *
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
     * Select an explicitly configured asymmetric signature algorithm from the token header.
     *
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

    /**
     * Verify the token signature, refreshing the issuer key set once for key rotation.
     */
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
     * Select issuer verification keys that exactly match the token key ID and algorithm.
     *
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
     * Enforce issuer, nonce, time, audience, assurance and token-binding claims.
     *
     * @param  array<string, mixed>  $claims
     */
    private function validateClaims(array $claims, string $expectedNonce, string $accessToken, string $algorithm): void
    {
        $issuer = $this->requiredString($claims, 'iss');
        $subject = $this->requiredString($claims, 'sub');
        $nonce = $this->requiredString($claims, 'nonce');
        $expiresAt = $this->requiredNumeric($claims, 'exp');
        $issuedAt = $this->requiredNumeric($claims, 'iat');

        // auth_time is OPTIONAL in OpenID Connect Core and is only emitted by
        // Keycloak when the client is assigned the auth_time scope. When present
        // it must describe the authentication moment; otherwise the issuance
        // time is the closest verifiable anchor.
        $authenticatedAt = array_key_exists('auth_time', $claims)
            ? $this->requiredNumeric($claims, 'auth_time')
            : $issuedAt;

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
     * Ensure the token audience and authorized party identify this client only.
     *
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
     * Enforce the configured authentication context and multi-factor methods.
     *
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

    /**
     * Verify the optional access-token hash binding in an ID token.
     */
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
     * Read a required non-empty string value from token claims or key data.
     *
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
     * Read a required numeric timestamp claim.
     *
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
     * Ensure a JWK key type, curve or modulus is appropriate for the requested algorithm.
     *
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
     * Ensure a JWK is permitted to perform signature verification when operations are declared.
     *
     * @param  array<string, mixed>  $key
     */
    private function keyAllowsVerification(array $key): bool
    {
        $operations = $key['key_ops'] ?? null;

        return $operations === null || (is_array($operations) && in_array('verify', $operations, true));
    }

    /**
     * Return the bounded clock-skew tolerance configured for identity assertions.
     */
    private function clockSkew(): int
    {
        $leeway = config('services.keycloak.clock_skew_seconds');

        if (! is_int($leeway) || $leeway < 0 || $leeway > 300) {
            throw new IdentityProtocolException;
        }

        return $leeway;
    }
}
