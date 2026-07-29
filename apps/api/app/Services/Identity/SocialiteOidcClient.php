<?php

namespace App\Services\Identity;

use App\Exceptions\IdentityProtocolException;
use App\Services\Identity\Contracts\OidcClient;
use App\Services\Identity\Data\OidcIdentity;
use App\Services\Identity\Data\OidcLoginResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Adapts the hardened Keycloak provider to the application's OIDC client contract.
 */
final class SocialiteOidcClient implements OidcClient
{
    /**
     * Create the client with trusted discovery and ID token validation services.
     */
    public function __construct(
        private OidcDiscovery $discovery,
        private IdTokenValidator $idTokenValidator,
    ) {}

    /**
     * Start a provider redirect for an already normalized local return path.
     */
    public function redirect(Request $request, string $returnPath): RedirectResponse
    {
        return $this->provider($request)->redirectFor($returnPath);
    }

    /**
     * Complete the OIDC callback and map verified claims to a token-free login result.
     */
    public function complete(Request $request): OidcLoginResult
    {
        $provider = $this->provider($request);
        $socialiteUser = $provider->user();
        $claims = $provider->validatedClaims();
        $rawUser = $socialiteUser->getRaw();
        $issuer = $this->requiredString($claims, 'iss');
        $subject = $this->requiredString($claims, 'sub');
        $displayName = $this->displayName($rawUser, $claims);
        $email = $this->verifiedEmail($rawUser, $claims);
        $authenticationContext = is_string($claims['acr'] ?? null) ? $claims['acr'] : null;
        $authenticationMethods = $this->authenticationMethods($claims['amr'] ?? []);

        unset($socialiteUser->token, $socialiteUser->refreshToken);

        return new OidcLoginResult(
            identity: new OidcIdentity(
                issuer: $issuer,
                subject: $subject,
                displayName: $displayName,
                email: $email,
                authenticationContext: $authenticationContext,
                authenticationMethods: $authenticationMethods,
                authenticatedAt: $this->authenticatedAt($claims),
            ),
            returnPath: $provider->completedReturnPath(),
        );
    }

    /**
     * Create a hardened Keycloak provider from validated identity configuration.
     */
    private function provider(Request $request): SecureKeycloakProvider
    {
        $clientId = $this->configString('client_id');
        $clientSecret = $this->configString('client_secret');
        $redirect = $this->configString('redirect');
        $scopes = config('services.keycloak.scopes');

        if (! is_array($scopes) || $scopes === [] || ! in_array('openid', $scopes, true)) {
            throw new IdentityProtocolException;
        }

        $provider = new SecureKeycloakProvider(
            request: $request,
            clientId: $clientId,
            clientSecret: $clientSecret,
            redirectUrl: $redirect,
            discovery: $this->discovery,
            idTokenValidator: $this->idTokenValidator,
            guzzle: [
                'allow_redirects' => false,
                'connect_timeout' => $this->configInteger('connect_timeout_seconds'),
                'http_errors' => true,
                'timeout' => $this->configInteger('timeout_seconds'),
                'verify' => true,
            ],
        );

        $provider->setScopes($scopes);

        return $provider;
    }

    /**
     * Select a bounded display name from trusted profile information.
     *
     * @param  array<string, mixed>  $rawUser
     * @param  array<string, mixed>  $claims
     */
    private function displayName(array $rawUser, array $claims): string
    {
        foreach ([$rawUser['name'] ?? null, $claims['name'] ?? null, $rawUser['preferred_username'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return Str::of($candidate)->squish()->limit(120, '')->toString();
            }
        }

        return 'Usuario NexaSalud';
    }

    /**
     * Return a normalized email address only when the issuer has verified it.
     *
     * @param  array<string, mixed>  $rawUser
     * @param  array<string, mixed>  $claims
     */
    private function verifiedEmail(array $rawUser, array $claims): ?string
    {
        $verified = ($rawUser['email_verified'] ?? $claims['email_verified'] ?? false) === true;
        $email = $rawUser['email'] ?? $claims['email'] ?? null;

        if (! $verified || ! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return mb_strtolower($email);
    }

    /**
     * Normalize the bounded list of authentication methods supplied by the issuer.
     *
     * @return list<string>
     */
    private function authenticationMethods(mixed $methods): array
    {
        if (! is_array($methods)) {
            return [];
        }

        $methods = array_filter(
            $methods,
            static fn (mixed $method): bool => is_string($method) && $method !== '' && mb_strlen($method) <= 64,
        );

        return array_slice(array_values(array_unique($methods)), 0, 20);
    }

    /**
     * Return the authenticated-at claim after rejecting invalid or implausible values.
     *
     * @param  array<string, mixed>  $claims
     */
    private function authenticatedAt(array $claims): int
    {
        $authenticatedAt = $claims['auth_time'] ?? null;

        if (! is_int($authenticatedAt) || $authenticatedAt < 1 || $authenticatedAt > time() + 300) {
            throw new IdentityProtocolException;
        }

        return $authenticatedAt;
    }

    /**
     * Read a required non-empty string claim from a provider response.
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
     * Read a required non-empty string from Keycloak configuration.
     */
    private function configString(string $key): string
    {
        $value = config('services.keycloak.'.$key);

        if (! is_string($value) || $value === '') {
            throw new IdentityProtocolException;
        }

        return $value;
    }

    /**
     * Read a required positive integer from Keycloak configuration.
     */
    private function configInteger(string $key): int
    {
        $value = config('services.keycloak.'.$key);

        if (! is_int($value) || $value < 1) {
            throw new IdentityProtocolException;
        }

        return $value;
    }
}
