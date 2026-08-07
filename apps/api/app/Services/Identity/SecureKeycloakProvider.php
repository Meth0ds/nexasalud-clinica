<?php

namespace App\Services\Identity;

use App\Exceptions\IdentityAccessDeniedException;
use App\Exceptions\IdentityProtocolException;
use App\Exceptions\IdentityUnavailableException;
use App\Exceptions\InvalidOidcTransactionException;
use App\Services\Identity\Data\OidcTransaction;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use JsonException;
use SocialiteProviders\Keycloak\Provider;
use SocialiteProviders\Manager\OAuth2\User as ManagerUser;

/**
 * Implements a stateful Keycloak authorization-code flow with PKCE and verified identity data.
 */
final class SecureKeycloakProvider extends Provider
{
    public const TRANSACTION_SESSION_KEY = 'auth.oidc.transaction';

    public const TRANSACTIONS_SESSION_KEY = 'auth.oidc.transactions';

    private const MAX_PENDING_TRANSACTIONS = 5;

    private ?OidcTransaction $activeTransaction = null;

    /** @var array<string, mixed>|null */
    private ?array $validatedClaims = null;

    private ?string $completedReturnPath = null;

    /**
     * Create the provider with hardened discovery, token validation and HTTP settings.
     *
     * @param  array<string, mixed>  $guzzle
     */
    public function __construct(
        Request $request,
        string $clientId,
        string $clientSecret,
        string $redirectUrl,
        private OidcDiscovery $discovery,
        private IdTokenValidator $idTokenValidator,
        array $guzzle = [],
    ) {
        parent::__construct($request, $clientId, $clientSecret, $redirectUrl, $guzzle);
        $this->enablePKCE();
    }

