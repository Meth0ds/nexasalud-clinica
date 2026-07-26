<?php

namespace App\Models;

use App\Enums\PrescriptionStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\IsAppendOnly;
use Database\Factories\PrescriptionStatusHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'prescription_id', 'from_status', 'to_status', 'changed_by', 'reason', 'context', 'occurred_at'])]
#[Hidden(['reason', 'context'])]
class PrescriptionStatusHistory extends Model
{
    /** @use HasFactory<PrescriptionStatusHistoryFactory> */
    use BelongsToTenant, HasFactory, HasUlids, IsAppendOnly;

    public $timestamps = false;

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    protected function casts(): array
    {
        return [
            'from_status' => PrescriptionStatus::class,
            'to_status' => PrescriptionStatus::class,
            'reason' => 'encrypted',
            'context' => 'encrypted:array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
