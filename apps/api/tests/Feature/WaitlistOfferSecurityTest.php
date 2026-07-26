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
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class WaitlistOfferSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private Clinic $clinic;

    private AppointmentType $appointmentType;

    private User $coordinator;

    private Membership $membership;

    private WaitlistEntry $entry;

    private Appointment $matchingAppointment;

    private Appointment $otherPatientAppointment;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('w', 32)));
        config()->set('session.absolute_lifetime', 480);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withHeader('Origin', 'http://localhost:4200');

        $this->tenant = Tenant::factory()->create(['timezone' => 'Europe/Madrid']);
        app()->instance('currentTenant', $this->tenant);
        $this->clinic = Clinic::factory()->create(['tenant_id' => $this->tenant->getKey(), 'is_active' => true]);
        $this->appointmentType = AppointmentType::factory()->create(['tenant_id' => $this->tenant->getKey(), 'is_active' => true]);
        $room = Room::factory()->create(['tenant_id' => $this->tenant->getKey(), 'clinic_id' => $this->clinic->getKey(), 'is_active' => true]);
        $this->coordinator = User::factory()->create(['is_active' => true]);
        $this->membership = Membership::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'user_id' => $this->coordinator->getKey(),
            'is_active' => true,
        ]);
        $role = Role::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'permissions' => ['appointments.read', 'appointments.write'],
        ]);
        $this->membership->roles()->attach($role, [
            'tenant_id' => $this->tenant->getKey(),
            'granted_by' => $this->coordinator->getKey(),
            'granted_at' => now(),
        ]);

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->getKey()]);
        $this->entry = WaitlistEntry::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'clinic_id' => $this->clinic->getKey(),
            'patient_id' => $patient->getKey(),
            'appointment_type_id' => $this->appointmentType->getKey(),
        ]);
        $this->matchingAppointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'clinic_id' => $this->clinic->getKey(),
            'room_id' => $room->getKey(),
            'appointment_type_id' => $this->appointmentType->getKey(),
            'patient_id' => $patient->getKey(),
            'clinician_user_id' => $this->coordinator->getKey(),
            'created_by' => $this->coordinator->getKey(),
            'updated_by' => $this->coordinator->getKey(),
        ]);
        $this->otherPatientAppointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'clinic_id' => $this->clinic->getKey(),
            'room_id' => Room::factory()->create([
                'tenant_id' => $this->tenant->getKey(),
                'clinic_id' => $this->clinic->getKey(),
                'is_active' => true,
            ])->getKey(),
            'appointment_type_id' => $this->appointmentType->getKey(),
            'patient_id' => Patient::factory()->create(['tenant_id' => $this->tenant->getKey()])->getKey(),
            'clinician_user_id' => User::factory()->create()->getKey(),
            'created_by' => $this->coordinator->getKey(),
            'updated_by' => $this->coordinator->getKey(),
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

    public function test_an_offer_cannot_link_an_appointment_for_a_different_patient_and_replays_safely(): void
    {
        $this->actingAs($this->coordinator);
        $session = [
            'auth.session.absolute_expires_at' => time() + 3600,
            'auth.context.membership_id' => $this->membership->getKey(),
            'auth.context.clinic_id' => $this->clinic->getKey(),
        ];

        $invalid = $this->withSession($session)
            ->withHeader('Idempotency-Key', 'waitlist-offer-invalid-0001')
            ->postJson('/api/v1/waitlist-entries/'.$this->entry->getKey().'/offer', [
                'version' => $this->entry->lock_version,
                'appointmentId' => $this->otherPatientAppointment->getKey(),
                'expiresInMinutes' => 20,
            ]);
        $invalid->assertUnprocessable();
        $this->assertDatabaseHas('waitlist_entries', ['id' => $this->entry->getKey(), 'status' => 'waiting']);

        $payload = [
            'version' => $this->entry->lock_version,
            'appointmentId' => $this->matchingAppointment->getKey(),
            'expiresInMinutes' => 20,
        ];
        $offered = $this->withSession($session)
            ->withHeader('Idempotency-Key', 'waitlist-offer-valid-00001')
            ->postJson('/api/v1/waitlist-entries/'.$this->entry->getKey().'/offer', $payload);
        $offered->assertOk()
            ->assertJsonPath('data.status', 'offered')
            ->assertJsonPath('data.version', 2);

        $replay = $this->withSession($session)
            ->withHeader('Idempotency-Key', 'waitlist-offer-valid-00001')
            ->postJson('/api/v1/waitlist-entries/'.$this->entry->getKey().'/offer', $payload);
        $replay->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    }
}
