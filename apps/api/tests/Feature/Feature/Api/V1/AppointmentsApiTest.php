<?php

declare(strict_types=1);

namespace Tests\Feature\Feature\Api\V1;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\AuditEvent;
use App\Models\Clinic;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\Role;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class AppointmentsApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private Clinic $clinic;

    private Room $room;

    private AppointmentType $appointmentType;

    private User $coordinator;

    private Membership $membership;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        config()->set('session.absolute_lifetime', 480);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withHeader('Origin', 'http://localhost:4200');

        $this->tenant = Tenant::factory()->create(['timezone' => 'Europe/Madrid']);
        app()->instance('currentTenant', $this->tenant);
        $this->clinic = Clinic::factory()->create(['tenant_id' => $this->tenant->getKey(), 'is_active' => true]);
        $this->room = Room::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'clinic_id' => $this->clinic->getKey(),
            'is_active' => true,
        ]);
        $this->appointmentType = AppointmentType::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'is_active' => true,
        ]);
        $this->coordinator = User::factory()->create(['is_active' => true]);
        $this->membership = Membership::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'user_id' => $this->coordinator->getKey(),
            'is_active' => true,
        ]);
        $role = Role::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'permissions' => ['appointments.read', 'appointments.write', 'appointments.cancel'],
        ]);
        $this->membership->roles()->attach($role, [
            'tenant_id' => $this->tenant->getKey(),
            'granted_by' => $this->coordinator->getKey(),
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

    public function test_the_list_is_tenant_scoped_and_preserves_the_paginated_appointment_contract(): void
    {
        $start = now()->addDay()->startOfHour();
        $first = $this->appointment($start);
        $second = $this->appointment($start->clone()->addHour());

        $otherTenant = Tenant::factory()->create();
        app()->instance('currentTenant', $otherTenant);
        $otherClinic = Clinic::factory()->create(['tenant_id' => $otherTenant->getKey()]);
        $otherAppointment = Appointment::factory()->create([
            'tenant_id' => $otherTenant->getKey(),
            'clinic_id' => $otherClinic->getKey(),
            'room_id' => Room::factory()->create([
                'tenant_id' => $otherTenant->getKey(),
                'clinic_id' => $otherClinic->getKey(),
            ])->getKey(),
            'appointment_type_id' => AppointmentType::factory()->create(['tenant_id' => $otherTenant->getKey()])->getKey(),
            'patient_id' => Patient::factory()->create(['tenant_id' => $otherTenant->getKey()])->getKey(),
            'clinician_user_id' => User::factory()->create()->getKey(),
            'created_by' => User::factory()->create()->getKey(),
            'updated_by' => User::factory()->create()->getKey(),
            'starts_at' => $start,
            'ends_at' => $start->clone()->addMinutes(30),
        ]);
        app()->forgetInstance('currentTenant');

        $response = $this->actingAs($this->coordinator)
            ->withSession($this->sessionContext())
            ->getJson('/api/v1/appointments?from='.$start->toDateString().'&to='.$start->clone()->addDays(2)->toDateString().'&perPage=10');

        $response->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $first->getKey())
            ->assertJsonPath('data.1.id', $second->getKey())
            ->assertJsonMissingPath('data.0.reason')
            ->assertJsonMissingPath('data.1.reason');
        $this->assertNotSame($otherAppointment->getKey(), $response->json('data.0.id'));
        $this->assertNotSame($otherAppointment->getKey(), $response->json('data.1.id'));
    }

    public function test_appointment_reason_is_only_returned_with_the_explicit_reason_permission(): void
    {
        $appointment = $this->appointment(now()->addDay()->startOfHour());
        $appointment->update(['reason' => 'Seguimiento clínico de dolor torácico.']);
        $this->actingAs($this->coordinator);

        $withoutReasonPermission = $this->withSession($this->sessionContext())
            ->getJson('/api/v1/appointments?from='.now()->toDateString().'&to='.now()->addDays(2)->toDateString());

        $withoutReasonPermission->assertOk()->assertJsonMissingPath('data.0.reasonSummary');

        app()->instance('currentTenant', $this->tenant);
        $role = $this->membership->roles()->firstOrFail();
        app()->forgetInstance('currentTenant');
        $role->update(['permissions' => ['appointments.read', 'appointments.reason.read']]);

        $withReasonPermission = $this->withSession($this->sessionContext())
            ->getJson('/api/v1/appointments?from='.now()->toDateString().'&to='.now()->addDays(2)->toDateString());

        $withReasonPermission->assertOk()->assertJsonPath('data.0.reasonSummary', 'Seguimiento clínico de dolor torácico.');
    }

    public function test_transition_records_audit_evidence_and_rejects_stale_or_invalid_lifecycle_changes(): void
    {
        $appointment = $this->appointment(now()->addDays(2)->startOfHour());
        $this->actingAs($this->coordinator);

        $payload = [
            'version' => 1,
            'status' => AppointmentStatus::Confirmed->value,
        ];
        $transitioned = $this->withSession($this->sessionContext())
            ->withHeader('Idempotency-Key', 'appointment-transition-confirm-01')
            ->postJson('/api/v1/appointments/'.$appointment->getKey().'/transition', [
                ...$payload,
            ]);
        $transitioned->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.version', 2);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $this->tenant->getKey(),
            'actor_user_id' => $this->coordinator->getKey(),
            'event_type' => 'appointment.status_changed',
            'auditable_id' => $appointment->getKey(),
        ]);

        $this->withSession($this->sessionContext())
            ->withHeader('Idempotency-Key', 'appointment-transition-confirm-01')
            ->postJson('/api/v1/appointments/'.$appointment->getKey().'/transition', $payload)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $stale = $this->withSession($this->sessionContext())
            ->withHeader('Idempotency-Key', 'appointment-transition-stale-001')
            ->postJson('/api/v1/appointments/'.$appointment->getKey().'/transition', [
                'version' => 1,
                'status' => AppointmentStatus::CheckedIn->value,
            ]);
        $stale->assertConflict()->assertJsonPath('type', '/problems/data-conflict');

        $invalid = $this->withSession($this->sessionContext())
            ->postJson('/api/v1/appointments/'.$appointment->getKey().'/transition', [
                'version' => 2,
                'status' => AppointmentStatus::Completed->value,
            ]);
        $invalid->assertConflict()->assertJsonPath('type', '/problems/clinical-conflict');
        $this->assertSame(1, AuditEvent::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $this->tenant->getKey())
            ->where('event_type', 'appointment.status_changed')
            ->count());
    }

    public function test_cross_tenant_appointments_are_not_bound_and_missing_write_permission_is_denied(): void
    {
        $otherTenant = Tenant::factory()->create();
        app()->instance('currentTenant', $otherTenant);
        $otherClinic = Clinic::factory()->create(['tenant_id' => $otherTenant->getKey()]);
        $otherAppointment = Appointment::factory()->create([
            'tenant_id' => $otherTenant->getKey(),
            'clinic_id' => $otherClinic->getKey(),
            'room_id' => Room::factory()->create([
                'tenant_id' => $otherTenant->getKey(),
                'clinic_id' => $otherClinic->getKey(),
            ])->getKey(),
            'appointment_type_id' => AppointmentType::factory()->create(['tenant_id' => $otherTenant->getKey()])->getKey(),
            'patient_id' => Patient::factory()->create(['tenant_id' => $otherTenant->getKey()])->getKey(),
            'clinician_user_id' => User::factory()->create()->getKey(),
            'created_by' => User::factory()->create()->getKey(),
            'updated_by' => User::factory()->create()->getKey(),
        ]);
        app()->forgetInstance('currentTenant');

        $this->actingAs($this->coordinator)
            ->withSession($this->sessionContext())
            ->getJson('/api/v1/appointments/'.$otherAppointment->getKey())
            ->assertNotFound()
            ->assertJsonPath('type', '/problems/not-found');

        $readOnlyUser = User::factory()->create(['is_active' => true]);
        app()->instance('currentTenant', $this->tenant);
        $readOnlyMembership = Membership::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'user_id' => $readOnlyUser->getKey(),
            'is_active' => true,
        ]);
        $readOnlyRole = Role::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'permissions' => ['appointments.read'],
        ]);
        $readOnlyMembership->roles()->attach($readOnlyRole, [
            'tenant_id' => $this->tenant->getKey(),
            'granted_by' => $this->coordinator->getKey(),
            'granted_at' => now(),
        ]);
        app()->forgetInstance('currentTenant');

        $appointment = $this->appointment(now()->addDays(3)->startOfHour());
        $this->actingAs($readOnlyUser)
            ->withSession($this->sessionContext($readOnlyMembership))
            ->withHeader('Idempotency-Key', 'appointment-transition-denied-01')
            ->postJson('/api/v1/appointments/'.$appointment->getKey().'/transition', [
                'version' => 1,
                'status' => AppointmentStatus::Confirmed->value,
            ])
            ->assertForbidden()
            ->assertJsonPath('type', '/problems/access-denied');
    }

    public function test_cancellation_requires_a_reason_and_replays_safely_without_duplicate_audit_events(): void
    {
        $appointment = $this->appointment(now()->addDays(4)->startOfHour());
        $this->actingAs($this->coordinator);

        $this->withSession($this->sessionContext())
            ->withHeader('Idempotency-Key', 'appointment-cancel-invalid-01')
            ->postJson('/api/v1/appointments/'.$appointment->getKey().'/cancel', ['version' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('type', '/problems/validation-failed');

        $payload = ['version' => 1, 'reason' => 'La persona ha solicitado cancelar la consulta.'];
        $cancelled = $this->withSession($this->sessionContext())
            ->withHeader('Idempotency-Key', 'appointment-cancel-valid-001')
            ->postJson('/api/v1/appointments/'.$appointment->getKey().'/cancel', $payload);
        $cancelled->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.version', 2);

        $replayed = $this->withSession($this->sessionContext())
            ->withHeader('Idempotency-Key', 'appointment-cancel-valid-001')
            ->postJson('/api/v1/appointments/'.$appointment->getKey().'/cancel', $payload);
        $replayed->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame(1, AuditEvent::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $this->tenant->getKey())
            ->where('event_type', 'appointment.cancelled')
            ->where('auditable_id', $appointment->getKey())
            ->count());
    }

    public function test_delete_alias_requires_reason_and_expected_version_before_cancelling(): void
    {
        $appointment = $this->appointment(now()->addDays(5)->startOfHour());
        $this->actingAs($this->coordinator);

        $this->withSession($this->sessionContext())
            ->deleteJson('/api/v1/appointments/'.$appointment->getKey(), ['version' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('type', '/problems/validation-failed');

        $cancelled = $this->withSession($this->sessionContext())
            ->deleteJson('/api/v1/appointments/'.$appointment->getKey(), [
                'version' => 1,
                'reason' => 'La persona solicita cancelar la consulta.',
            ]);

        $cancelled->assertNoContent();
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->getKey(),
            'status' => AppointmentStatus::Cancelled->value,
            'lock_version' => 2,
        ]);

        $this->withSession($this->sessionContext())
            ->deleteJson('/api/v1/appointments/'.$appointment->getKey(), [
                'version' => 1,
                'reason' => 'Intento de repetición con versión antigua.',
            ])
            ->assertConflict();
    }

    public function test_transition_to_cancelled_requires_the_dedicated_cancellation_permission(): void
    {
        $writeOnly = User::factory()->create(['is_active' => true]);
        $membership = Membership::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'user_id' => $writeOnly->getKey(),
            'is_active' => true,
        ]);
        $role = Role::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'permissions' => ['appointments.read', 'appointments.write'],
        ]);
        $membership->roles()->attach($role, [
            'tenant_id' => $this->tenant->getKey(),
            'granted_by' => $this->coordinator->getKey(),
            'granted_at' => now(),
        ]);
        $appointment = $this->appointment(now()->addDays(6)->startOfHour());

        $this->actingAs($writeOnly)
            ->withSession($this->sessionContext($membership))
            ->withHeader('Idempotency-Key', 'appointment-transition-cancel-denied')
            ->postJson('/api/v1/appointments/'.$appointment->getKey().'/transition', [
                'version' => 1,
                'status' => AppointmentStatus::Cancelled->value,
                'reason' => 'Cancelación no autorizada.',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->getKey(),
            'status' => AppointmentStatus::Scheduled->value,
            'lock_version' => 1,
        ]);
    }

    public function test_no_show_requires_a_reason_and_finalizes_the_appointment(): void
    {
        $appointment = $this->appointment(now()->addDays(4)->startOfHour());
        $this->actingAs($this->coordinator);

        $this->withSession($this->sessionContext())
            ->withHeader('Idempotency-Key', 'appointment-no-show-invalid-001')
            ->postJson('/api/v1/appointments/'.$appointment->getKey().'/transition', [
                'version' => 1,
                'status' => AppointmentStatus::NoShow->value,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('type', '/problems/validation-failed');

        $transitioned = $this->withSession($this->sessionContext())
            ->withHeader('Idempotency-Key', 'appointment-no-show-valid-0001')
            ->postJson('/api/v1/appointments/'.$appointment->getKey().'/transition', [
                'version' => 1,
                'status' => AppointmentStatus::NoShow->value,
                'reason' => 'La persona no ha acudido a la cita.',
            ]);
        $transitioned->assertOk()
            ->assertJsonPath('data.status', 'no-show')
            ->assertJsonPath('data.version', 2);
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->getKey(),
            'status' => AppointmentStatus::NoShow->value,
            'lock_version' => 2,
        ]);
    }

    public function test_rescheduling_into_an_occupied_resource_returns_a_clinical_conflict_without_changing_the_original_slot(): void
    {
        $appointment = $this->appointment(now()->addDays(5)->setTime(9, 0));
        $occupied = $this->appointment(now()->addDays(5)->setTime(11, 0));
        $this->actingAs($this->coordinator);

        $response = $this->withSession($this->sessionContext())
            ->withHeader('Idempotency-Key', 'appointment-reschedule-conflict-01')
            ->postJson('/api/v1/appointments/'.$appointment->getKey().'/reschedule', [
                'version' => 1,
                'startsAt' => $occupied->starts_at->toIso8601String(),
                'endsAt' => $occupied->ends_at->toIso8601String(),
                'timezone' => 'Europe/Madrid',
                'roomId' => $this->room->getKey(),
                'practitionerId' => $this->coordinator->getKey(),
                'reason' => 'Se ha solicitado un cambio de horario.',
            ]);

        $response->assertConflict()->assertJsonPath('type', '/problems/clinical-conflict');
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->getKey(),
            'lock_version' => 1,
            'starts_at' => $appointment->starts_at,
            'ends_at' => $appointment->ends_at,
        ]);
        $this->assertDatabaseMissing('appointment_change_histories', [
            'appointment_id' => $appointment->getKey(),
            'change_type' => 'rescheduled',
        ]);
    }

    private function appointment(CarbonInterface $startsAt): Appointment
    {
        app()->instance('currentTenant', $this->tenant);
        $appointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'clinic_id' => $this->clinic->getKey(),
            'room_id' => $this->room->getKey(),
            'appointment_type_id' => $this->appointmentType->getKey(),
            'patient_id' => Patient::factory()->create(['tenant_id' => $this->tenant->getKey()])->getKey(),
            'clinician_user_id' => $this->coordinator->getKey(),
            'created_by' => $this->coordinator->getKey(),
            'updated_by' => $this->coordinator->getKey(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->clone()->addMinutes(30),
        ]);
        app()->forgetInstance('currentTenant');

        return $appointment;
    }

    /** @return array<string, int|string> */
    private function sessionContext(?Membership $membership = null): array
    {
        return [
            'auth.session.absolute_expires_at' => time() + 3600,
            'auth.context.membership_id' => ($membership ?? $this->membership)->getKey(),
            'auth.context.clinic_id' => $this->clinic->getKey(),
        ];
    }
}
