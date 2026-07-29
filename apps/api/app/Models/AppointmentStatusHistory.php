<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\IsAppendOnly;
use Database\Factories\AppointmentStatusHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'appointment_id', 'from_status', 'to_status', 'changed_by', 'reason', 'context', 'occurred_at'])]
#[Hidden(['reason', 'context'])]
/**
 * Stores an append-only history of appointment status transitions.
 */
class AppointmentStatusHistory extends Model
{
    /** @use HasFactory<AppointmentStatusHistoryFactory> */
    use BelongsToTenant, HasFactory, HasUlids, IsAppendOnly;

    public $timestamps = false;

    /**
     * Return the appointment whose status changed.
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * Return the user who initiated the status transition.
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Define status enum, encrypted context and immutable timestamp attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => AppointmentStatus::class,
            'to_status' => AppointmentStatus::class,
            'reason' => 'encrypted',
            'context' => 'encrypted:array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
