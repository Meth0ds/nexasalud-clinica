<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PrescriptionStatus;
use App\Models\Prescription;
use Illuminate\Validation\Rule;

class TransitionPrescriptionRequest extends TenantFormRequest
{
    public function authorize(): bool
    {
        $prescription = $this->route('prescription');
        if (! $prescription instanceof Prescription) { return false; }
        $ability = $this->input('status') === 'signed' ? 'sign' : ($this->input('status') === 'sent' ? 'send' : 'update');
        return $this->authenticatedUser()->can($ability, $prescription);
    }
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
