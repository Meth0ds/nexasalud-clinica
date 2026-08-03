<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use App\Support\Authorization\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Transform an appointment and its loaded relations into the client-facing API contract.
 */
class AppointmentResource extends JsonResource
{
    /**
     * Convert the appointment resource into an array for the current request.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $patientName = trim((string) $this->patient?->first_name.' '.(string) $this->patient?->last_name);
        $data = [
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
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];

        if ($this->canViewReason($request)) {
            $data['reasonSummary'] = $this->reason ? Str::limit((string) $this->reason, 140) : null;
        }

        return $data;
    }

    /**
     * Determine whether this request may receive the appointment's potentially sensitive reason.
     */
    private function canViewReason(Request $request): bool
    {
        $cached = $request->attributes->get('appointments.can_view_reason');
        if (is_bool($cached)) {
            return $cached;
        }

        $actor = $request->user();
        $permissions = app(PermissionService::class);
        $allowed = $actor instanceof User
            && $this->resource instanceof Appointment
            && $permissions->belongsToCurrentTenant($this->resource)
            && $permissions->allows($actor, 'appointments.reason.read');

        $request->attributes->set('appointments.can_view_reason', $allowed);

        return $allowed;
    }

    /**
     * Derive up to two uppercase initials from a display name.
     */
    private static function initials(string $name): string
    {
        return Str::of($name)->explode(' ')->filter()->take(2)->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))->join('');
    }

    /**
     * Map a persisted appointment status to its frontend status identifier.
     */
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
