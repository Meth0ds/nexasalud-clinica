<?php

namespace App\Http\Middleware;

use App\Exceptions\IdentityAccessDeniedException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terminates sessions for inactive users or identities whose sessions were revoked.
 */
class EnsureUserIsActive
{
    /**
     * Reject a revoked or inactive user before invoking the next request handler.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        $authenticatedAt = $request->session()->get('auth.assurance.authenticated_at');
        $sessionWasRevoked = $user instanceof User
            && $user->sessions_revoked_at !== null
            && (! is_int($authenticatedAt) || $authenticatedAt <= $user->sessions_revoked_at->getTimestamp());

        if ($user instanceof User && (! $user->is_active || $sessionWasRevoked)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new IdentityAccessDeniedException;
        }

        return $next($request);
    }
}
