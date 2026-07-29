<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PrescriptionStatus;
use App\Models\Prescription;
use Illuminate\Validation\Rule;

/**
 * Validate a versioned prescription status transition and its state-specific fields.
 */
class TransitionPrescriptionRequest extends TenantFormRequest
{
    /**
     * Determine whether the authenticated user has the ability required by the requested transition.
     */
    public function authorize(): bool
    {
        $prescription = $this->route('prescription');
        if (! $prescription instanceof Prescription) {
            return false;
        }
        $ability = $this->input('status') === 'signed' ? 'sign' : ($this->input('status') === 'sent' ? 'send' : 'update');

        return $this->authenticatedUser()->can($ability, $prescription);
    }

    /**
     * Return validation rules for a prescription status transition.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::enum(PrescriptionStatus::class)],
            'reason' => ['nullable', 'required_if:status,cancelled', 'string', 'min:3', 'max:500'],
            'externalProviderReference' => ['nullable', 'required_if:status,sent', 'string', 'max:160'],
        ];
    }
}
