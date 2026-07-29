<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\Authorization\PermissionService;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

/**
 * Validate a patient portal request to reserve an appointment slot temporarily.
 */
final class CreateSlotHoldRequest extends TenantFormRequest
{
    /**
     * Determine whether the authenticated user has patient portal access.
     */
    public function authorize(): bool
    {
        return app(PermissionService::class)->allows($this->authenticatedUser(), 'portal.self');
    }

    /**
     * Return tenant-scoped validation rules for a prospective appointment slot hold.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $tenant = $this->tenantId();
        $exists = fn (string $table) => Rule::exists($table, 'id')->where(
            fn (Builder $query) => $query->where('tenant_id', $tenant),
        );

        return [
            'clinicId' => ['required', 'string', $exists('clinics')],
            'appointmentTypeId' => ['required', 'string', $exists('appointment_types')],
            'practitionerId' => ['required', 'string', Rule::exists('memberships', 'user_id')->where(
                fn (Builder $query) => $query->where('tenant_id', $tenant)->where('is_active', true),
            )],
            'roomId' => ['nullable', 'string', $exists('rooms')],
            'startsAt' => ['required', 'date', 'after:now'],
            'mode' => ['required', Rule::in(['in-person', 'video', 'phone'])],
        ];
    }
}
