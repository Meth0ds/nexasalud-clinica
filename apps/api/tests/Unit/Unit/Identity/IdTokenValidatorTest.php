<?php

namespace Tests\Unit\Unit\Identity;

use App\Exceptions\IdentityMfaEnrollmentCompletedException;
use App\Exceptions\IdentityProtocolException;
use App\Services\Identity\IdTokenValidator;
use App\Services\Identity\OidcDiscovery;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

class IdTokenValidatorTest extends TestCase
{
    private const ISSUER = 'https://identity.example.test/realms/nexasalud';

    private const ACCESS_TOKEN = 'opaque-access-token-never-exposed';

    private string $privateKey;

    /** @var array<string, mixed> */
    private array $jwk;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
        config()->set([
            'services.keycloak.issuer' => self::ISSUER,
            'services.keycloak.client_id' => 'nexasalud-bff',
            'services.keycloak.allowed_algorithms' => ['RS256'],
            'services.keycloak.required_acr_values' => ['1'],
            'services.keycloak.required_amr_values' => ['pwd', 'otp'],
            'services.keycloak.discovery_cache_seconds' => 300,
            'services.keycloak.jwks_cache_seconds' => 600,
            'services.keycloak.connect_timeout_seconds' => 2,
            'services.keycloak.timeout_seconds' => 5,
            'services.keycloak.clock_skew_seconds' => 30,
            'services.keycloak.allow_insecure_http' => false,
        ]);

