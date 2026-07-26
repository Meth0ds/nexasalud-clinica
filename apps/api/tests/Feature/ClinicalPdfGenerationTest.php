<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\ClinicalDocumentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PrescriptionStatus;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Clinic;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Documents\ClinicalPdfGenerator;
use DomainException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use UnexpectedValueException;

class ClinicalPdfGenerationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private User $actor;

    private Patient $patient;

    private Clinic $clinic;

    private ClinicalPdfGenerator $generator;

    private Membership $membership;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('p', 32)));
        Storage::fake('clinical');

        $this->tenant = Tenant::factory()->create(['timezone' => 'Europe/Madrid']);
        app()->instance('currentTenant', $this->tenant);
        $this->actor = User::factory()->create(['display_name' => 'Dra. Ana Vega']);
        $this->patient = Patient::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'medical_record_number' => 'SYN-PDF-4821',
            'first_name' => 'Lucía',
            'last_name' => 'Martín Soler',
        ]);
        $this->clinic = Clinic::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'name' => 'Centro Médico Mediterráneo',
            'timezone' => 'Europe/Madrid',
        ]);
        $this->membership = Membership::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'user_id' => $this->actor->getKey(),
        ]);
        $role = Role::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'permissions' => ['appointments.read', 'patients.*', 'prescriptions.read', 'billing.read'],
        ]);
        $this->membership->roles()->attach($role->getKey(), [
            'tenant_id' => $this->tenant->getKey(),
            'granted_by' => $this->actor->getKey(),
            'granted_at' => now(),
        ]);
        app()->instance('currentMembership', $this->membership);
        $this->generator = app(ClinicalPdfGenerator::class);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('currentTenant');
        app()->forgetInstance('currentMembership');

        parent::tearDown();
    }

    public function test_it_generates_encrypts_and_verifies_an_appointment_confirmation(): void
    {
        $appointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $this->patient->getKey(),
            'clinic_id' => $this->clinic->getKey(),
            'clinician_user_id' => $this->actor->getKey(),
            'created_by' => $this->actor->getKey(),
            'updated_by' => $this->actor->getKey(),
        ]);

        $document = $this->generator->generateAppointmentConfirmation($appointment, $this->actor);

        $this->assertSame(ClinicalDocumentStatus::Ready, $document->status);
        $this->assertSame('appointment_confirmation', $document->document_type);
        $this->assertSame($appointment->getKey(), $document->appointment_id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $document->sha256);
        Storage::disk('clinical')->assertExists($document->storage_key);
        $this->assertFalse(str_starts_with(Storage::disk('clinical')->get($document->storage_key), '%PDF-'));
        $this->assertStringStartsWith('%PDF-', $this->generator->verifiedContents($document));
        $this->assertMatchesRegularExpression('/^confirmacion-cita-[0-9]{8}-[A-Z0-9]{10}\.pdf$/', $this->generator->safeDownloadName($document));
    }

    public function test_it_generates_a_signed_medication_plan_and_an_issued_invoice(): void
    {
        $prescription = Prescription::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $this->patient->getKey(),
            'clinic_id' => $this->clinic->getKey(),
            'prescriber_user_id' => $this->actor->getKey(),
        ]);
        PrescriptionItem::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'prescription_id' => $prescription->getKey(),
        ]);
        $prescription = $prescription
            ->transitionTo(PrescriptionStatus::PendingReview, $this->actor)
            ->transitionTo(PrescriptionStatus::Signed, $this->actor);

        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $this->patient->getKey(),
            'clinic_id' => $this->clinic->getKey(),
            'status' => InvoiceStatus::Issued,
            'issued_on' => today(),
        ]);
        InvoiceItem::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'invoice_id' => $invoice->getKey(),
        ]);

        $prescriptionDocument = $this->generator->generatePrescriptionPlan($prescription, $this->actor);
        $invoiceDocument = $this->generator->generateInvoice($invoice, $this->actor);

        $this->assertSame(ClinicalDocumentStatus::Ready, $prescriptionDocument->status);
        $this->assertSame($prescription->getKey(), $prescriptionDocument->prescription_id);
        $this->assertStringStartsWith('%PDF-', $this->generator->verifiedContents($prescriptionDocument));
        $this->assertSame(ClinicalDocumentStatus::Ready, $invoiceDocument->status);
        $this->assertStringStartsWith('%PDF-', $this->generator->verifiedContents($invoiceDocument));
    }

    public function test_it_rejects_a_tampered_encrypted_object(): void
    {
        $appointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $this->patient->getKey(),
            'clinic_id' => $this->clinic->getKey(),
            'clinician_user_id' => $this->actor->getKey(),
            'created_by' => $this->actor->getKey(),
            'updated_by' => $this->actor->getKey(),
        ]);
        $document = $this->generator->generateAppointmentConfirmation($appointment, $this->actor);
        Storage::disk('clinical')->put($document->storage_key, 'tampered-object');

        $this->expectException(UnexpectedValueException::class);

        $this->generator->verifiedContents($document);
    }

    public function test_download_is_integrity_checked_non_cacheable_and_audited(): void
    {
        $appointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $this->patient->getKey(),
            'clinic_id' => $this->clinic->getKey(),
            'clinician_user_id' => $this->actor->getKey(),
            'created_by' => $this->actor->getKey(),
            'updated_by' => $this->actor->getKey(),
        ]);
        $document = $this->generator->generateAppointmentConfirmation($appointment, $this->actor);
        $this->actingAs($this->actor);
        $this->assertTrue(Gate::forUser($this->actor)->allows('view', $document));

        $request = Request::create('/api/v1/documents/'.$document->getKey().'/download', 'GET');
        $request->setUserResolver(fn (): User => $this->actor);
        $request->attributes->set('trace_id', 'pdf-download-test');
        $response = app(DocumentController::class)->download($request, $document);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $this->tenant->getKey(),
            'actor_user_id' => $this->actor->getKey(),
            'event_type' => 'clinical_document.downloaded',
            'auditable_id' => $document->getKey(),
        ]);
        $this->assertSame(1, AuditEvent::query()->where('event_type', 'clinical_document.downloaded')->count());
    }

    public function test_it_rejects_documents_from_invalid_clinical_or_billing_states(): void
    {
        $cancelledAppointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $this->patient->getKey(),
            'clinic_id' => $this->clinic->getKey(),
            'clinician_user_id' => $this->actor->getKey(),
            'created_by' => $this->actor->getKey(),
            'updated_by' => $this->actor->getKey(),
            'status' => AppointmentStatus::Cancelled,
        ]);

        try {
            $this->generator->generateAppointmentConfirmation($cancelledAppointment, $this->actor);
            $this->fail('A cancelled appointment must not produce a confirmation.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $draftPrescription = Prescription::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $this->patient->getKey(),
            'clinic_id' => $this->clinic->getKey(),
            'prescriber_user_id' => $this->actor->getKey(),
        ]);

        try {
            $this->generator->generatePrescriptionPlan($draftPrescription, $this->actor);
            $this->fail('A draft prescription must not produce a medication plan.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $draftInvoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $this->patient->getKey(),
            'clinic_id' => $this->clinic->getKey(),
            'status' => InvoiceStatus::Draft,
            'issued_on' => null,
        ]);

        $this->expectException(DomainException::class);
        $this->generator->generateInvoice($draftInvoice, $this->actor);
    }
}
