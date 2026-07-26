<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\IsAppendOnly;
use Database\Factories\AppointmentChangeHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'appointment_id', 'changed_by', 'change_type', 'from_starts_at', 'from_ends_at', 'to_starts_at', 'to_ends_at', 'from_room_id', 'to_room_id', 'from_clinician_user_id', 'to_clinician_user_id', 'reason', 'occurred_at'])]
#[Hidden(['reason'])]
class AppointmentChangeHistory extends Model
{
    /** @use HasFactory<AppointmentChangeHistoryFactory> */
    use BelongsToTenant, HasFactory, HasUlids, IsAppendOnly;

    public $timestamps = false;

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    protected function casts(): array
    {
        return [
            'from_starts_at' => 'immutable_datetime',
            'from_ends_at' => 'immutable_datetime',
            'to_starts_at' => 'immutable_datetime',
            'to_ends_at' => 'immutable_datetime',
            'reason' => 'encrypted',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
