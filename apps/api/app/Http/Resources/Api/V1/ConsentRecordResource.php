<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transform a consent record into the minimal clinical API contract.
 */
class ConsentRecordResource extends JsonResource
{
    /**
     * Convert the consent record resource into an array for the current request.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'consentType' => $this->consent_type,
            'documentVersion' => $this->document_version,
            'status' => $this->status->value,
            'grantedAt' => $this->granted_at?->toIso8601String(),
            'expiresAt' => $this->expires_at?->toIso8601String(),
            'withdrawnAt' => $this->withdrawn_at?->toIso8601String(),
        ];
    }
}
