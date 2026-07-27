<?php

namespace App\Models;

use App\Enums\PrescriptionStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PrescriptionItemFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'prescription_id', 'medication_code_system', 'medication_code', 'medication_display', 'dose_amount', 'dose_unit_code', 'route_code', 'frequency_code', 'duration_value', 'duration_unit', 'quantity', 'quantity_unit_code', 'instructions', 'sort_order'])]
#[Hidden(['medication_display', 'instructions'])]
class PrescriptionItem extends Model
{
    /** @use HasFactory<PrescriptionItemFactory> */
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $attributes = ['sort_order' => 0];

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    protected static function booted(): void
    {
        $ensureDraft = function (self $item): void {
            $prescription = $item->prescription()->first();

            if ($prescription && $prescription->status !== PrescriptionStatus::Draft) {
                throw new DomainException('Prescription items can only be changed while the prescription is a draft.');
            }
        };

        static::saving($ensureDraft);
        static::deleting($ensureDraft);
    }

    protected function casts(): array
    {
        return [
            'medication_display' => 'encrypted',
            'dose_amount' => 'decimal:3',
            'duration_value' => 'integer',
            'quantity' => 'decimal:3',
            'instructions' => 'encrypted',
            'sort_order' => 'integer',
        ];
    }
}