        [$this->privateKey, $this->jwk] = $this->rsaKeyPair('primary-key');
    }

    public function test_it_accepts_a_signed_id_token_with_all_required_claims(): void
    {
        $this->fakeIdentityEndpoints([$this->jwk]);
        $claims = $this->claims();
        $claims['at_hash'] = $this->accessTokenHash(self::ACCESS_TOKEN);

        $validated = $this->validator()->validate(
            $this->token($claims),
            'expected-nonce',
            self::ACCESS_TOKEN,
        );

        $this->assertSame('patient-safe-subject', $validated['sub']);
        $this->assertSame(['pwd', 'otp'], $validated['amr']);
    }

    public function test_it_rejects_nonce_issuer_and_audience_confusion(): void
    {
        $this->fakeIdentityEndpoints([$this->jwk]);

        $invalidClaims = [
            'nonce' => ['nonce' => 'different-nonce'],
            'issuer' => ['iss' => 'https://attacker.example/realms/nexasalud'],
            'audience' => ['aud' => 'different-client'],
            'authorized party' => ['aud' => ['nexasalud-bff', 'other-client'], 'azp' => 'other-client'],
            'future authentication time' => ['auth_time' => time() + 120],
        ];

        foreach ($invalidClaims as $case => $overrides) {
            try {
                $this->validator()->validate(
                    $this->token(array_replace($this->claims(), $overrides)),
                    'expected-nonce',
                    self::ACCESS_TOKEN,
                );
                $this->fail("The {$case} claim set should have been rejected.");
            } catch (IdentityProtocolException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_rejects_expired_and_not_yet_valid_tokens(): void
    {
        $this->fakeIdentityEndpoints([$this->jwk]);

        foreach ([
            'expired' => ['exp' => time() - 120],
            'future nbf' => ['nbf' => time() + 120],
            'future iat with a current nbf' => ['iat' => time() + 120, 'nbf' => time() - 5],
        ] as $case => $overrides) {
            try {
                $this->validator()->validate(
                    $this->token(array_replace($this->claims(), $overrides)),
                    'expected-nonce',
                    self::ACCESS_TOKEN,
                );
                $this->fail("The {$case} token should have been rejected.");
            } catch (IdentityProtocolException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_rejects_inadequate_authentication_context_and_access_token_hash(): void
    {
        $this->fakeIdentityEndpoints([$this->jwk]);

        foreach ([
            'acr' => ['acr' => '0'],
            'amr' => ['amr' => ['pwd']],
            'at_hash' => ['at_hash' => 'invalid-hash'],
        ] as $case => $overrides) {
            try {
                $this->validator()->validate(
                    $this->token(array_replace($this->claims(), $overrides)),
                    'expected-nonce',
                    self::ACCESS_TOKEN,
                );
                $this->fail("The {$case} token should have been rejected.");
            } catch (IdentityProtocolException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_distinguishes_the_safe_second_login_required_after_totp_enrolment(): void
    {
        $this->fakeIdentityEndpoints([$this->jwk]);

        $this->expectException(IdentityMfaEnrollmentCompletedException::class);

        $this->validator()->validate(
            $this->token(array_replace($this->claims(), ['amr' => ['pwd']])),
            'expected-nonce',
            self::ACCESS_TOKEN,
        );
    }

    public function test_it_rejects_algorithms_outside_the_asymmetric_allowlist(): void
    {
        $this->fakeIdentityEndpoints([$this->jwk]);
        $token = JWT::encode($this->claims(), str_repeat('s', 64), 'HS256', 'primary-key');

        $this->expectException(IdentityProtocolException::class);

        $this->validator()->validate($token, 'expected-nonce', self::ACCESS_TOKEN);
    }

    public function test_it_refreshes_jwks_once_when_keycloak_rotates_to_an_unknown_kid(): void
    {
        [$rotatedPrivateKey, $rotatedJwk] = $this->rsaKeyPair('rotated-key');
        $metadata = $this->metadata();

        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => Http::response($metadata),
            $metadata['jwks_uri'] => Http::sequence()
                ->push(['keys' => [$this->jwk]])
                ->push(['keys' => [$rotatedJwk]]),
        ]);

        $token = JWT::encode($this->claims(), $rotatedPrivateKey, 'RS256', 'rotated-key');
        $validated = $this->validator()->validate($token, 'expected-nonce', self::ACCESS_TOKEN);

        $this->assertSame('patient-safe-subject', $validated['sub']);
        Http::assertSentCount(4);
    }

    public function test_it_rejects_discovery_endpoints_that_escape_the_pinned_issuer(): void
    {
        $metadata = $this->metadata();
        $metadata['jwks_uri'] = 'https://attacker.example/keys';

        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => Http::response($metadata),
        ]);

        $this->expectException(IdentityProtocolException::class);

        $this->validator()->validate(
            $this->token($this->claims()),
            'expected-nonce',
            self::ACCESS_TOKEN,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $keys
     */
    private function fakeIdentityEndpoints(array $keys): void
    {
        $metadata = $this->metadata();

        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => Http::response($metadata),
            $metadata['jwks_uri'] => Http::response(['keys' => $keys]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(): array
    {
        return [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/protocol/openid-connect/auth',
            'token_endpoint' => self::ISSUER.'/protocol/openid-connect/token',
            'userinfo_endpoint' => self::ISSUER.'/protocol/openid-connect/userinfo',
            'jwks_uri' => self::ISSUER.'/protocol/openid-connect/certs',
            'end_session_endpoint' => self::ISSUER.'/protocol/openid-connect/logout',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function claims(): array
    {
        return [
            'iss' => self::ISSUER,
            'sub' => 'patient-safe-subject',
            'aud' => 'nexasalud-bff',
            'exp' => time() + 300,
            'iat' => time() - 5,
            'auth_time' => time() - 10,
            'nonce' => 'expected-nonce',
            'acr' => '1',
            'amr' => ['pwd', 'otp'],
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function token(array $claims): string
    {
        return JWT::encode($claims, $this->privateKey, 'RS256', 'primary-key');
    }

    private function validator(): IdTokenValidator
    {
        return new IdTokenValidator(new OidcDiscovery);
    }

    private function accessTokenHash(string $accessToken): string
    {
        $digest = hash('sha256', $accessToken, true);

        return rtrim(strtr(base64_encode(substr($digest, 0, intdiv(strlen($digest), 2))), '+/', '-_'), '=');
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function rsaKeyPair(string $keyId): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);

        return [
            $privateKey,
            [
                'kty' => 'RSA',
                'kid' => $keyId,
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => $this->base64UrlEncode($details['rsa']['n']),
                'e' => $this->base64UrlEncode($details['rsa']['e']),
            ],
        ];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
