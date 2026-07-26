<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\IdentityUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StartOidcLoginRequest;
use App\Services\Identity\OidcAuthenticationService;
use Illuminate\Http\RedirectResponse;

class StartOidcLoginController extends Controller
{
    public function __construct(private OidcAuthenticationService $authentication) {}

    public function __invoke(StartOidcLoginRequest $request): RedirectResponse
    {
        try {
            return $this->authentication->redirect($request, $request->validated('returnTo'));
        } catch (IdentityUnavailableException $exception) {
            report($exception);

            return redirect()->to($this->authentication->frontendUrl('/login?error=identity_unavailable'));
        }
    }
}
