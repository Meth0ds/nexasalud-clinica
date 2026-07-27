<?php

namespace App\Services\Identity;

use App\Exceptions\IdentityProtocolException;
use App\Models\User;
use App\Services\Identity\Contracts\OidcClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Coordinates the OIDC BFF session lifecycle without exposing upstream tokens to the frontend.
 */
final class OidcAuthenticationService
{
    /**
     * Creates the service with the OIDC client, identity resolver, and safe return-path policy.
     */
    public function __construct(
        private OidcClient $oidcClient,
        private OidcUserResolver $userResolver,
        private SafeReturnPath $safeReturnPath,
    ) {}

    /**
     * Starts an OIDC authorization redirect after normalizing the requested local return path.
     */
    public function redirect(Request $request, ?string $returnPath): RedirectResponse
    {
        return $this->oidcClient->redirect(
            $request,
            $this->safeReturnPath->normalize($returnPath),
        );
    }

    /**
     * Completes a validated OIDC transaction and rotates the local session before redirecting to the frontend.
     */
    public function complete(Request $request): string
    {
        $absoluteExpiresAt = time() + ($this->absoluteLifetimeMinutes() * 60);
        $result = $this->oidcClient->complete($request);
        $user = $this->userResolver->resolve($result->identity);

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::guard('web')->login($user, remember: false);
        $request->session()->put([
            'auth.assurance.acr' => $result->identity->authenticationContext,
            'auth.assurance.amr' => $result->identity->authenticationMethods,
            'auth.assurance.authenticated_at' => $result->identity->authenticatedAt,
            'auth.session.absolute_expires_at' => $absoluteExpiresAt,
        ]);

        return $this->frontendUrl($this->safeReturnPath->normalize($result->returnPath));
    }

    /**
     * Ends the local session and rotates its CSRF token.
     */
    public function invalidate(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * Returns the authenticated web user when the current session is valid.
     */
    public function currentUser(Request $request): ?User
    {
        $user = $request->user('web');

        return $user instanceof User ? $user : null;
    }

    /**
     * Builds a frontend redirect only from a trusted configured origin and normalized local path.
     */
    public function frontendUrl(string $path): string
    {
        $baseUrl = config('services.keycloak.frontend_url');

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new IdentityProtocolException;
        }

        $baseUrl = rtrim($baseUrl, '/');
        $parts = parse_url($baseUrl);

        if (
            $parts === false ||
            ! isset($parts['scheme'], $parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['query']) || isset($parts['fragment'])
        ) {
            throw new IdentityProtocolException;
        }

        if ($parts['scheme'] !== 'https' && ! $this->isAllowedLocalFrontend($parts)) {
            throw new IdentityProtocolException;
        }

        return $baseUrl.$path;
    }

    /**
     * Allows plain HTTP frontend URLs only for explicitly local development and testing hosts.
     *
     * @param  array<string, mixed>  $parts
     */
    private function isAllowedLocalFrontend(array $parts): bool
    {
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $localHost = $host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || str_ends_with($host, '.localhost');

        return app()->environment(['local', 'testing']) && $localHost;
    }

    /**
     * Returns a bounded absolute session lifetime or rejects invalid security configuration.
     */
    private function absoluteLifetimeMinutes(): int
    {
        $minutes = config('session.absolute_lifetime');

        if (! is_int($minutes) || $minutes < 1 || $minutes > 1440) {
            throw new IdentityProtocolException;
        }

        return $minutes;
    }
}
