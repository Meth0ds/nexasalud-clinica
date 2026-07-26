<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\ClinicalDocument;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientCoverage;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DocumentAndBillingDataTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('d', 32)));
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('currentTenant');
        parent::tearDown();
    }

    public function test_document_uses_opaque_storage_and_records_access_without_phi(): void
    {
        $tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $tenant);
        $patient = Patient::factory()->create(['tenant_id' => $tenant->getKey()]);
        $user = User::factory()->create();
        $document = ClinicalDocument::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'patient_id' => $patient->getKey(),
            'generated_by' => $user->getKey(),
            'storage_key' => 'clinical/opaque-object-key.pdf.enc',
        ]);
        $event = $document->recordAccess($user, 'clinical_document.downloaded', 'test-request');

        $this->assertModelExists($event);
        $this->assertSame(1, AuditEvent::query()->where('auditable_id', $document->getKey())->count());
        $this->assertArrayNotHasKey('storage_key', $document->toArray());
        $this->assertArrayNotHasKey('patient_id', $event->metadata);
    }

    public function test_public_document_url_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $tenant);
        $patient = Patient::factory()->create(['tenant_id' => $tenant->getKey()]);

        $this->expectException(DomainException::class);
        ClinicalDocument::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'patient_id' => $patient->getKey(),
            'storage_key' => 'https://public.example.test/document.pdf',
        ]);
    }

    public function test_coverage_and_billing_fields_are_encrypted_and_payment_schema_has_no_pan(): void
    {
        $tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $tenant);
        $patient = Patient::factory()->create(['tenant_id' => $tenant->getKey()]);
        $coverage = PatientCoverage::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'patient_id' => $patient->getKey(),
            'member_number' => 'SYN-MEMBER-PRIVATE',
            'member_number_hash' => hash('sha256', 'SYN-MEMBER-PRIVATE'),
        ]);
        $invoice = Invoice::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'patient_id' => $patient->getKey(),
            'patient_coverage_id' => $coverage->getKey(),
        ]);
        $payment = Payment::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'invoice_id' => $invoice->getKey(),
        ]);

        $rawMemberNumber = DB::table('patient_coverages')->where('id', $coverage->getKey())->value('member_number');
        $this->assertNotSame('SYN-MEMBER-PRIVATE', $rawMemberNumber);
        $this->assertSame(Patient::blindIndex($tenant->getKey(), 'SYN-MEMBER-PRIVATE'), $coverage->member_number_hash);
        $this->assertModelExists($payment);
        $this->assertFalse(Schema::hasColumn('payments', 'pan'));
        $this->assertFalse(Schema::hasColumn('payments', 'card_number'));
    }
}
