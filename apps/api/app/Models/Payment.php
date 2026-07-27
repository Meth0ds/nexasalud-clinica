<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'invoice_id', 'amount_minor', 'currency', 'status', 'method_code', 'external_reference', 'failure_reason', 'received_at'])]
#[Hidden(['failure_reason'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $attributes = [
        'currency' => 'EUR',
        'status' => PaymentStatus::Pending->value,
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'status' => PaymentStatus::class,
            'failure_reason' => 'encrypted',
            'received_at' => 'immutable_datetime',
        ];
    }
}
