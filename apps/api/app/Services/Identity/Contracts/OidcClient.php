<?php

namespace App\Services\Identity\Contracts;

use App\Services\Identity\Data\OidcLoginResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

interface OidcClient
{
    public function redirect(Request $request, string $returnPath): RedirectResponse;

    public function complete(Request $request): OidcLoginResult;
}
