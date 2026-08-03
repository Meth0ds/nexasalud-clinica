<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'clinic_id', 'patient_id', 'is_active', 'first_seen_at'])]
/**
 * Records the clinics in which a tenant patient may be accessed.
 */
final class ClinicPatient extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'clinic_patient';

    protected $attributes = ['is_active' => true];

    /**
     * Return the clinic containing this patient record.
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * Return the patient linked to this clinic.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Define activation and first-seen timestamp casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'first_seen_at' => 'immutable_datetime',
        ];
    }
}
