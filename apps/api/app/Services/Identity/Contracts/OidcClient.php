<?php

namespace App\Services\Identity\Contracts;

use App\Services\Identity\Data\OidcLoginResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Defines the OpenID Connect client operations used by the authentication flow.
 */
interface OidcClient
{
    /**
     * Create the redirect response that starts an OpenID Connect login.
     */
    public function redirect(Request $request, string $returnPath): RedirectResponse;

    /**
     * Complete an OpenID Connect callback and return the verified login result.
     */
    public function complete(Request $request): OidcLoginResult;
}
