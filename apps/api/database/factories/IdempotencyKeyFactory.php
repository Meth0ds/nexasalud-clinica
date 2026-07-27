<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\IdempotencyKey;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IdempotencyKey>
 */
class IdempotencyKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = (string) Str::uuid();

        return [
            'tenant_id' => Tenant::factory(),
            'actor_subject' => (string) Str::uuid(),
            'operation' => 'appointments.create',
            'key_hash' => hash('sha256', $key),
            'request_hash' => hash('sha256', '{"synthetic":true}'),
            'response_status' => 201,
            'response_body' => ['synthetic' => true],
            'resource_type' => Appointment::class,
            'resource_id' => (string) Str::ulid(),
            'expires_at' => now()->addDay(),
            'created_at' => now(),
        ];
    }
}
