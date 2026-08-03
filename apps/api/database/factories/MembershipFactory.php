<?php

namespace Database\Factories;

use App\Models\Membership;
use App\Models\MembershipClinic;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Schema;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    /**
     * Keep existing feature tests useful while preserving an explicit clinic grant in production.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Membership $membership): void {
            if (! app()->environment('testing') || ! Schema::hasTable('membership_clinic')) {
                return;
            }

            $membership->loadMissing('tenant');

            $membership->tenant->clinics()
                ->withoutGlobalScope('tenant')
                ->where('is_active', true)
                ->get()
                ->each(function ($clinic) use ($membership): void {
                    MembershipClinic::query()->firstOrCreate([
                        'tenant_id' => $membership->tenant_id,
                        'membership_id' => $membership->getKey(),
                        'clinic_id' => $clinic->getKey(),
                    ], ['is_active' => true]);
                });
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'is_active' => true,
            'joined_at' => now(),
        ];
    }
}
