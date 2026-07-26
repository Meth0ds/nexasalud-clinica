<?php

namespace App\Services\Identity;

final class SafeReturnPath
{
    public function normalize(?string $candidate): string
    {
        if ($candidate === null || $candidate === '') {
            return '/';
        }

        if (mb_strlen($candidate) > 1024 || preg_match('/[\x00-\x1F\x7F]/u', $candidate) === 1) {
            return '/';
        }

        $decoded = rawurldecode($candidate);

        if (
            ! str_starts_with($candidate, '/') ||
            str_starts_with($candidate, '//') ||
            str_starts_with($decoded, '//') ||
            str_contains($candidate, '\\') ||
            str_contains($decoded, '\\')
        ) {
            return '/';
        }

        $parts = parse_url($candidate);

        if (
            $parts === false ||
            isset($parts['scheme']) || isset($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
        ) {
            return '/';
        }

        return $candidate;
    }
}
