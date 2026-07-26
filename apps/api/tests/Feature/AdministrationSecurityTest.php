<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class AdministrationSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private Clinic $clinic;

    private User $administrator;

    private Membership $administratorMembership;

    private User $target;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('c', 32)));
        config()->set('session.absolute_lifetime', 480);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withHeader('Origin', 'http://localhost:4200');

        $this->tenant = Tenant::factory()->create(['timezone' => 'Europe/Madrid']);
        app()->instance('currentTenant', $this->tenant);
        $this->clinic = Clinic::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'timezone' => 'Europe/Madrid',
            'is_active' => true,
        ]);
        $this->administrator = User::factory()->create(['is_active' => true]);
        $this->administratorMembership = Membership::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'user_id' => $this->administrator->getKey(),
            'is_active' => true,
        ]);
        $administratorRole = Role::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'permissions' => ['admin.users.read', 'admin.users.write', 'admin.security.write'],
        ]);
        $this->administratorMembership->roles()->attach($administratorRole, [
            'tenant_id' => $this->tenant->getKey(),
            'granted_by' => $this->administrator->getKey(),
            'granted_at' => now(),
        ]);

        $this->target = User::factory()->create(['is_active' => true]);
        Membership::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'user_id' => $this->target->getKey(),
            'is_active' => true,
        ]);
        app()->forgetInstance('currentTenant');
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('currentClinic');
        app()->forgetInstance('currentMembership');
        app()->forgetInstance('currentTenant');

        parent::tearDown();
    }

    public function test_an_administrator_cannot_disable_or_reassign_their_own_membership(): void
    {
        $this->actingAs($this->administrator);

        $response = $this->withSession($this->recentMfaContext())
            ->patchJson('/api/v1/administration/users/'.$this->administrator->getKey(), [
                'active' => false,
                'roleIds' => [],
                'reason' => 'Solicitud de prueba de auto bloqueo',
            ]);

        $response->assertConflict();
        $this->assertDatabaseHas('memberships', [
            'tenant_id' => $this->tenant->getKey(),
            'user_id' => $this->administrator->getKey(),
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('membership_role', 1);
    }

    public function test_target_membership_changes_require_recent_mfa_and_are_audited(): void
    {
        $this->actingAs($this->administrator);

        $staleResponse = $this->withSession($this->sessionContext([
            'auth.assurance.amr' => ['otp'],
            'auth.assurance.authenticated_at' => now()->subMinutes(16)->getTimestamp(),
        ]))->patchJson('/api/v1/administration/users/'.$this->target->getKey(), [
            'active' => false,
            'reason' => 'Baja temporal aprobada por dirección',
        ]);
        $staleResponse->assertForbidden();

        $updatedResponse = $this->withSession($this->recentMfaContext())
            ->patchJson('/api/v1/administration/users/'.$this->target->getKey(), [
                'active' => false,
                'reason' => 'Baja temporal aprobada por dirección',
            ]);

        $updatedResponse->assertOk()->assertJsonPath('data.active', false);
        $this->assertDatabaseHas('memberships', [
            'tenant_id' => $this->tenant->getKey(),
            'user_id' => $this->target->getKey(),
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $this->tenant->getKey(),
            'event_type' => 'administration.membership_updated',
            'actor_user_id' => $this->administrator->getKey(),
        ]);
    }

    /** @return array<string, int|string|array<int, string>> */
    private function recentMfaContext(): array
    {
        return $this->sessionContext([
            'auth.assurance.amr' => ['otp'],
            'auth.assurance.authenticated_at' => now()->getTimestamp(),
        ]);
    }

    /**
     * @param  array<string, int|string|array<int, string>>  $assurance
     * @return array<string, int|string|array<int, string>>
     */
    private function sessionContext(array $assurance): array
    {
        return [
            'auth.session.absolute_expires_at' => time() + 3600,
            'auth.context.membership_id' => $this->administratorMembership->getKey(),
            'auth.context.clinic_id' => $this->clinic->getKey(),
            ...$assurance,
        ];
    }
}
