<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PatientAllergyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'patient_id', 'substance_code_system', 'substance_code', 'substance_display', 'clinical_status', 'verification_status', 'criticality', 'notes', 'recorded_by', 'recorded_at'])]
#[Hidden(['substance_display', 'notes'])]
class PatientAllergy extends Model
{
    /** @use HasFactory<PatientAllergyFactory> */
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'clinical_status' => 'active',
        'verification_status' => 'unconfirmed',
        'criticality' => 'unknown',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function adverseReactions(): HasMany
    {
        return $this->hasMany(AdverseReaction::class);
    }

    protected function casts(): array
    {
        return [
            'substance_display' => 'encrypted',
            'notes' => 'encrypted',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
