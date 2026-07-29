<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transform a clinical note into the API representation authorized for its reader.
 */
final class ClinicalNoteResource extends JsonResource
{
    /**
     * Convert the clinical note resource into an array for the current request.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'patientId' => $this->patient_id,
            'appointmentId' => $this->appointment_id,
            'supersedesId' => $this->supersedes_id,
            'status' => $this->status->value,
            'content' => $this->content,
            'authorDisplayName' => $this->author?->display_name,
            'createdAt' => $this->created_at?->toIso8601String(),
            'signedAt' => $this->signed_at?->toIso8601String(),
        ];
    }
}
