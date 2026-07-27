<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'tenant_id' => fn (array $attributes): string => Invoice::withoutGlobalScope('tenant')->findOrFail($attributes['invoice_id'])->tenant_id,
            'service_code' => 'SYN-SERVICE-001',
            'description' => 'Servicio sanitario sintético',
            'quantity' => 1,
            'unit_price_minor' => 10000,
            'tax_minor' => 0,
            'total_minor' => 10000,
            'sort_order' => 0,
        ];
    }
}
