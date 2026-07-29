<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Transform a prescription into an API representation including loaded medication safety alerts.
 */
class PrescriptionResource extends JsonResource
{
    /**
     * Convert the prescription resource into an array for the current request.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $patient = trim((string) $this->patient?->first_name.' '.(string) $this->patient?->last_name);
        $matchingAllergies = collect();

        if ($this->relationLoaded('patient') && $this->patient?->relationLoaded('allergies') && $this->relationLoaded('items')) {
            $medicationCodes = $this->items->pluck('medication_code')->filter();
            $matchingAllergies = $this->patient->allergies
                ->where('clinical_status', 'active')
                ->filter(fn ($allergy): bool => $medicationCodes->contains($allergy->substance_code))
                ->values();
        }

        return [
            'id' => $this->getKey(),
            'version' => $this->lock_version,
            'patientId' => $this->patient_id,
            'patientDisplayName' => $patient,
            'patientInitials' => Str::of($patient)->explode(' ')->filter()->take(2)->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))->join(''),
            'prescriberId' => $this->prescriber_user_id,
            'prescriberName' => $this->prescriber?->display_name,
            'status' => $this->status->value,
            'clinicalIndication' => $this->clinical_indication,
            'validUntil' => $this->valid_until?->toDateString(),
            'signedAt' => $this->signed_at?->toIso8601String(),
            'sentAt' => $this->sent_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'safetyAlerts' => $this->when(
                $this->relationLoaded('patient') && $this->patient?->relationLoaded('allergies') && $this->relationLoaded('items'),
                fn () => $matchingAllergies->map(fn ($allergy): array => [
                    'code' => $allergy->substance_code,
                    'label' => $allergy->substance_display,
                    'criticality' => $allergy->criticality,
                ])->values(),
            ),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'id' => $item->getKey(),
                'codeSystem' => $item->medication_code_system,
                'code' => $item->medication_code,
                'display' => $item->medication_display,
                'doseAmount' => $item->dose_amount,
                'doseUnitCode' => $item->dose_unit_code,
                'routeCode' => $item->route_code,
                'frequencyCode' => $item->frequency_code,
                'durationValue' => $item->duration_value,
                'durationUnit' => $item->duration_unit,
                'quantity' => $item->quantity,
                'quantityUnitCode' => $item->quantity_unit_code,
                'instructions' => $item->instructions,
            ])),
        ];
    }
}
