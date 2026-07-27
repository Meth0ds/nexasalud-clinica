<?php

namespace App\Models;

use App\Enums\WaitlistStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WaitlistEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'clinic_id', 'patient_id', 'appointment_type_id', 'preferred_clinician_user_id', 'desired_from', 'desired_to', 'preferred_days', 'priority', 'status', 'notes', 'offered_at', 'offer_expires_at', 'fulfilled_appointment_id', 'lock_version'])]
#[Hidden(['notes'])]
class WaitlistEntry extends Model
{
    /** @use HasFactory<WaitlistEntryFactory> */
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'priority' => 3,
        'status' => WaitlistStatus::Waiting->value,
        'lock_version' => 1,
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function appointmentType(): BelongsTo
    {
        return $this->belongsTo(AppointmentType::class);
    }

    public function preferredClinician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preferred_clinician_user_id');
    }

    public function fulfilledAppointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'fulfilled_appointment_id');
    }

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
            'lock_version' => 'integer',
        ];
    }
}
