<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\AppointmentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $patientName = trim((string) $this->patient?->first_name.' '.(string) $this->patient?->last_name);

        return [
            'id' => $this->getKey(),
            'version' => $this->lock_version,
            'patientId' => $this->patient_id,
            'patientDisplayName' => $patientName,
            'patientInitials' => self::initials($patientName),
            'practitionerId' => $this->clinician_user_id,
            'practitionerName' => $this->clinician?->display_name,
            'roomId' => $this->room_id,
            'roomName' => $this->room?->name,
            'startsAt' => $this->starts_at?->toIso8601String(),
            'endsAt' => $this->ends_at?->toIso8601String(),
            'timezone' => $this->clinic?->timezone ?? 'Europe/Madrid',
            'typeName' => $this->appointmentType?->name,
            'appointmentTypeId' => $this->appointment_type_id,
            'mode' => match ($this->channel) {
                'video' => 'video',
                'phone' => 'phone',
                default => 'in-person',
            },
            'status' => self::frontendStatus($this->status),
            'reasonSummary' => $this->reason ? Str::limit((string) $this->reason, 140) : null,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }

    private static function initials(string $name): string
    {
        return Str::of($name)->explode(' ')->filter()->take(2)->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))->join('');
    }

    private static function frontendStatus(AppointmentStatus $status): string
    {
        return match ($status) {
            AppointmentStatus::Scheduled => 'pending',
            AppointmentStatus::Confirmed => 'confirmed',
            AppointmentStatus::CheckedIn => 'waiting',
            AppointmentStatus::InProgress => 'in-progress',
            AppointmentStatus::Completed => 'completed',
            AppointmentStatus::Cancelled => 'cancelled',
            AppointmentStatus::NoShow => 'no-show',
        };
    }
}
