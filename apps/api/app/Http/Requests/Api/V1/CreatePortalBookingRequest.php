<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\Authorization\PermissionService;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

final class CreatePortalBookingRequest extends TenantFormRequest
{
    public function authorize(): bool
    {
        return app(PermissionService::class)->allows($this->authenticatedUser(), 'portal.self');
    }

    public function rules(): array
    {
        return [
            'holdId' => ['required', 'string', Rule::exists('appointment_slot_holds', 'id')->where(
                fn (Builder $query) => $query->where('tenant_id', $this->tenantId()),
            )],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
