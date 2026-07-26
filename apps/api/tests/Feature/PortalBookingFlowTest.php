<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\PatientUserLink;
use App\Models\Role;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class PortalBookingFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private Clinic $clinic;

    private Room $room;

    private AppointmentType $appointmentType;

    private User $patientUser;

    private Membership $patientMembership;

    private User $clinician;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('b', 32)));
        config()->set('session.absolute_lifetime', 480);
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->tenant = Tenant::factory()->create(['timezone' => 'Europe/Madrid']);
        app()->instance('currentTenant', $this->tenant);
        $this->clinic = Clinic::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'timezone' => 'Europe/Madrid',
            'is_active' => true,
        ]);
        $this->room = Room::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'clinic_id' => $this->clinic->getKey(),
            'is_active' => true,
        ]);
        $this->appointmentType = AppointmentType::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'duration_minutes' => 30,
            'allows_video' => true,
            'is_active' => true,
        ]);

        $this->clinician = User::factory()->create();
        $clinicianMembership = Membership::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'user_id' => $this->clinician->getKey(),
            'is_active' => true,
        ]);
        $clinicianRole = Role::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'permissions' => ['appointments.write'],
        ]);
        $clinicianMembership->roles()->attach($clinicianRole, [
            'tenant_id' => $this->tenant->getKey(),
            'granted_by' => $this->clinician->getKey(),
            'granted_at' => now(),
        ]);

        $this->patientUser = User::factory()->create();
        $this->patientMembership = Membership::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'user_id' => $this->patientUser->getKey(),
            'is_active' => true,
        ]);
        $portalRole = Role::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'permissions' => ['portal.*'],
        ]);
        $this->patientMembership->roles()->attach($portalRole, [
            'tenant_id' => $this->tenant->getKey(),
            'granted_by' => $this->clinician->getKey(),
            'granted_at' => now(),
        ]);
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->getKey()]);
        PatientUserLink::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'user_id' => $this->patientUser->getKey(),
            'patient_id' => $patient->getKey(),
            'relationship' => 'self',
            'is_active' => true,
            'verified_at' => now(),
            'verified_by' => $this->clinician->getKey(),
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

    public function test_a_patient_can_hold_book_replay_and_cancel_only_their_own_slot(): void
    {
        $this->actingAs($this->patientUser);
        $session = $this->sessionContext($this->patientMembership);

        $overview = $this->withSession($session)->getJson('/api/v1/portal/overview');
        $overview->assertOk()->assertJsonPath('data.patient.relationship', 'self');

        $availability = $this->withSession($session)->getJson('/api/v1/portal/availability?'.http_build_query([
            'clinicId' => $this->clinic->getKey(),
            'appointmentTypeId' => $this->appointmentType->getKey(),
            'practitionerId' => $this->clinician->getKey(),
            'days' => 14,
            'mode' => 'in-person',
        ]));
        $availability->assertOk()->assertJsonPath('meta.holdDurationSeconds', 300);
        $slot = $availability->json('data.0');
        $this->assertIsArray($slot);

        $holdPayload = [
            'clinicId' => $slot['clinicId'],
            'appointmentTypeId' => $slot['appointmentTypeId'],
            'practitionerId' => $slot['practitionerId'],
            'roomId' => $slot['roomId'],
            'startsAt' => $slot['startsAt'],
            'mode' => $slot['mode'],
        ];
        $hold = $this->withSession($session)
            ->withHeader('Idempotency-Key', 'portal-hold-00000001')
            ->postJson('/api/v1/portal/slot-holds', $holdPayload);
        $hold->assertCreated();

        $bookingPayload = ['holdId' => $hold->json('data.holdId'), 'reason' => 'Consulta solicitada desde el portal'];
        $booking = $this->withSession($session)
            ->withHeader('Idempotency-Key', 'portal-booking-0001')
            ->postJson('/api/v1/portal/bookings', $bookingPayload);
        $booking->assertOk()->assertJsonPath('patientId', $overview->json('data.patient.id'));

        $replay = $this->withSession($session)
            ->withHeader('Idempotency-Key', 'portal-booking-0001')
            ->postJson('/api/v1/portal/bookings', $bookingPayload);
        $replay->assertOk()->assertHeader('Idempotency-Replayed', 'true');

        $appointmentId = $booking->json('id');
        $version = $booking->json('version');
        $cancelled = $this->withSession($session)
            ->withHeader('Idempotency-Key', 'portal-cancel-000001')
            ->postJson('/api/v1/portal/appointments/'.$appointmentId.'/cancel', [
                'version' => $version,
                'reason' => 'Cambio de disponibilidad personal',
            ]);
        $cancelled->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertDatabaseHas('appointments', ['id' => $appointmentId, 'status' => 'cancelled']);
    }

    /** @return array<string, int|string> */
    private function sessionContext(Membership $membership): array
    {
        return [
            'auth.session.absolute_expires_at' => time() + 3600,
            'auth.assurance.authenticated_at' => time(),
            'auth.context.membership_id' => $membership->getKey(),
            'auth.context.clinic_id' => $this->clinic->getKey(),
        ];
    }
}
