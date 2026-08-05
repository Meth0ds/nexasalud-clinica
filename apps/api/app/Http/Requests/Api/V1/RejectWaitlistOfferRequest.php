<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\WaitlistEntry;

/**
 * Validate an optimistic-concurrency request to reject a waitlist offer.
 */
class RejectWaitlistOfferRequest extends TenantFormRequest
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
     * Return the validation rules for a rejected waitlist offer.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