    /**
     * Create an authorization redirect and persist its one-time state and PKCE transaction.
     */
    public function redirectFor(string $returnPath): RedirectResponse
    {
        $requiredAcrValues = config('services.keycloak.required_acr_values', []);

        if (! is_array($requiredAcrValues)) {
            throw new IdentityProtocolException;
        }

        foreach ($requiredAcrValues as $acrValue) {
            if (
                ! is_string($acrValue) ||
                $acrValue === '' ||
                mb_strlen($acrValue) > 256 ||
                preg_match('/\s/u', $acrValue) !== 0
            ) {
                throw new IdentityProtocolException;
            }
        }

        $transaction = OidcTransaction::issue($returnPath);
        $transactions = $this->request->session()->get(self::TRANSACTIONS_SESSION_KEY, []);
        $transactions = is_array($transactions) ? $transactions : [];
        $transactions[$transaction->state] = $transaction->toSession();
        if (count($transactions) > self::MAX_PENDING_TRANSACTIONS) {
            $transactions = array_slice($transactions, -self::MAX_PENDING_TRANSACTIONS, null, true);
        }
        $this->request->session()->put(self::TRANSACTIONS_SESSION_KEY, $transactions);
        // Keep the legacy key for sessions created by older releases. New callbacks
        // consume the state-indexed collection first, so parallel tabs do not overwrite
        // each other's nonce and PKCE verifier.
        $this->request->session()->put(self::TRANSACTION_SESSION_KEY, $transaction->toSession());
        $this->activeTransaction = $transaction;
        $this->parameters = ['nonce' => $transaction->nonce];

        if ($requiredAcrValues !== []) {
            $this->parameters['acr_values'] = implode(' ', $requiredAcrValues);
            try {
                $this->parameters['claims'] = json_encode([
                    'id_token' => [
                        'acr' => [
                            'essential' => true,
                            'values' => $requiredAcrValues,
                        ],
                    ],
                ], JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new IdentityProtocolException($exception);
            }
        }

        try {
            return new RedirectResponse($this->getAuthUrl($transaction->state));
        } catch (\Throwable $exception) {
            $this->forgetPendingTransaction($transaction->state);

            throw $exception;
        } finally {
            $this->activeTransaction = null;
            $this->parameters = [];
        }
    }

    /**
     * Consume and validate the callback transaction before retrieving the verified provider user.
     */
    public function user(): ManagerUser
    {
        $state = $this->request->query('state');
        $transactionValue = null;

        if (is_string($state) && $state !== '') {
            $transactions = $this->request->session()->get(self::TRANSACTIONS_SESSION_KEY, []);
            if (is_array($transactions) && array_key_exists($state, $transactions)) {
                $transactionValue = $transactions[$state];
                unset($transactions[$state]);
                if ($transactions === []) {
                    $this->request->session()->forget(self::TRANSACTIONS_SESSION_KEY);
                } else {
                    $this->request->session()->put(self::TRANSACTIONS_SESSION_KEY, $transactions);
                }
            }
        }

        if ($transactionValue !== null) {
            $transaction = OidcTransaction::fromSession($transactionValue);
            $this->forgetMatchingLegacyTransaction((string) $state);
        } else {
            // Consume the legacy compatibility transaction only when no state
            // matched, so unrelated callback requests cannot destroy it while a
            // parallel login flow is still pending.
            $legacyTransaction = $this->request->session()->pull(self::TRANSACTION_SESSION_KEY);
            $transaction = OidcTransaction::fromSession($legacyTransaction);
        }

        $lifetime = config('services.keycloak.transaction_ttl_seconds');

        if (! is_int($lifetime) || $lifetime < 30 || $lifetime > 900 || $transaction->isExpired(time(), $lifetime)) {
            throw new InvalidOidcTransactionException;
        }

        if (! is_string($state) || ! hash_equals($transaction->state, $state)) {
            throw new InvalidOidcTransactionException;
        }

        $error = $this->request->query('error');

        if ($error !== null) {
            throw match ($error) {
                'access_denied',
                'account_selection_required',
                'consent_required',
                'interaction_required',
                'login_required' => new IdentityAccessDeniedException,
                'server_error',
                'temporarily_unavailable' => new IdentityUnavailableException,
                default => new IdentityProtocolException,
            };
        }

        $code = $this->request->query('code');

        if (! is_string($code) || $code === '') {
            throw new InvalidOidcTransactionException;
        }

        $this->activeTransaction = $transaction;

        try {
            $tokenResponse = $this->getAccessTokenResponse($code);
            $accessToken = $this->requiredToken($tokenResponse, 'access_token');
            $idToken = $this->requiredToken($tokenResponse, 'id_token');
            $claims = $this->idTokenValidator->validate($idToken, $transaction->nonce, $accessToken);
            $rawUser = $this->getUserByToken($accessToken);

            if (($rawUser['sub'] ?? null) !== ($claims['sub'] ?? null)) {
                throw new IdentityProtocolException;
            }

            $this->validatedClaims = $claims;
            $this->completedReturnPath = $transaction->returnPath;
            $user = $this->mapUserToObject($rawUser);

            if (! $user instanceof ManagerUser) {
                throw new IdentityProtocolException;
            }

            return $user
                ->setToken($accessToken)
                ->setExpiresIn($tokenResponse['expires_in'] ?? null)
                ->setApprovedScopes($this->parseScopes($tokenResponse['scope'] ?? ''));
        } finally {
            $this->activeTransaction = null;
        }
    }

    /**
     * Return the claims validated during the completed callback.
     *
     * @return array<string, mixed>
     */
    public function validatedClaims(): array
    {
        if ($this->validatedClaims === null) {
            throw new IdentityProtocolException;
        }

        return $this->validatedClaims;
    }

    /**
     * Return the local path bound to the completed OIDC transaction.
     */
    public function completedReturnPath(): string
    {
        if ($this->completedReturnPath === null) {
            throw new IdentityProtocolException;
        }

        return $this->completedReturnPath;
    }

    /**
     * Build the authorization URL from trusted discovery metadata.
     */
    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->discovery->metadata()->authorizationEndpoint, $state);
    }

    /**
     * Return the token endpoint pinned to the trusted issuer.
     */
    protected function getTokenUrl(): string
    {
        return $this->discovery->metadata()->tokenEndpoint;
    }

    /**
     * Fetch a bounded user-info response using the server-side access token.
     *
     * @return array<string, mixed>
     */
    protected function getUserByToken($token): array
    {
        try {
            $userInfoEndpoint = $this->discovery->metadata()->userInfoEndpoint;
            $response = $this->getHttpClient()->get($userInfoEndpoint, [
                RequestOptions::HEADERS => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$token,
                ],
                ...LocalhostCurlResolver::optionsFor($userInfoEndpoint),
            ]);

            $body = (string) $response->getBody();

            if (strlen($body) > 131072) {
                throw new IdentityProtocolException;
            }

            $user = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (ClientException $exception) {
            throw new IdentityProtocolException($exception);
        } catch (JsonException $exception) {
            throw new IdentityProtocolException($exception);
        } catch (GuzzleException) {
            throw new IdentityUnavailableException;
        }

        if (! is_array($user) || ! is_string($user['sub'] ?? null) || $user['sub'] === '') {
            throw new IdentityProtocolException;
        }

        return $user;
    }

    /**
     * Exchange an authorization code for a bounded bearer token response.
     *
     * @return array<string, mixed>
     */
    public function getAccessTokenResponse($code): array
    {
        try {
            $tokenUrl = $this->getTokenUrl();
            $response = $this->getHttpClient()->post($tokenUrl, [
                RequestOptions::HEADERS => ['Accept' => 'application/json'],
                RequestOptions::FORM_PARAMS => $this->getTokenFields($code),
                ...LocalhostCurlResolver::optionsFor($tokenUrl),
            ]);

            $body = (string) $response->getBody();

            if (strlen($body) > 262144) {
                throw new IdentityProtocolException;
            }

            $tokens = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (ClientException $exception) {
            throw new IdentityProtocolException($exception);
        } catch (JsonException $exception) {
            throw new IdentityProtocolException($exception);
        } catch (GuzzleException) {
            throw new IdentityUnavailableException;
        }

        if (! is_array($tokens)) {
            throw new IdentityProtocolException;
        }

        $tokenType = $tokens['token_type'] ?? null;

        if (! is_string($tokenType) || mb_strtolower($tokenType) !== 'bearer') {
            throw new IdentityProtocolException;
        }

        return $tokens;
    }

    /**
     * Build the authorization-code exchange fields, including the active PKCE verifier.
     *
     * @return array<string, string>
     */
    protected function getTokenFields($code): array
    {
        if ($this->activeTransaction === null || ! is_string($code) || $code === '') {
            throw new InvalidOidcTransactionException;
        }

        return [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUrl,
            'code_verifier' => $this->activeTransaction->codeVerifier,
        ];
    }

    /**
     * Derive the RFC 7636 S256 challenge from the active transaction verifier.
     */
    protected function getCodeChallenge(): string
    {
        if ($this->activeTransaction === null) {
            throw new InvalidOidcTransactionException;
        }

        $hashed = hash('sha256', $this->activeTransaction->codeVerifier, true);

        return rtrim(strtr(base64_encode($hashed), '+/', '-_'), '=');
    }

    /**
     * Read a required bounded token string from the token endpoint response.
     *
     * @param  array<string, mixed>  $tokens
     */
    private function requiredToken(array $tokens, string $key): string
    {
        $token = $tokens[$key] ?? null;

        $maximumLength = $key === 'id_token' ? 32768 : 16384;

        if (! is_string($token) || $token === '' || strlen($token) > $maximumLength) {
            throw new IdentityProtocolException;
        }

        return $token;
    }

    /**
     * Convert an optional space-delimited OAuth scope string to a list.
     *
     * @return list<string>
     */
    private function parseScopes(mixed $scopes): array
    {
        if (! is_string($scopes)) {
            return [];
        }

        return array_values(array_filter(explode(' ', $scopes)));
    }

    /**
     * Remove the legacy compatibility copy when it belongs to the just-consumed
     * transaction, so a replayed callback cannot fall back to a stale copy.
     * A legacy copy from a different parallel flow is preserved.
     */
    private function forgetMatchingLegacyTransaction(string $state): void
    {
        $legacy = $this->request->session()->get(self::TRANSACTION_SESSION_KEY);

        if (is_array($legacy) && ($legacy['state'] ?? null) === $state) {
            $this->request->session()->forget(self::TRANSACTION_SESSION_KEY);
        }
    }

    /**
     * Remove one pending transaction and the legacy compatibility value.
     */
    private function forgetPendingTransaction(string $state): void
    {
        $transactions = $this->request->session()->get(self::TRANSACTIONS_SESSION_KEY, []);
        if (is_array($transactions)) {
            unset($transactions[$state]);
            if ($transactions === []) {
                $this->request->session()->forget(self::TRANSACTIONS_SESSION_KEY);
            } else {
                $this->request->session()->put(self::TRANSACTIONS_SESSION_KEY, $transactions);
            }
        }
        $this->request->session()->forget(self::TRANSACTION_SESSION_KEY);
    }
}
