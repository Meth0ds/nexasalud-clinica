<?php

namespace App\Services\Identity;

use App\Exceptions\IdentityProtocolException;
use App\Exceptions\IdentityUnavailableException;
use App\Services\Identity\Data\OidcMetadata;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Retrieves and validates discovery metadata and signing keys from the configured OIDC issuer.
 */
final class OidcDiscovery
{
    /**
     * Return trusted issuer metadata, optionally refreshing the cached document first.
     */
    public function metadata(bool $fresh = false): OidcMetadata
    {
        $issuer = $this->issuer();
        $cacheKey = 'oidc:discovery:'.hash('sha256', $issuer);

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        /** @var array<string, mixed> $metadata */
        $metadata = Cache::remember(
            $cacheKey,
            $this->positiveIntegerConfig('discovery_cache_seconds'),
            fn (): array => $this->fetchMetadata($issuer),
        );

        return $this->toMetadata($metadata, $issuer);
    }

    /**
     * Return the trusted JSON Web Key Set, optionally refreshing its cache first.
     *
     * @return array{keys: list<array<string, mixed>>}
     */
    public function jwks(bool $fresh = false): array
    {
        $metadata = $this->metadata($fresh);
        $cacheKey = 'oidc:jwks:'.hash('sha256', $metadata->jwksUri);

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        /** @var array{keys: list<array<string, mixed>>} $jwks */
        $jwks = Cache::remember(
            $cacheKey,
            $this->positiveIntegerConfig('jwks_cache_seconds'),
            fn (): array => $this->fetchJwks($metadata->jwksUri),
        );

        return $jwks;
    }

