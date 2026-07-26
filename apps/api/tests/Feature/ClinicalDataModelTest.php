<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Patient;
use App\Models\Tenant;
use DomainException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClinicalDataModelTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('currentTenant');
        parent::tearDown();
    }

    public function test_patient_phi_is_encrypted_and_hidden_by_default(): void
    {
        $tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $tenant);
        $patient = Patient::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'medical_record_hash' => hash('sha256', 'SYN-000001'),
            'medical_record_number' => 'SYN-000001',
            'first_name' => 'Lucía Sintética',
            'last_name' => 'Prueba',
            'clinical_notes' => 'Contenido clínico sintético.',
        ]);

        $raw = DB::table('patients')->where('id', $patient->getKey())->firstOrFail();

        $this->assertNotSame('Lucía Sintética', $raw->first_name);
        $this->assertNotSame('SYN-000001', $raw->medical_record_number);
        $this->assertNotSame(hash('sha256', 'SYN-000001'), $raw->medical_record_hash);
        $this->assertSame(Patient::blindIndex($tenant->getKey(), 'SYN-000001'), $raw->medical_record_hash);
        $this->assertSame('Lucía Sintética', $patient->first_name);
        $this->assertArrayNotHasKey('first_name', $patient->toArray());
        $this->assertArrayNotHasKey('clinical_notes', $patient->toArray());
    }

    public function test_tenant_scope_fails_closed_and_never_leaks_another_tenant(): void
    {
        $firstTenant = Tenant::factory()->create();
        $secondTenant = Tenant::factory()->create();
        app()->instance('currentTenant', $firstTenant);
        $firstPatient = Patient::factory()->create(['tenant_id' => $firstTenant->getKey()]);

        app()->instance('currentTenant', $secondTenant);
        $secondPatient = Patient::factory()->create(['tenant_id' => $secondTenant->getKey()]);

        app()->instance('currentTenant', $firstTenant);
        $this->assertSame([$firstPatient->getKey()], Patient::query()->pluck('id')->all());
        $this->assertSame(2, Patient::withoutGlobalScope('tenant')->count());

        app()->forgetInstance('currentTenant');
        $this->assertSame(0, Patient::query()->count());
        $this->assertNotSame($firstPatient->getKey(), $secondPatient->getKey());
    }

    public function test_audit_events_are_append_only_in_eloquent(): void
    {
        $tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $tenant);
        $event = AuditEvent::factory()->create(['tenant_id' => $tenant->getKey()]);

        $this->expectException(DomainException::class);
        $event->update(['event_type' => 'tampered']);
    }
}
