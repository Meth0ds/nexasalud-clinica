<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceAbsoluteSessionLifetime
{
    private const ABSOLUTE_EXPIRY_SESSION_KEY = 'auth.session.absolute_expires_at';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expiresAt = $request->session()->get(self::ABSOLUTE_EXPIRY_SESSION_KEY);
        $now = time();
        $maximumExpiresAt = $now + ($this->absoluteLifetimeMinutes() * 60) + 5;

        if (! is_int($expiresAt) || $expiresAt <= $now || $expiresAt > $maximumExpiresAt) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new AuthenticationException;
        }

        return $next($request);
    }

    private function absoluteLifetimeMinutes(): int
    {
        $minutes = config('session.absolute_lifetime');

        return is_int($minutes) && $minutes >= 1 && $minutes <= 1440 ? $minutes : 0;
    }
}
