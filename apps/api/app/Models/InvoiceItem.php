<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\InvoiceItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'invoice_id', 'service_code', 'description', 'quantity', 'unit_price_minor', 'tax_minor', 'total_minor', 'sort_order'])]
#[Hidden(['description'])]
class InvoiceItem extends Model
{
    /** @use HasFactory<InvoiceItemFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $attributes = [
        'quantity' => 1,
        'tax_minor' => 0,
        'sort_order' => 0,
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    protected function casts(): array
    {
        return [
            'description' => 'encrypted',
            'quantity' => 'decimal:3',
            'unit_price_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
