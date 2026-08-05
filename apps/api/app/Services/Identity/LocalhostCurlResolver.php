<?php

namespace App\Services\Identity;

/**
 * Pins local *.localhost hosts to the container-resolved address for cURL
 * clients. PHP's cURL resolver treats *.localhost as loopback on Docker
 * Desktop, unlike the resolver used by the rest of the local stack, so
 * connections to a *.localhost identity host would target 127.0.0.1 and fail.
 */
final class LocalhostCurlResolver
{
    /**
     * Return cURL options pinning a local *.localhost host to the address
     * resolved by the container, or an empty array when the environment or
     * URL shape does not require the pin.
     *
     * @return array{curl?: array{0: int, 1: list<string>}}
     */
    public static function optionsFor(string $url): array
    {
        if (! app()->environment(['local', 'testing'])) {
            return [];
        }

        $parts = parse_url($url);

        if (
            ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'http'
            || ! is_string($parts['host'] ?? null)
            || ! str_ends_with(mb_strtolower($parts['host']), '.localhost')
        ) {
            return [];
        }

        $resolvedAddress = gethostbyname($parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : 80;

        if (
            filter_var($resolvedAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || $port < 1
            || $port > 65535
        ) {
            return [];
        }

        return ['curl' => [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $parts['host'], $port, $resolvedAddress)]]];
    }
}
