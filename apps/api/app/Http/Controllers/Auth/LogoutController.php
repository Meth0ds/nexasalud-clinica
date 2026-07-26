<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LogoutRequest;
use App\Services\Identity\OidcAuthenticationService;
use App\Services\Identity\OidcLogoutUrlBuilder;
use Illuminate\Http\JsonResponse;
use Throwable;

class LogoutController extends Controller
{
    public function __construct(
        private OidcAuthenticationService $authentication,
        private OidcLogoutUrlBuilder $logoutUrlBuilder,
    ) {}

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
