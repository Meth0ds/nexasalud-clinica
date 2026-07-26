<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\Role;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class DashboardOperationalDataTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private Clinic $clinic;

    private User $clinician;

    private Membership $membership;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('d', 32)));
        config()->set('session.absolute_lifetime', 480);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withHeader('Origin', 'http://localhost:4200');

        $this->tenant = Tenant::factory()->create(['timezone' => 'Europe/Madrid']);
        app()->instance('currentTenant', $this->tenant);
        $this->clinic = Clinic::factory()->create(['tenant_id' => $this->tenant->getKey(), 'timezone' => 'Europe/Madrid', 'is_active' => true]);
        $this->clinician = User::factory()->create(['is_active' => true]);
        $this->membership = Membership::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'user_id' => $this->clinician->getKey(),
            'is_active' => true,
        ]);
        $role = Role::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'permissions' => ['appointments.read'],
        ]);
        $this->membership->roles()->attach($role, [
            'tenant_id' => $this->tenant->getKey(),
            'granted_by' => $this->clinician->getKey(),
            'granted_at' => now(),
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

    public function test_dashboard_returns_only_the_active_clinic_day_and_audits_the_access(): void
    {
        app()->instance('currentTenant', $this->tenant);
        $patient = Patient::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'first_name' => 'Paciente',
            'last_name' => 'Operativo',
        ]);
        $type = AppointmentType::factory()->create(['tenant_id' => $this->tenant->getKey()]);
        $room = Room::factory()->create(['tenant_id' => $this->tenant->getKey(), 'clinic_id' => $this->clinic->getKey()]);
        $startsAt = now('Europe/Madrid')->startOfDay()->addHours(10)->utc();
        $endsAt = (clone $startsAt)->addMinutes(30);
        $appointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'clinic_id' => $this->clinic->getKey(),
            'room_id' => $room->getKey(),
            'appointment_type_id' => $type->getKey(),
            'patient_id' => $patient->getKey(),
            'clinician_user_id' => $this->clinician->getKey(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'created_by' => $this->clinician->getKey(),
            'updated_by' => $this->clinician->getKey(),
        ]);
        app()->forgetInstance('currentTenant');

        $this->actingAs($this->clinician);
        $response = $this->withSession($this->sessionContext())->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.clinic.id', $this->clinic->getKey())
            ->assertJsonPath('data.metrics.appointments', 1)
            ->assertJsonPath('data.dayAppointments.0.id', $appointment->getKey())
            ->assertJsonPath('data.dayAppointments.0.patientDisplayName', 'Paciente Operativo')
            ->assertJsonPath('data.dayAppointments.0.status', 'pending');
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $this->tenant->getKey(),
            'event_type' => 'dashboard.viewed',
            'actor_user_id' => $this->clinician->getKey(),
        ]);
    }

    /** @return array<string, int|string> */
    private function sessionContext(): array
    {
        return [
            'auth.session.absolute_expires_at' => time() + 3600,
            'auth.context.membership_id' => $this->membership->getKey(),
            'auth.context.clinic_id' => $this->clinic->getKey(),
        ];
    }
}
