<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Appointment;
use App\Support\Authorization\PermissionService;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

/**
 * Validate a patient portal request that reschedules an appointment through a slot hold.
 */
final class ReschedulePortalAppointmentRequest extends TenantFormRequest
{
    /**
     * Determine whether the request targets an appointment and the actor has portal access.
     */
    public function authorize(): bool
    {
        return $this->route('appointment') instanceof Appointment
            && app(PermissionService::class)->allows($this->authenticatedUser(), 'portal.self');
    }

    /**
     * Return tenant-scoped validation rules for a portal appointment rescheduling request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'holdId' => ['required', 'string', Rule::exists('appointment_slot_holds', 'id')->where(
                fn (Builder $query) => $query->where('tenant_id', $this->tenantId()),
            )],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
