<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'clinic_id', 'patient_id', 'patient_coverage_id', 'appointment_id', 'invoice_number', 'status', 'currency', 'subtotal_minor', 'tax_minor', 'total_minor', 'balance_minor', 'issued_on', 'due_on', 'notes', 'lock_version'])]
#[Hidden(['notes'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'status' => InvoiceStatus::Draft->value,
        'currency' => 'EUR',
        'subtotal_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 0,
        'balance_minor' => 0,
        'lock_version' => 1,
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function coverage(): BelongsTo
    {
        return $this->belongsTo(PatientCoverage::class, 'patient_coverage_id');
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'balance_minor' => 'integer',
            'issued_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'notes' => 'encrypted',
            'lock_version' => 'integer',
        ];
    }
}
