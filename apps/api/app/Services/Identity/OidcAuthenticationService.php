<?php

namespace App\Services\Identity;

use App\Exceptions\IdentityProtocolException;
use App\Models\User;
use App\Services\Identity\Contracts\OidcClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class OidcAuthenticationService
{
    public function __construct(
        private OidcClient $oidcClient,
        private OidcUserResolver $userResolver,
        private SafeReturnPath $safeReturnPath,
    ) {}

    public function redirect(Request $request, ?string $returnPath): RedirectResponse
    {
        return $this->oidcClient->redirect(
            $request,
            $this->safeReturnPath->normalize($returnPath),
        );
    }

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

    public function invalidate(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public function currentUser(Request $request): ?User
    {
        $user = $request->user('web');

        return $user instanceof User ? $user : null;
    }

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
     * @param  array<string, mixed>  $parts
     */
    private function isAllowedLocalFrontend(array $parts): bool
    {
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $localHost = $host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || str_ends_with($host, '.localhost');

        return app()->environment(['local', 'testing']) && $localHost;
    }

    private function absoluteLifetimeMinutes(): int
    {
        $minutes = config('session.absolute_lifetime');

        if (! is_int($minutes) || $minutes < 1 || $minutes > 1440) {
            throw new IdentityProtocolException;
        }

        return $minutes;
    }
}
