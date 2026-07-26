<?php

namespace Database\Factories;

use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditEvent>
 */
class AuditEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'actor_user_id' => User::factory(),
            'actor_subject' => (string) Str::uuid(),
            'event_type' => 'demo.resource.viewed',
            'auditable_type' => 'synthetic',
            'auditable_id' => (string) Str::ulid(),
            'request_id' => (string) Str::ulid(),
            'ip_hash' => hash('sha256', '192.0.2.1'),
            'user_agent_hash' => hash('sha256', 'synthetic-test-agent'),
            'metadata' => ['synthetic' => true],
            'occurred_at' => now(),
        ];
    }
}
