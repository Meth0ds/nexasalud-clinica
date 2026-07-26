<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'clinic_id', 'appointment_type_id', 'patient_id', 'created_by_user_id', 'clinician_user_id', 'room_id', 'starts_at', 'ends_at', 'channel', 'status', 'expires_at', 'consumed_at', 'appointment_id', 'lock_version'])]
final class AppointmentSlotHold extends Model
{
    use BelongsToTenant, HasUlids;

    protected $attributes = ['status' => 'active', 'lock_version' => 1];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointmentType(): BelongsTo
    {
        return $this->belongsTo(AppointmentType::class);
    }

    public function clinician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'clinician_user_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

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
