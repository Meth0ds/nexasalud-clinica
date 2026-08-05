<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\WaitlistStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WaitlistEntryFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'clinic_id', 'patient_id', 'appointment_type_id', 'preferred_clinician_user_id', 'desired_from', 'desired_to', 'preferred_days', 'priority', 'status', 'notes', 'offered_at', 'offer_expires_at', 'offer_seen_at', 'offer_created_appointment', 'fulfilled_appointment_id', 'lock_version'])]
#[Hidden(['notes'])]
/**
 * Represents a tenant-scoped patient request for a future appointment opportunity.
 */
class WaitlistEntry extends Model
{
    /** @use HasFactory<WaitlistEntryFactory> */
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'priority' => 3,
        'status' => WaitlistStatus::Waiting->value,
        'lock_version' => 1,
    ];

    /**
     * Return the patient waiting for an appointment.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Return the preferred clinic for the waiting-list request.
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * Return the requested appointment type.
     */
    public function appointmentType(): BelongsTo
    {
        return $this->belongsTo(AppointmentType::class);
    }

    /**
     * Return the optionally preferred clinician.
     */
    public function preferredClinician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preferred_clinician_user_id');
    }

    /**
     * Return the appointment that fulfilled this waitlist entry, when present.
     */
    public function fulfilledAppointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'fulfilled_appointment_id');
    }

    /**
     * Cancel the provisional appointment owned by this offer when it is rejected or expires.
     */
    public function releaseOwnedReservation(string $reason): void
    {
        if (! $this->offer_created_appointment || ! is_string($this->fulfilled_appointment_id)) {
            return;
        }

        $appointment = Appointment::query()->find($this->fulfilled_appointment_id);

        if (! $appointment instanceof Appointment || ! $appointment->status->blocksAvailability()) {
            return;
        }

        $actor = User::query()->find($appointment->clinician_user_id);

        if (! $actor instanceof User) {
            throw new DomainException('Cannot release the provisional appointment without its clinician actor.');
        }

        $appointment->transitionTo(AppointmentStatus::Cancelled, $actor, $reason);
    }

    /**
     * Define scheduling preferences, status enum, encrypted notes and lifecycle casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'desired_from' => 'immutable_datetime',
            'desired_to' => 'immutable_datetime',
            'preferred_days' => 'array',
            'priority' => 'integer',
            'status' => WaitlistStatus::class,
            'notes' => 'encrypted',
            'offered_at' => 'immutable_datetime',
            'offer_expires_at' => 'immutable_datetime',
            'offer_seen_at' => 'immutable_datetime',
            'offer_created_appointment' => 'boolean',
            'lock_version' => 'integer',
        ];
    }
}
