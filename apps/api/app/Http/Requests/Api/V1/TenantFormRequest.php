<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Provide tenant context and authenticated-user guards for versioned API form requests.
 */
abstract class TenantFormRequest extends FormRequest
{
    /**
     * Return the current tenant identifier or abort when tenant context is unavailable.
     */
    final protected function tenantId(): string
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;

        abort_unless($tenant instanceof Tenant, 403);

        return (string) $tenant->getKey();
    }

    /**
     * Return the authenticated application user or abort when authentication is unavailable.
     */
    final protected function authenticatedUser(): User
    {
        $user = $this->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
