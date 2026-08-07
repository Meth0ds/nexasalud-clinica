<?php

namespace Tests\Unit\Unit\Identity;

use App\Exceptions\IdentityAccessDeniedException;
use App\Exceptions\IdentityUnavailableException;
use App\Exceptions\InvalidOidcTransactionException;
use App\Services\Identity\IdTokenValidator;
use App\Services\Identity\OidcDiscovery;
use App\Services\Identity\SecureKeycloakProvider;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

class SecureKeycloakProviderTest extends TestCase
{
    private const ISSUER = 'https://identity.example.test/realms/nexasalud';

    private const ACCESS_TOKEN = 'server-side-access-token';

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
            'services.keycloak.client_secret' => 'only-the-bff-knows-this-secret',
            'services.keycloak.redirect' => 'https://api.example.test/auth/callback',
            'services.keycloak.allowed_algorithms' => ['RS256'],
            'services.keycloak.required_acr_values' => ['1'],
            'services.keycloak.required_amr_values' => ['pwd', 'otp'],
            'services.keycloak.transaction_ttl_seconds' => 300,
            'services.keycloak.discovery_cache_seconds' => 300,
            'services.keycloak.jwks_cache_seconds' => 600,
            'services.keycloak.connect_timeout_seconds' => 2,
            'services.keycloak.timeout_seconds' => 5,
            'services.keycloak.clock_skew_seconds' => 30,
            'services.keycloak.allow_insecure_http' => false,
        ]);

        [$this->privateKey, $this->jwk] = $this->rsaKeyPair();
        $this->fakeIdentityEndpoints();
    }

    public function test_redirect_uses_cryptographic_state_nonce_acr_and_pkce_s256(): void
    {
        $session = $this->newSessionStore();
        $provider = $this->provider($this->request('/auth/login', $session));

        $response = $provider->redirectFor('/agenda?view=week');
        parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);
        $transaction = $session->get(SecureKeycloakProvider::TRANSACTION_SESSION_KEY);

        $this->assertIsArray($transaction);
        $this->assertSame($transaction['state'], $query['state']);
        $this->assertSame($transaction['nonce'], $query['nonce']);
        $this->assertSame('1', $query['acr_values']);
        $claimsRequest = json_decode($query['claims'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($claimsRequest['id_token']['acr']['essential']);
        $this->assertSame(['1'], $claimsRequest['id_token']['acr']['values']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(
            $this->base64UrlEncode(hash('sha256', $transaction['code_verifier'], true)),
            $query['code_challenge'],
        );
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('openid profile email', $query['scope']);
        $this->assertArrayNotHasKey('client_secret', $query);
    }

    public function test_parallel_redirects_keep_independent_transactions(): void
    {
        $session = $this->newSessionStore();
        $firstProvider = $this->provider($this->request('/auth/login', $session));
        $firstProvider->redirectFor('/agenda');
        $first = $session->get(SecureKeycloakProvider::TRANSACTION_SESSION_KEY);

        $secondProvider = $this->provider($this->request('/auth/login', $session));
        $secondProvider->redirectFor('/portal');
        $second = $session->get(SecureKeycloakProvider::TRANSACTION_SESSION_KEY);
        $pending = $session->get(SecureKeycloakProvider::TRANSACTIONS_SESSION_KEY);

        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $this->assertIsArray($pending);
        $this->assertCount(2, $pending);
        $this->assertArrayHasKey($first['state'], $pending);
        $this->assertArrayHasKey($second['state'], $pending);
        $this->assertNotSame($first['state'], $second['state']);
    }

    public function test_callback_validates_id_token_and_consumes_transaction_exactly_once(): void
    {
        $session = $this->newSessionStore();
        $startProvider = $this->provider($this->request('/auth/login', $session));
        $startProvider->redirectFor('/agenda');
        $transaction = $session->get(SecureKeycloakProvider::TRANSACTION_SESSION_KEY);
        $this->assertIsArray($transaction);
        $idToken = $this->idToken($transaction['nonce']);
        $history = [];
        $client = $this->guzzleClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => self::ACCESS_TOKEN,
                'id_token' => $idToken,
                'token_type' => 'Bearer',
                'expires_in' => 300,
                'scope' => 'openid profile email',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'sub' => 'staff-subject',
                'name' => 'Dra. Elena Torres',
                'email' => 'elena@example.test',
                'email_verified' => true,
            ], JSON_THROW_ON_ERROR)),
        ], $history);
        $callback = $this->request('/auth/callback?'.http_build_query([
            'state' => $transaction['state'],
            'code' => 'single-use-code',
        ]), $session);
        $provider = $this->provider($callback);
        $provider->setHttpClient($client);

        $user = $provider->user();

        $this->assertSame('staff-subject', $user->getId());
        $this->assertSame('staff-subject', $provider->validatedClaims()['sub']);
        $this->assertSame('/agenda', $provider->completedReturnPath());
        $this->assertFalse($session->has(SecureKeycloakProvider::TRANSACTION_SESSION_KEY));
        $this->assertCount(2, $history);
        parse_str((string) $history[0]['request']->getBody(), $tokenFields);
        $this->assertSame($transaction['code_verifier'], $tokenFields['code_verifier']);
        $this->assertSame('single-use-code', $tokenFields['code']);
        $this->assertSame('only-the-bff-knows-this-secret', $tokenFields['client_secret']);

        try {
            $provider->user();
            $this->fail('A replayed callback should not be accepted.');
        } catch (InvalidOidcTransactionException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_parallel_callbacks_consume_only_their_own_legacy_transaction_copy(): void
    {
        $session = $this->newSessionStore();

        // Two tabs start independent flows; the legacy key only holds the latest one.
        $this->provider($this->request('/auth/login', $session))->redirectFor('/agenda');
        $first = $session->get(SecureKeycloakProvider::TRANSACTION_SESSION_KEY);
        $this->assertIsArray($first);

        $this->provider($this->request('/auth/login', $session))->redirectFor('/portal');
        $second = $session->get(SecureKeycloakProvider::TRANSACTION_SESSION_KEY);
        $this->assertIsArray($second);
        $this->assertNotSame($first['state'], $second['state']);

        // 1) The OLDER tab completes first: its modern entry is consumed and the
        //    legacy copy (now the newer tab) must survive.
        $history = [];
        $provider = $this->provider($this->request('/auth/callback?'.http_build_query([
            'state' => $first['state'],
            'code' => 'first-tab-code',
        ]), $session));
        $provider->setHttpClient($this->guzzleClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => self::ACCESS_TOKEN,
                'id_token' => $this->idToken($first['nonce']),
                'token_type' => 'Bearer',
                'expires_in' => 300,
                'scope' => 'openid profile email',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'sub' => 'staff-subject',
                'name' => 'Dra. Elena Torres',
                'email' => 'elena@example.test',
                'email_verified' => true,
            ], JSON_THROW_ON_ERROR)),
        ], $history));
        $provider->user();

        $pending = $session->get(SecureKeycloakProvider::TRANSACTIONS_SESSION_KEY);
        $this->assertIsArray($pending);
        $this->assertArrayNotHasKey($first['state'], $pending);
        $this->assertArrayHasKey($second['state'], $pending);
        $this->assertSame($second['state'], $session->get(SecureKeycloakProvider::TRANSACTION_SESSION_KEY)['state']);

        // 2) The NEWER tab completes afterwards: the legacy copy matches its state
        //    and must be removed so a replayed callback cannot fall back to it.
        $history = [];
        $provider = $this->provider($this->request('/auth/callback?'.http_build_query([
            'state' => $second['state'],
            'code' => 'second-tab-code',
        ]), $session));
        $provider->setHttpClient($this->guzzleClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => self::ACCESS_TOKEN,
                'id_token' => $this->idToken($second['nonce']),
                'token_type' => 'Bearer',
                'expires_in' => 300,
                'scope' => 'openid profile email',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'sub' => 'staff-subject',
                'name' => 'Dra. Elena Torres',
                'email' => 'elena@example.test',
                'email_verified' => true,
            ], JSON_THROW_ON_ERROR)),
        ], $history));
        $provider->user();

        $this->assertFalse($session->has(SecureKeycloakProvider::TRANSACTION_SESSION_KEY));
        $this->assertFalse($session->has(SecureKeycloakProvider::TRANSACTIONS_SESSION_KEY));
    }

    public function test_invalid_state_is_consumed_before_any_upstream_request(): void
    {
        $session = $this->newSessionStore();
        $this->provider($this->request('/auth/login', $session))->redirectFor('/agenda');
        $history = [];
        $provider = $this->provider($this->request('/auth/callback?'.http_build_query([
            'state' => str_repeat('x', 43),
            'code' => 'malicious-code',
        ]), $session));
        $provider->setHttpClient($this->guzzleClient([], $history));

        try {
            $provider->user();
            $this->fail('An invalid state should not be accepted.');
        } catch (InvalidOidcTransactionException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse($session->has(SecureKeycloakProvider::TRANSACTION_SESSION_KEY));
        $this->assertSame([], $history);
    }

    public function test_standard_user_denial_is_handled_only_after_state_validation(): void
    {
        $session = $this->newSessionStore();
        $this->provider($this->request('/auth/login', $session))->redirectFor('/agenda');
        $transaction = $session->get(SecureKeycloakProvider::TRANSACTION_SESSION_KEY);
        $this->assertIsArray($transaction);
        $provider = $this->provider($this->request('/auth/callback?'.http_build_query([
            'state' => $transaction['state'],
            'error' => 'consent_required',
        ]), $session));

        $this->expectException(IdentityAccessDeniedException::class);

        $provider->user();
    }

    public function test_token_endpoint_outage_never_chains_a_request_containing_credentials(): void
    {
        $session = $this->newSessionStore();
        $this->provider($this->request('/auth/login', $session))->redirectFor('/agenda');
        $transaction = $session->get(SecureKeycloakProvider::TRANSACTION_SESSION_KEY);
        $this->assertIsArray($transaction);
        $provider = $this->provider($this->request('/auth/callback?'.http_build_query([
            'state' => $transaction['state'],
            'code' => 'single-use-code',
        ]), $session));
        $provider->setHttpClient(new Client(['handler' => new MockHandler([
            new ConnectException(
                'The token endpoint is unavailable.',
                new PsrRequest('POST', self::ISSUER.'/protocol/openid-connect/token'),
            ),
        ])]));

        try {
            $provider->user();
            $this->fail('An unavailable token endpoint should fail closed.');
        } catch (IdentityUnavailableException $exception) {
            $this->assertNull($exception->getPrevious());
        }
    }

    private function provider(Request $request): SecureKeycloakProvider
    {
        $discovery = new OidcDiscovery;
        $provider = new SecureKeycloakProvider(
            request: $request,
            clientId: 'nexasalud-bff',
            clientSecret: 'only-the-bff-knows-this-secret',
            redirectUrl: 'https://api.example.test/auth/callback',
            discovery: $discovery,
            idTokenValidator: new IdTokenValidator($discovery),
        );
        $provider->setScopes(['openid', 'profile', 'email']);

        return $provider;
    }

    private function newSessionStore(): Store
    {
        $session = new Store('test-session', new ArraySessionHandler(120), serialization: 'json');
        $session->start();

        return $session;
    }

    private function request(string $path, Store $session): Request
    {
        $request = Request::create('https://api.example.test'.$path, 'GET');
        $request->setLaravelSession($session);

        return $request;
    }

    /**
     * @param  list<Response|ConnectException>  $responses
     * @param  array<int, array<string, mixed>>  $history
     */
    private function guzzleClient(array $responses, array &$history): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack, 'allow_redirects' => false]);
    }

    private function idToken(string $nonce): string
    {
        $claims = [
            'iss' => self::ISSUER,
            'sub' => 'staff-subject',
            'aud' => 'nexasalud-bff',
            'exp' => time() + 300,
            'iat' => time() - 5,
            'auth_time' => time() - 10,
            'nonce' => $nonce,
            'acr' => '1',
            'amr' => ['pwd', 'otp'],
            'at_hash' => $this->accessTokenHash(self::ACCESS_TOKEN),
        ];

        return JWT::encode($claims, $this->privateKey, 'RS256', 'primary-key');
    }

    private function fakeIdentityEndpoints(): void
    {
        $metadata = [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'/protocol/openid-connect/auth',
            'token_endpoint' => self::ISSUER.'/protocol/openid-connect/token',
            'userinfo_endpoint' => self::ISSUER.'/protocol/openid-connect/userinfo',
            'jwks_uri' => self::ISSUER.'/protocol/openid-connect/certs',
            'end_session_endpoint' => self::ISSUER.'/protocol/openid-connect/logout',
        ];

        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => Http::response($metadata),
            $metadata['jwks_uri'] => Http::response(['keys' => [$this->jwk]]),
        ]);
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function rsaKeyPair(): array
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
                'kid' => 'primary-key',
                'use' => 'sig',
                'alg' => 'RS256',
                'key_ops' => ['verify'],
                'n' => $this->base64UrlEncode($details['rsa']['n']),
                'e' => $this->base64UrlEncode($details['rsa']['e']),
            ],
        ];
    }

    private function accessTokenHash(string $accessToken): string
    {
        $digest = hash('sha256', $accessToken, true);

        return $this->base64UrlEncode(substr($digest, 0, intdiv(strlen($digest), 2)));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
