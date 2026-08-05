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
use Illuminate\Support\Facades\Log;

/**
 * Completes the browser-facing OpenID Connect callback and maps failures to safe frontend errors.
 */
class OidcCallbackController extends Controller
{
    /**
     * Create the OIDC callback controller.
     */
    public function __construct(private OidcAuthenticationService $authentication) {}

    /**
     * Complete a validated callback and redirect the browser to the local application.
     */
    public function __invoke(OidcCallbackRequest $request): RedirectResponse
    {
        try {
            return redirect()->to($this->authentication->complete($request));
        } catch (IdentityMfaEnrollmentCompletedException) {
            return $this->errorRedirect('mfa_enrollment_completed');
        } catch (IdentityAccessDeniedException) {
            return $this->errorRedirect('access_denied');
        } catch (IdentityUnavailableException $exception) {
            Log::warning('OIDC callback failed: identity unavailable', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            report($exception);

            return $this->errorRedirect('identity_unavailable');
        } catch (IdentityProtocolException|InvalidOidcTransactionException $exception) {
            Log::warning('OIDC callback failed: identity invalid', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'previous' => $exception->getPrevious() !== null
                    ? $exception->getPrevious()::class.': '.$exception->getPrevious()->getMessage()
                    : null,
            ]);
            report($exception);

            return $this->errorRedirect('identity_invalid');
        }
    }

    /**
     * Build a safe frontend login redirect for an allowed authentication error code.
     */
    private function errorRedirect(string $error): RedirectResponse
    {
        return redirect()->to($this->authentication->frontendUrl('/login?error='.$error));
    }
}
