<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Transform a waitlist entry and its patient context into the scheduling API contract.
 */
class WaitlistEntryResource extends JsonResource
{
    /**
     * Convert the waitlist entry resource into an array for the current request.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $patient = trim((string) $this->patient?->first_name.' '.(string) $this->patient?->last_name);

        return [
            'id' => $this->getKey(),
            'version' => $this->lock_version,
            'clinicId' => $this->clinic_id,
            'appointmentTypeId' => $this->appointment_type_id,
            'patientId' => $this->patient_id,
            'patientDisplayName' => $patient,
            'patientInitials' => Str::of($patient)->explode(' ')->filter()->take(2)->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))->join(''),
            'requestedType' => $this->appointmentType?->name,
            'preferredWindow' => [$this->desired_from?->toIso8601String(), $this->desired_to?->toIso8601String()],
            'preferredDays' => $this->preferred_days ?? [],
            'priority' => $this->priority,
            'status' => $this->status->value,
            'waitingSince' => $this->created_at?->toIso8601String(),
            'offeredAt' => $this->offered_at?->toIso8601String(),
            'offerExpiresAt' => $this->offer_expires_at?->toIso8601String(),
            'offeredSlot' => $this->fulfilledAppointment ? [
                'id' => $this->fulfilledAppointment->getKey(),
                'startsAt' => $this->fulfilledAppointment->starts_at?->toIso8601String(),
                'endsAt' => $this->fulfilledAppointment->ends_at?->toIso8601String(),
                'practitionerName' => $this->fulfilledAppointment->clinician?->display_name,
                'roomName' => $this->fulfilledAppointment->room?->name,
                'clinicName' => $this->fulfilledAppointment->clinic?->name,
            ] : null,
        ];
    }
}
