<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PrescriptionStatus;
use App\Models\Clinic;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class PrescriptionSignatureSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private Clinic $clinic;

    private User $clinician;

    private Membership $membership;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
        config()->set('session.absolute_lifetime', 480);
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->tenant = Tenant::factory()->create(['timezone' => 'Europe/Madrid']);
        app()->instance('currentTenant', $this->tenant);
        $this->clinic = Clinic::factory()->create(['tenant_id' => $this->tenant->getKey(), 'is_active' => true]);
        $this->clinician = User::factory()->create(['is_active' => true]);
        $this->membership = Membership::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'user_id' => $this->clinician->getKey(),
            'is_active' => true,
        ]);
        $role = Role::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'permissions' => ['prescriptions.read', 'prescriptions.write', 'prescriptions.sign'],
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

    public function test_signature_with_a_matching_allergy_requires_a_meaningful_override_reason(): void
    {
        $prescription = $this->pendingPrescriptionWithAllergies();
        $this->actingAs($this->clinician);

        $missingReason = $this->withSession($this->recentMfaContext())
            ->postJson('/api/v1/prescriptions/'.$prescription->getKey().'/transition', [
                'version' => $prescription->lock_version,
                'status' => 'signed',
            ]);
        $missingReason->assertConflict();

        $shortReason = $this->withSession($this->recentMfaContext())
            ->postJson('/api/v1/prescriptions/'.$prescription->getKey().'/transition', [
                'version' => $prescription->lock_version,
                'status' => 'signed',
                'reason' => 'Corto',
            ]);
        $shortReason->assertConflict();

        $approved = $this->withSession($this->recentMfaContext())
            ->postJson('/api/v1/prescriptions/'.$prescription->getKey().'/transition', [
                'version' => $prescription->lock_version,
                'status' => 'signed',
                'reason' => 'Balance terapéutico revisado por la especialista.',
            ]);

        $approved->assertOk()
            ->assertJsonPath('data.status', 'signed')
            ->assertJsonCount(1, 'data.safetyAlerts');
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $this->tenant->getKey(),
            'event_type' => 'prescription.status_changed',
            'actor_user_id' => $this->clinician->getKey(),
        ]);
    }

    private function pendingPrescriptionWithAllergies(): Prescription
    {
        app()->instance('currentTenant', $this->tenant);
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->getKey()]);
        $prescription = Prescription::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'clinic_id' => $this->clinic->getKey(),
            'patient_id' => $patient->getKey(),
            'prescriber_user_id' => $this->clinician->getKey(),
        ]);
        PrescriptionItem::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'prescription_id' => $prescription->getKey(),
            'medication_code' => 'CIMA-0001',
        ]);
        PatientAllergy::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $patient->getKey(),
            'substance_code' => 'CIMA-0001',
        ]);
        PatientAllergy::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $patient->getKey(),
            'substance_code' => 'CIMA-NO-COINCIDE',
        ]);
        $pending = $prescription->transitionTo(PrescriptionStatus::PendingReview, $this->clinician);
        app()->forgetInstance('currentTenant');

        return $pending;
    }

    /** @return array<string, int|string|array<int, string>> */
    private function recentMfaContext(): array
    {
        return [
            'auth.session.absolute_expires_at' => time() + 3600,
            'auth.context.membership_id' => $this->membership->getKey(),
            'auth.context.clinic_id' => $this->clinic->getKey(),
            'auth.assurance.amr' => ['otp'],
            'auth.assurance.authenticated_at' => now()->getTimestamp(),
        ];
    }
}
