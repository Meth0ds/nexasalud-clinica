<?php

namespace App\Providers;

use App\Events\CommunicationQueued;
use App\Listeners\RecordQueuedCommunicationDispatch;
use App\Services\Identity\Contracts\OidcClient;
use App\Services\Identity\SocialiteOidcClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Registers application services, operational event listeners, rate limits, and production safety checks.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Registers container bindings that are safe to resolve before the application boots.
     */
    public function register(): void
    {
        $this->app->bind(OidcClient::class, SocialiteOidcClient::class);
    }

    /**
     * Registers rate limits, asynchronous domain listeners, and fail-closed production configuration checks.
     */
    public function boot(): void
    {
        RateLimiter::for('oidc-login', fn (Request $request): Limit => Limit::perMinute(10)
            ->by(hash('sha256', (string) $request->ip())));
        RateLimiter::for('oidc-callback', fn (Request $request): Limit => Limit::perMinute(20)
            ->by(hash('sha256', (string) $request->ip())));
        RateLimiter::for('auth-session', fn (Request $request): Limit => Limit::perMinute(120)
            ->by(hash('sha256', (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()))));
        RateLimiter::for('logout', fn (Request $request): Limit => Limit::perMinute(20)
            ->by(hash('sha256', (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()))));
        RateLimiter::for('clinical-api', fn (Request $request): Limit => Limit::perMinute(300)
            ->by(hash('sha256', (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()))));
        RateLimiter::for('clinical-mutation', fn (Request $request): Limit => Limit::perMinute(120)
            ->by(hash('sha256', (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()))));
        RateLimiter::for('clinical-pdf', fn (Request $request): Limit => Limit::perMinute(30)
            ->by(hash('sha256', (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()))));

        Event::listen(CommunicationQueued::class, RecordQueuedCommunicationDispatch::class);

        $this->assertProductionIdentityConfiguration();
    }

    /**
     * Rejects insecure identity, session, CORS, and debugging configuration when the application is in production.
     */
    private function assertProductionIdentityConfiguration(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        $issuer = config('services.keycloak.issuer');
        $frontendUrl = config('services.keycloak.frontend_url');
        $redirectUrl = config('services.keycloak.redirect');
        $postLogoutRedirectUrl = config('services.keycloak.post_logout_redirect_uri');
        $applicationUrl = config('app.url');
        $requiredAcrValues = config('services.keycloak.required_acr_values');
        $requiredAmrValues = config('services.keycloak.required_amr_values');
        $allowedAlgorithms = config('services.keycloak.allowed_algorithms');
        $allowedOrigins = config('cors.allowed_origins');
        $trustedHosts = config('app.trusted_hosts');
        $trustedProxies = config('app.trusted_proxies');
        $clinicalFilesystem = config('filesystems.disks.clinical');
        $postgresConnection = config('database.connections.pgsql');
        $redisConnection = config('database.redis.default');
        $absoluteLifetime = config('session.absolute_lifetime');
        $idleLifetime = config('session.lifetime');

        $valid = $this->isSecureUrl($issuer)
            && $this->isExactSecureOrigin($frontendUrl)
            && $this->isSecureUrl($redirectUrl)
            && $this->path($redirectUrl) === '/auth/callback'
            && $this->isSecureUrl($postLogoutRedirectUrl)
            && $this->isExactSecureOrigin($applicationUrl)
            && $this->origin($redirectUrl) === $this->origin($applicationUrl)
            && $this->origin($postLogoutRedirectUrl) === $this->origin($frontendUrl)
            && is_string(config('services.keycloak.client_id')) && config('services.keycloak.client_id') !== ''
            && is_string(config('services.keycloak.client_secret')) && config('services.keycloak.client_secret') !== ''
            && config('services.keycloak.allow_insecure_http') === false
            && config('services.keycloak.upstream_logout') === true
            && is_array($requiredAcrValues) && $requiredAcrValues !== []
            && count(array_filter($requiredAcrValues, $this->isStrongAcrValue(...))) === count($requiredAcrValues)
            && is_array($requiredAmrValues) && $requiredAmrValues !== []
            && array_intersect($requiredAmrValues, ['fido', 'hwk', 'passkey', 'webauthn']) !== []
            && is_array($allowedAlgorithms) && $allowedAlgorithms !== []
            && array_diff($allowedAlgorithms, ['RS256', 'RS384', 'RS512', 'PS256', 'ES256', 'ES384']) === []
            && config('session.driver') === 'redis'
            && config('session.encrypt') === true
            && is_int($absoluteLifetime)
            && is_int($idleLifetime)
            && $idleLifetime >= 1
            && $absoluteLifetime >= $idleLifetime
            && $absoluteLifetime <= 1440
            && config('cache.default') === 'redis'
            && is_array($postgresConnection)
            && ($postgresConnection['sslmode'] ?? null) === 'verify-full'
            && is_string($postgresConnection['sslrootcert'] ?? null)
            && $postgresConnection['sslrootcert'] !== ''
            && is_array($redisConnection)
            && ($redisConnection['scheme'] ?? null) === 'tls'
            && is_array($clinicalFilesystem)
            && ($clinicalFilesystem['driver'] ?? null) === 's3'
            && is_string($clinicalFilesystem['bucket'] ?? null)
            && $clinicalFilesystem['bucket'] !== ''
            && config('session.cookie') === '__Host-nexasalud_session'
            && config('session.secure') === true
            && config('session.http_only') === true
            && config('session.same_site') === 'lax'
            && config('session.path') === '/'
            && config('session.domain') === null
            && config('cors.supports_credentials') === true
            && is_array($allowedOrigins) && $allowedOrigins !== []
            && ! in_array('*', $allowedOrigins, true)
            && count(array_filter($allowedOrigins, $this->isExactSecureOrigin(...))) === count($allowedOrigins)
            && in_array($this->origin($frontendUrl), $allowedOrigins, true)
            && config('cors.allowed_origins_patterns') === []
            && is_array($trustedHosts) && $trustedHosts !== []
            && ! in_array('*', $trustedHosts, true)
            && is_array($trustedProxies) && $trustedProxies !== []
            && ! in_array('*', $trustedProxies, true)
            && config('app.debug') === false;

        if (! $valid) {
            throw new RuntimeException('The production identity or session configuration is not secure.');
        }
    }

    /**
     * Verifies that a URL is an absolute HTTPS URL without credentials, query data, or control characters.
     */
    private function isSecureUrl(mixed $url): bool
    {
        if (! is_string($url) || $url === '' || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        return $parts !== false
            && ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }

    /**
     * Verifies that a URL exactly represents an HTTPS origin without a path.
     */
    private function isExactSecureOrigin(mixed $url): bool
    {
        return $this->isSecureUrl($url)
            && is_string($url)
            && $this->origin($url) === $url;
    }

    /**
     * Normalizes a valid absolute URL to its origin for exact configuration comparisons.
     */
    private function origin(mixed $url): string
    {
        if (! is_string($url)) {
            return '';
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * Returns the URL path used to validate exact callback routes.
     */
    private function path(mixed $url): string
    {
        if (! is_string($url)) {
            return '';
        }

        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }

    /**
     * Accepts only numeric assurance values that satisfy the required multi-factor strength.
     */
    private function isStrongAcrValue(mixed $value): bool
    {
        return is_string($value) && ctype_digit($value) && (int) $value >= 2;
    }
}
