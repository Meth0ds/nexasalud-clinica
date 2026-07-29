<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\PatientCommunication;

/**
 * Validate a versioned cancellation request for a patient communication.
 */
final class CancelPatientCommunicationRequest extends TenantFormRequest
{
    /**
     * Determine whether the authenticated user may update the routed communication.
     */
    public function authorize(): bool
    {
        $communication = $this->route('communication');

        return $communication instanceof PatientCommunication
            && $this->authenticatedUser()->can('update', $communication);
    }

    /**
     * Return validation rules for a communication cancellation mutation.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
