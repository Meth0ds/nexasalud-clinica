<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\PatientCommunication;

/**
 * Validate a versioned request to queue or schedule a patient communication.
 */
final class QueuePatientCommunicationRequest extends TenantFormRequest
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
     * Return validation rules for queueing a patient communication.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'scheduledAt' => ['nullable', 'date', 'after:now'],
        ];
    }
}
