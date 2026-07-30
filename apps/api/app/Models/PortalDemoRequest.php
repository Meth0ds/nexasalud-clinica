<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'patient_id', 'prescription_id', 'request_type', 'status', 'submitted_at'])]
/**
 * Records a clearly simulated patient-portal request without creating a clinical instruction.
 */
final class PortalDemoRequest extends Model
{
    use BelongsToTenant, HasUlids;

    /**
     * Return the linked patient who initiated the demonstration request.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Return the prescription displayed when the demonstration request was submitted.
     */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    /**
     * Define the immutable submission timestamp cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'immutable_datetime',
        ];
    }
}
