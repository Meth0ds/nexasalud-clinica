<?php

namespace App\Http\Requests\Api\V1;

use App\Models\WaitlistEntry;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

/**
 * Validate an optimistic-concurrency request to offer a waitlist entry a slot.
 */
class OfferWaitlistRequest extends TenantFormRequest
{
    /**
     * Determine whether the authenticated user may update the routed waitlist entry.
     */
    public function authorize(): bool
    {
        $entry = $this->route('waitlistEntry');

        return $entry instanceof WaitlistEntry && $this->authenticatedUser()->can('update', $entry);
    }

    /**
     * Return tenant-scoped validation rules for a waitlist offer.
     *
     * An offer may either reference an existing matching appointment or define a
     * concrete slot that the server reserves by creating a provisional appointment.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $tenantId = $this->tenantId();

        return [
            'version' => ['required', 'integer', 'min:1'],
            'appointmentId' => [
                'nullable',
                'string',
                Rule::exists('appointments', 'id')->where(
                    fn (Builder $query) => $query->where('tenant_id', $tenantId),
                ),
            ],
            'startsAt' => ['required_without:appointmentId', 'nullable', 'date'],
            'endsAt' => ['required_with:startsAt', 'nullable', 'date', 'after:startsAt'],
            'roomId' => [
                'nullable',
                'string',
                Rule::exists('rooms', 'id')->where(
                    fn (Builder $query) => $query->where('tenant_id', $tenantId),
                ),
            ],
            'practitionerId' => ['nullable', 'string'],
            'mode' => ['nullable', Rule::in(['in-person', 'video', 'phone'])],
            'expiresInMinutes' => ['required', 'integer', 'between:5,120'],
        ];
    }
}
