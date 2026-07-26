<?php

namespace App\Http\Requests\Api\V1;

use App\Models\WaitlistEntry;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

class OfferWaitlistRequest extends TenantFormRequest
{
    public function authorize(): bool
    {
        $entry = $this->route('waitlistEntry');
        return $entry instanceof WaitlistEntry && $this->authenticatedUser()->can('update', $entry);
    }
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
