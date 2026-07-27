<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prevents shared browsers and intermediaries from storing authenticated or API responses.
 */
class PreventSensitiveResponseCaching
{
    /**
     * Adds restrictive cache and browser-sniffing headers after the application has created its response.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->is('api/*') || $request->is('auth/*') || $request->user() !== null) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }
}
