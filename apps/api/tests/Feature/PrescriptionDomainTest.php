<?php

namespace Tests\Feature;

use App\Enums\PrescriptionStatus;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Tenant;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrescriptionDomainTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('p', 32)));
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('currentTenant');
        parent::tearDown();
    }

    public function test_prescription_requires_items_before_signing_and_freezes_items_after_signature(): void
    {
        [$tenant, $user, $prescription] = $this->prescriptionContext();
        $item = PrescriptionItem::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'prescription_id' => $prescription->getKey(),
        ]);
        $prescription = $prescription->transitionTo(PrescriptionStatus::PendingReview, $user);
        $prescription = $prescription->transitionTo(PrescriptionStatus::Signed, $user);

        $this->assertNotNull($prescription->signed_at);
        $this->assertCount(3, $prescription->statusHistory()->get());

        $this->expectException(DomainException::class);
        $item->update(['dose_amount' => 2]);
    }

    public function test_empty_prescription_cannot_be_signed(): void
    {
        [, $user, $prescription] = $this->prescriptionContext();
        $prescription = $prescription->transitionTo(PrescriptionStatus::PendingReview, $user);

        $this->expectException(DomainException::class);
        $prescription->transitionTo(PrescriptionStatus::Signed, $user);
    }

    public function test_allergy_free_text_is_encrypted_at_rest(): void
    {
        [$tenant, $user, $prescription] = $this->prescriptionContext();
        $allergy = PatientAllergy::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'patient_id' => $prescription->patient_id,
            'recorded_by' => $user->getKey(),
            'substance_display' => 'Sustancia sintética privada',
        ]);

        $raw = DB::table('patient_allergies')->where('id', $allergy->getKey())->value('substance_display');
        $this->assertNotSame('Sustancia sintética privada', $raw);
        $this->assertSame('Sustancia sintética privada', $allergy->substance_display);
    }

    /** @return array{Tenant, User, Prescription} */
    private function prescriptionContext(): array
    {
        $tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $tenant);
        $user = User::factory()->create();
        $patient = Patient::factory()->create(['tenant_id' => $tenant->getKey()]);
        $clinic = Clinic::factory()->create(['tenant_id' => $tenant->getKey()]);
        $prescription = Prescription::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'clinic_id' => $clinic->getKey(),
            'patient_id' => $patient->getKey(),
            'prescriber_user_id' => $user->getKey(),
        ]);

        return [$tenant, $user, $prescription];
    }
}
