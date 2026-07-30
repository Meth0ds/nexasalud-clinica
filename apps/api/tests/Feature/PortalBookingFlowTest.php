<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\ClinicalDocument;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\PatientCommunication;
use App\Models\PatientUserLink;
use App\Models\Prescription;
use App\Models\Role;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
        config()->set('demo.portal_simulations', true);
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
        $booking->assertCreated()->assertJsonPath('data.patientId', $overview->json('data.patient.id'));

        $replay = $this->withSession($session)
            ->withHeader('Idempotency-Key', 'portal-booking-0001')
            ->postJson('/api/v1/portal/bookings', $bookingPayload);
        $replay->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

        $appointmentId = $booking->json('data.id');
        $version = $booking->json('data.version');
        $cancelled = $this->withSession($session)
            ->withHeader('Idempotency-Key', 'portal-cancel-000001')
            ->postJson('/api/v1/portal/appointments/'.$appointmentId.'/cancel', [
                'version' => $version,
                'reason' => 'Cambio de disponibilidad personal',
            ]);
        $cancelled->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('appointments', ['id' => $appointmentId, 'status' => 'cancelled']);
    }

    public function test_a_patient_can_read_only_their_queued_portal_messages(): void
    {
        app()->instance('currentTenant', $this->tenant);
        $link = PatientUserLink::query()->where('user_id', $this->patientUser->getKey())->firstOrFail();
        $visible = PatientCommunication::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $link->patient_id,
            'author_user_id' => $this->clinician->getKey(),
            'channel' => 'portal',
            'content_class' => 'clinical_link',
            'subject' => 'Información disponible',
            'body' => 'Revise la información disponible desde su portal seguro.',
            'status' => 'queued',
            'queued_at' => now(),
        ]);
        $otherPatient = Patient::factory()->create(['tenant_id' => $this->tenant->getKey()]);
        $hidden = PatientCommunication::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $otherPatient->getKey(),
            'author_user_id' => $this->clinician->getKey(),
            'channel' => 'portal',
            'status' => 'queued',
            'queued_at' => now(),
        ]);
        app()->forgetInstance('currentTenant');

        $this->actingAs($this->patientUser);
        $response = $this->withSession($this->sessionContext($this->patientMembership))
            ->getJson('/api/v1/portal/messages');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $visible->getKey())
            ->assertJsonPath('data.0.subject', 'Información disponible')
            ->assertJsonPath('data.0.body', 'Revise la información disponible desde su portal seguro.')
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['id' => $hidden->getKey()]);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $this->tenant->getKey(),
            'event_type' => 'portal.messages_viewed',
            'actor_user_id' => $this->patientUser->getKey(),
            'auditable_id' => $link->patient_id,
        ]);
    }

    public function test_a_patient_can_list_and_create_only_their_own_waitlist_entries(): void
    {
        app()->instance('currentTenant', $this->tenant);
        $link = PatientUserLink::query()->where('user_id', $this->patientUser->getKey())->firstOrFail();
        $visible = WaitlistEntry::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'clinic_id' => $this->clinic->getKey(),
            'patient_id' => $link->patient_id,
            'appointment_type_id' => $this->appointmentType->getKey(),
        ]);
        $hidden = WaitlistEntry::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'clinic_id' => $this->clinic->getKey(),
            'patient_id' => Patient::factory()->create(['tenant_id' => $this->tenant->getKey()])->getKey(),
            'appointment_type_id' => $this->appointmentType->getKey(),
        ]);
        app()->forgetInstance('currentTenant');

        $this->actingAs($this->patientUser);
        $session = $this->sessionContext($this->patientMembership);
        $listed = $this->withSession($session)->getJson('/api/v1/portal/waitlist-entries');
        $listed->assertOk()
            ->assertJsonPath('data.0.id', $visible->getKey())
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['id' => $hidden->getKey()]);

        $payload = [
            'clinicId' => $this->clinic->getKey(),
            'appointmentTypeId' => $this->appointmentType->getKey(),
            'desiredFrom' => now()->addDay()->toIso8601String(),
            'desiredTo' => now()->addDays(21)->toIso8601String(),
            'preferredDays' => [1, 3, 5],
            'notes' => 'Solicito una cita en horario de maÃ±ana.',
        ];
        $created = $this->withSession($session)
            ->withHeader('Idempotency-Key', 'portal-waitlist-create-0001')
            ->postJson('/api/v1/portal/waitlist-entries', $payload);
        $created->assertCreated()
            ->assertJsonPath('data.patientId', $link->patient_id)
            ->assertJsonPath('data.priority', 3)
            ->assertJsonPath('data.status', 'waiting');
        $this->assertDatabaseHas('waitlist_entries', [
            'patient_id' => $link->patient_id,
            'clinic_id' => $this->clinic->getKey(),
            'priority' => 3,
            'status' => 'waiting',
        ]);
    }

    public function test_a_patient_can_submit_one_simulated_medication_review_for_their_own_prescription(): void
    {
        app()->instance('currentTenant', $this->tenant);
        $link = PatientUserLink::query()->where('user_id', $this->patientUser->getKey())->firstOrFail();
        $prescription = Prescription::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'clinic_id' => $this->clinic->getKey(),
            'patient_id' => $link->patient_id,
            'prescriber_user_id' => $this->clinician->getKey(),
        ]);
        $otherPrescription = Prescription::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'clinic_id' => $this->clinic->getKey(),
            'patient_id' => Patient::factory()->create(['tenant_id' => $this->tenant->getKey()])->getKey(),
            'prescriber_user_id' => $this->clinician->getKey(),
        ]);
        app()->forgetInstance('currentTenant');

        $this->actingAs($this->patientUser);
        $session = $this->sessionContext($this->patientMembership);
        $created = $this->withSession($session)
            ->withHeader('Idempotency-Key', 'portal-demo-review-0001')
            ->postJson('/api/v1/portal/prescriptions/'.$prescription->getKey().'/demo-review-requests');

        $created->assertCreated()
            ->assertJsonPath('data.simulated', true)
            ->assertJsonPath('data.status', 'submitted');
        $this->assertDatabaseHas('portal_demo_requests', [
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $link->patient_id,
            'prescription_id' => $prescription->getKey(),
            'request_type' => 'medication_review',
            'status' => 'submitted',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $this->tenant->getKey(),
            'event_type' => 'portal.demo_medication_review_requested',
            'actor_user_id' => $this->patientUser->getKey(),
        ]);

        $repeated = $this->withSession($session)
            ->withHeader('Idempotency-Key', 'portal-demo-review-0002')
            ->postJson('/api/v1/portal/prescriptions/'.$prescription->getKey().'/demo-review-requests');
        $repeated->assertOk()->assertJsonPath('data.simulated', true);

        $this->withSession($session)
            ->withHeader('Idempotency-Key', 'portal-demo-review-0003')
            ->postJson('/api/v1/portal/prescriptions/'.$otherPrescription->getKey().'/demo-review-requests')
            ->assertNotFound();
    }

    public function test_a_patient_can_generate_and_download_a_simulated_non_fiscal_invoice_preview(): void
    {
        Storage::fake('clinical');
        app()->instance('currentTenant', $this->tenant);
        $link = PatientUserLink::query()->where('user_id', $this->patientUser->getKey())->firstOrFail();
        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'clinic_id' => $this->clinic->getKey(),
            'patient_id' => $link->patient_id,
        ]);
        InvoiceItem::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'invoice_id' => $invoice->getKey(),
        ]);
        app()->forgetInstance('currentTenant');

        $this->actingAs($this->patientUser);
        $session = $this->sessionContext($this->patientMembership);
        $created = $this->withSession($session)
            ->withHeader('Idempotency-Key', 'portal-demo-invoice-0001')
            ->postJson('/api/v1/portal/invoices/'.$invoice->getKey().'/demo-preview');

        $created->assertCreated()
            ->assertJsonPath('data.simulated', true)
            ->assertJsonPath('data.documentType', 'demo_invoice_preview');
        $documentId = $created->json('data.id');
        $this->assertIsString($documentId);
        $this->assertDatabaseHas('clinical_documents', [
            'id' => $documentId,
            'patient_id' => $link->patient_id,
            'document_type' => 'demo_invoice_preview',
        ]);

        $this->withSession($session)
            ->get('/api/v1/documents/'.$documentId.'/download')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $this->tenant->getKey(),
            'event_type' => 'portal.demo_invoice_preview_generated',
            'auditable_type' => ClinicalDocument::class,
            'auditable_id' => $documentId,
        ]);
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
