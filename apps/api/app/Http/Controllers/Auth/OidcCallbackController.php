<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\IdentityAccessDeniedException;
use App\Exceptions\IdentityMfaEnrollmentCompletedException;
use App\Exceptions\IdentityProtocolException;
use App\Exceptions\IdentityUnavailableException;
use App\Exceptions\InvalidOidcTransactionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\OidcCallbackRequest;
use App\Services\Identity\OidcAuthenticationService;
use Illuminate\Http\RedirectResponse;

class OidcCallbackController extends Controller
{
    public function __construct(private OidcAuthenticationService $authentication) {}

    public function __invoke(OidcCallbackRequest $request): RedirectResponse
    {
        try {
            return redirect()->to($this->authentication->complete($request));
        } catch (IdentityMfaEnrollmentCompletedException) {
            return $this->errorRedirect('mfa_enrollment_completed');
        } catch (IdentityAccessDeniedException) {
            return $this->errorRedirect('access_denied');
        } catch (IdentityUnavailableException $exception) {
            report($exception);

            return $this->errorRedirect('identity_unavailable');
        } catch (IdentityProtocolException|InvalidOidcTransactionException) {
            return $this->errorRedirect('identity_invalid');
        }
    }

    private function errorRedirect(string $error): RedirectResponse
    {
        return redirect()->to($this->authentication->frontendUrl('/login?error='.$error));
    }
}
