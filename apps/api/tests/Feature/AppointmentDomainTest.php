<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AppointmentDomainTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('currentTenant');
        parent::tearDown();
    }

    public function test_overlapping_clinician_or_room_is_rejected_and_adjacent_time_is_allowed(): void
    {
        [$tenant, $user, $clinic, $room, $type, $patient] = $this->clinicalContext();
        $start = now()->addDay()->startOfHour();
        $this->appointment($tenant, $user, $clinic, $room, $type, $patient, $start, $start->clone()->addMinutes(30));

        try {
            $this->appointment($tenant, $user, $clinic, $room, $type, Patient::factory()->create(['tenant_id' => $tenant->getKey()]), $start->clone()->addMinutes(10), $start->clone()->addMinutes(40));
            $this->fail('An overlapping appointment should be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('overlaps', $exception->getMessage());
        }

        $otherUser = User::factory()->create();
        $otherRoom = Room::factory()->create(['tenant_id' => $tenant->getKey(), 'clinic_id' => $clinic->getKey()]);

        try {
            $this->appointment($tenant, $otherUser, $clinic, $otherRoom, $type, $patient, $start->clone()->addMinutes(5), $start->clone()->addMinutes(25));
            $this->fail('A patient double-booking should be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('overlaps', $exception->getMessage());
        }

        $adjacent = $this->appointment($tenant, $user, $clinic, $room, $type, Patient::factory()->create(['tenant_id' => $tenant->getKey()]), $start->clone()->addMinutes(30), $start->clone()->addHour());
        $this->assertModelExists($adjacent);
    }

    public function test_status_changes_and_rescheduling_are_transactional_and_historicized(): void
    {
        [$tenant, $user, $clinic, $room, $type, $patient] = $this->clinicalContext();
        $start = now()->addDays(2)->startOfHour();
        $appointment = $this->appointment($tenant, $user, $clinic, $room, $type, $patient, $start, $start->clone()->addMinutes(30));

        $this->assertCount(1, $appointment->statusHistory()->get());
        $appointment = $appointment->transitionTo(AppointmentStatus::Confirmed, $user, 'Confirmación sintética');
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertCount(2, $appointment->statusHistory()->get());

        $appointment = $appointment->reschedule($start->clone()->addDay(), $start->clone()->addDay()->addMinutes(30), $user, $room, $user, 'Cambio sintético');
        $this->assertCount(1, $appointment->changeHistory()->get());
        $this->assertSame(3, $appointment->lock_version);

        $this->expectException(DomainException::class);
        $appointment->update(['status' => AppointmentStatus::Completed]);
    }

    /** @return array{Tenant, User, Clinic, Room, AppointmentType, Patient} */
    private function clinicalContext(): array
    {
        $tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $tenant);
        $user = User::factory()->create();
        $clinic = Clinic::factory()->create(['tenant_id' => $tenant->getKey()]);
        $room = Room::factory()->create(['tenant_id' => $tenant->getKey(), 'clinic_id' => $clinic->getKey()]);
        $type = AppointmentType::factory()->create(['tenant_id' => $tenant->getKey()]);
        $patient = Patient::factory()->create(['tenant_id' => $tenant->getKey()]);

        return [$tenant, $user, $clinic, $room, $type, $patient];
    }

    private function appointment(
        Tenant $tenant,
        User $user,
        Clinic $clinic,
        Room $room,
        AppointmentType $type,
        Patient $patient,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): Appointment {
        return Appointment::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'clinic_id' => $clinic->getKey(),
            'room_id' => $room->getKey(),
            'appointment_type_id' => $type->getKey(),
            'patient_id' => $patient->getKey(),
            'clinician_user_id' => $user->getKey(),
            'created_by' => $user->getKey(),
            'updated_by' => $user->getKey(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }
}
