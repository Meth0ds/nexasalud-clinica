<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AdverseReactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'patient_id', 'patient_allergy_id', 'manifestation_code_system', 'manifestation_code', 'manifestation_display', 'severity', 'notes', 'occurred_at', 'recorded_by'])]
#[Hidden(['manifestation_display', 'notes'])]
class AdverseReaction extends Model
{
    /** @use HasFactory<AdverseReactionFactory> */
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $attributes = ['severity' => 'unknown'];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function allergy(): BelongsTo
    {
        return $this->belongsTo(PatientAllergy::class, 'patient_allergy_id');
    }

    protected function casts(): array
    {
        return [
            'manifestation_display' => 'encrypted',
            'notes' => 'encrypted',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
