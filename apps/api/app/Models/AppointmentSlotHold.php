<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'clinic_id', 'appointment_type_id', 'patient_id', 'created_by_user_id', 'clinician_user_id', 'room_id', 'starts_at', 'ends_at', 'channel', 'status', 'expires_at', 'consumed_at', 'appointment_id', 'lock_version'])]
/**
 * Represents a short-lived, tenant-scoped reservation of an appointment slot before confirmation.
 */
final class AppointmentSlotHold extends Model
{
    use BelongsToTenant, HasUlids;

    protected $attributes = ['status' => 'active', 'lock_version' => 1];

    /**
     * Return the patient for whom the slot is held.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Return the requested appointment type.
     */
    public function appointmentType(): BelongsTo
    {
        return $this->belongsTo(AppointmentType::class);
    }

    /**
     * Return the optionally selected clinician for the hold.
     */
    public function clinician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'clinician_user_id');
    }

    /**
     * Return the optionally selected room for the hold.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Define immutable slot timestamps and optimistic-lock version casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }
}
