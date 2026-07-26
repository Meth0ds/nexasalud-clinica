<?php

namespace App\Services\Identity;

use App\Exceptions\IdentityProtocolException;

final class OidcLogoutUrlBuilder
{
    public function __construct(
        private OidcDiscovery $discovery,
        private OidcAuthenticationService $authentication,
    ) {}

    public function build(): ?string
    {
        if (config('services.keycloak.upstream_logout') !== true) {
            return null;
        }

        $endSessionEndpoint = $this->discovery->metadata()->endSessionEndpoint;

        if ($endSessionEndpoint === null) {
            return null;
        }

        $clientId = config('services.keycloak.client_id');
        $postLogoutRedirect = config('services.keycloak.post_logout_redirect_uri');

        if (! is_string($clientId) || $clientId === '' || ! is_string($postLogoutRedirect)) {
            throw new IdentityProtocolException;
        }

        $frontendBase = $this->authentication->frontendUrl('/');
        $frontendOrigin = $this->origin($frontendBase);
        $redirectOrigin = $this->origin($postLogoutRedirect);

        if ($frontendOrigin !== $redirectOrigin) {
            throw new IdentityProtocolException;
        }

        return $endSessionEndpoint.'?'.http_build_query([
            'client_id' => $clientId,
            'post_logout_redirect_uri' => $postLogoutRedirect,
        ], encoding_type: PHP_QUERY_RFC3986);
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        if (
            $parts === false ||
            ! isset($parts['scheme'], $parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        ) {
            throw new IdentityProtocolException;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