    /**
     * Return the configured issuer after enforcing its trusted URL shape.
     */
    public function issuer(): string
    {
        $issuer = config('services.keycloak.issuer');

        if (! is_string($issuer) || $issuer === '') {
            throw new IdentityUnavailableException;
        }

        $issuer = rtrim($issuer, '/');
        $parts = parse_url($issuer);

        if (
            $parts === false ||
            ! isset($parts['scheme'], $parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['query']) || isset($parts['fragment'])
        ) {
            throw new IdentityProtocolException;
        }

        if ($parts['scheme'] !== 'https' && ! $this->isAllowedLocalHttpIssuer($parts)) {
            throw new IdentityProtocolException;
        }

        return $issuer;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchMetadata(string $issuer): array
    {
        $response = $this->request($issuer.'/.well-known/openid-configuration');

        if (strlen($response->body()) > 262144) {
            throw new IdentityProtocolException;
        }

        $metadata = $response->json();

        if (! is_array($metadata)) {
            throw new IdentityProtocolException;
        }

        return $metadata;
    }

    /**
     * @return array{keys: list<array<string, mixed>>}
     */
    private function fetchJwks(string $jwksUri): array
    {
        $response = $this->request($jwksUri);

        if (strlen($response->body()) > 1048576) {
            throw new IdentityProtocolException;
        }

        $jwks = $response->json();

        if (! is_array($jwks) || ! isset($jwks['keys']) || ! is_array($jwks['keys'])) {
            throw new IdentityProtocolException;
        }

        $keys = array_values(array_filter($jwks['keys'], 'is_array'));

        if ($keys === [] || count($keys) > 20 || count($keys) !== count($jwks['keys'])) {
            throw new IdentityProtocolException;
        }

        return ['keys' => $keys];
    }

    /**
     * Fetch a trusted identity endpoint without following redirects.
     */
    private function request(string $url): Response
    {
        try {
            return Http::acceptJson()
                ->withOptions($this->httpOptions($url))
                ->connectTimeout($this->positiveIntegerConfig('connect_timeout_seconds'))
                ->timeout($this->positiveIntegerConfig('timeout_seconds'))
                ->retry([100, 250], throw: false)
                ->get($url)
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw new IdentityUnavailableException($exception);
        }
    }

    /**
     * Return bounded HTTP options, pinning local *.localhost hosts to the
     * container-resolved address because PHP's cURL resolver treats them as
     * loopback on Docker Desktop. Production continues using standard DNS
     * resolution and TLS verification.
     *
     * @return array<string, mixed>
     */
    private function httpOptions(string $url): array
    {
        return array_replace([
            'allow_redirects' => false,
            'verify' => true,
        ], LocalhostCurlResolver::optionsFor($url));
    }

    /**
     * Convert validated discovery fields into immutable issuer metadata.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function toMetadata(array $metadata, string $issuer): OidcMetadata
    {
        if (($metadata['issuer'] ?? null) !== $issuer) {
            throw new IdentityProtocolException;
        }

        $authorizationEndpoint = $this->requiredEndpoint($metadata, 'authorization_endpoint', $issuer);
        $tokenEndpoint = $this->requiredEndpoint($metadata, 'token_endpoint', $issuer);
        $userInfoEndpoint = $this->requiredEndpoint($metadata, 'userinfo_endpoint', $issuer);
        $jwksUri = $this->requiredEndpoint($metadata, 'jwks_uri', $issuer);
        $endSessionEndpoint = $this->optionalEndpoint($metadata, 'end_session_endpoint', $issuer);

        return new OidcMetadata(
            issuer: $issuer,
            authorizationEndpoint: $authorizationEndpoint,
            tokenEndpoint: $tokenEndpoint,
            userInfoEndpoint: $userInfoEndpoint,
            jwksUri: $jwksUri,
            endSessionEndpoint: $endSessionEndpoint,
        );
    }

    /**
     * Return a required discovery endpoint after pinning it to the configured issuer.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function requiredEndpoint(array $metadata, string $key, string $issuer): string
    {
        $endpoint = $metadata[$key] ?? null;

        if (! is_string($endpoint) || $endpoint === '') {
            throw new IdentityProtocolException;
        }

        $this->assertTrustedEndpoint($endpoint, $issuer);

        return $endpoint;
    }

    /**
     * Return an optional discovery endpoint after pinning it to the configured issuer.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function optionalEndpoint(array $metadata, string $key, string $issuer): ?string
    {
        $endpoint = $metadata[$key] ?? null;

        if ($endpoint === null) {
            return null;
        }

        if (! is_string($endpoint) || $endpoint === '') {
            throw new IdentityProtocolException;
        }

        $this->assertTrustedEndpoint($endpoint, $issuer);

        return $endpoint;
    }

    /**
     * Ensure a discovery endpoint remains within the issuer origin and path boundary.
     */
    private function assertTrustedEndpoint(string $endpoint, string $issuer): void
    {
        $endpointParts = parse_url($endpoint);
        $issuerParts = parse_url($issuer);

        if ($endpointParts === false || $issuerParts === false) {
            throw new IdentityProtocolException;
        }

        foreach (['scheme', 'host'] as $part) {
            if (($endpointParts[$part] ?? null) !== ($issuerParts[$part] ?? null)) {
                throw new IdentityProtocolException;
            }
        }

        if (($endpointParts['port'] ?? null) !== ($issuerParts['port'] ?? null)) {
            throw new IdentityProtocolException;
        }

        if (
            isset($endpointParts['user']) || isset($endpointParts['pass']) ||
            isset($endpointParts['query']) || isset($endpointParts['fragment'])
        ) {
            throw new IdentityProtocolException;
        }

        $issuerPath = rtrim((string) ($issuerParts['path'] ?? ''), '/');
        $endpointPath = (string) ($endpointParts['path'] ?? '');

        if ($issuerPath !== '' && ! str_starts_with($endpointPath, $issuerPath.'/')) {
            throw new IdentityProtocolException;
        }
    }

    /**
     * Allow an HTTP issuer only for explicit local development or test environments.
     *
     * @param  array<string, mixed>  $parts
     */
    private function isAllowedLocalHttpIssuer(array $parts): bool
    {
        $allowInsecureHttp = config('services.keycloak.allow_insecure_http') === true;
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $localHost = $host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || str_ends_with($host, '.localhost');

        return $allowInsecureHttp && app()->environment(['local', 'testing']) && $localHost;
    }

    /**
     * Read a bounded positive timeout or cache-duration setting from identity configuration.
     */
    private function positiveIntegerConfig(string $key): int
    {
        $value = config('services.keycloak.'.$key);
        $maximum = match ($key) {
            'connect_timeout_seconds' => 5,
            'timeout_seconds' => 10,
            'discovery_cache_seconds', 'jwks_cache_seconds' => 86400,
            default => 86400,
        };

        if (! is_int($value) || $value < 1 || $value > $maximum) {
            throw new IdentityUnavailableException;
        }

        return $value;
    }
}
