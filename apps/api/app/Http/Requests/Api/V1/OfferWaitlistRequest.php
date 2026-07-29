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
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'appointmentId' => [
                'nullable',
                'string',
                Rule::exists('appointments', 'id')->where(
                    fn (Builder $query) => $query->where('tenant_id', $this->tenantId()),
                ),
            ],
            'expiresInMinutes' => ['required', 'integer', 'between:5,120'],
        ];
    }
}
