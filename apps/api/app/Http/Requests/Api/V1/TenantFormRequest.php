<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

abstract class TenantFormRequest extends FormRequest
{
    final protected function tenantId(): string
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;

        abort_unless($tenant instanceof Tenant, 403);

        return (string) $tenant->getKey();
    }

    final protected function authenticatedUser(): User
    {
        $user = $this->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
