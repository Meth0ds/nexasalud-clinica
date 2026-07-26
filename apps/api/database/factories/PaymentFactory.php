<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
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
            'amount_minor' => 10000,
            'currency' => 'EUR',
            'status' => 'completed',
            'method_code' => 'external_card_processor',
            'external_reference' => 'SYN-PAY-'.fake()->unique()->numerify('########'),
            'received_at' => now(),
        ];
    }
}
