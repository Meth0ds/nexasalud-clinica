<?php

namespace App\Models;

use App\Enums\ConsentStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ConsentRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'patient_id', 'consent_type', 'document_version', 'status', 'recorded_by', 'granted_at', 'expires_at', 'withdrawn_at', 'withdrawal_reason', 'evidence_hash'])]
#[Hidden(['withdrawal_reason'])]
class ConsentRecord extends Model
{
    /** @use HasFactory<ConsentRecordFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $attributes = ['status' => ConsentStatus::Active->value];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ClinicalDocument::class);
    }

    protected function casts(): array
    {
        return [
            'status' => ConsentStatus::class,
            'granted_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
            'withdrawal_reason' => 'encrypted',
        ];
    }
}
