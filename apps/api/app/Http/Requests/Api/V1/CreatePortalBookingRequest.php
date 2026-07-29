<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\Authorization\PermissionService;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

/**
 * Validate a patient portal request that consumes an appointment slot hold.
 */
final class CreatePortalBookingRequest extends TenantFormRequest
{
    /**
     * Determine whether the authenticated user has patient portal access.
     */
    public function authorize(): bool
    {
        return app(PermissionService::class)->allows($this->authenticatedUser(), 'portal.self');
    }

    /**
     * Return tenant-scoped validation rules for a portal booking request.
     *
     * @return array<string, list<mixed>>
     */
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
