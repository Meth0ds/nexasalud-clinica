<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\PatientCommunication;

final class CancelPatientCommunicationRequest extends TenantFormRequest
{
    public function authorize(): bool
    {
        $communication = $this->route('communication');

        return $communication instanceof PatientCommunication
            && $this->authenticatedUser()->can('update', $communication);
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
