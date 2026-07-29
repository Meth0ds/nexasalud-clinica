<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LogoutRequest;
use App\Services\Identity\OidcAuthenticationService;
use App\Services\Identity\OidcLogoutUrlBuilder;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Terminates the local session and optionally supplies a validated upstream logout redirect.
 */
class LogoutController extends Controller
{
    /**
     * Create the logout controller.
     */
    public function __construct(
        private OidcAuthenticationService $authentication,
        private OidcLogoutUrlBuilder $logoutUrlBuilder,
    ) {}

    /**
     * End the local session and report an optional upstream logout destination.
     */
    public function __invoke(LogoutRequest $request): JsonResponse
    {
        $upstreamRequested = $request->boolean('upstream');
        $this->authentication->invalidate($request);
        $upstreamRedirect = null;

        if ($upstreamRequested) {
            try {
                $upstreamRedirect = $this->logoutUrlBuilder->build();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return response()->json([
            'signedOut' => true,
            'upstreamRedirect' => $upstreamRedirect,
        ]);
    }
}
