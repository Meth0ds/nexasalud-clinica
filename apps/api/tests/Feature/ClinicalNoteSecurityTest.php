<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ClinicalNote;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ClinicalNoteSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private Clinic $clinic;

    private User $clinician;

    private Membership $membership;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('n', 32)));
        config()->set('session.absolute_lifetime', 480);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withHeader('Origin', 'http://localhost:4200');

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
            'permissions' => ['clinical_notes.*'],
        ]);
        $this->membership->roles()->attach($role, [
            'tenant_id' => $this->tenant->getKey(),
            'granted_by' => $this->clinician->getKey(),
            'granted_at' => now(),
        ]);
        $this->patient = Patient::factory()->create(['tenant_id' => $this->tenant->getKey()]);
        app()->forgetInstance('currentTenant');
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('currentClinic');
        app()->forgetInstance('currentMembership');
        app()->forgetInstance('currentTenant');

        parent::tearDown();
    }

    public function test_notes_are_encrypted_append_only_and_visible_only_through_an_authorized_patient_context(): void
    {
        $this->actingAs($this->clinician);
        $response = $this->withSession($this->sessionContext())
            ->withHeader('Idempotency-Key', 'clinical-note-draft-0001')
            ->postJson('/api/v1/patients/'.$this->patient->getKey().'/clinical-notes', [
                'content' => 'Seguimiento clínico estructurado y relevante para la atención.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.patientId', $this->patient->getKey());
        $noteId = (string) $response->json('data.id');
        $raw = DB::table('clinical_notes')->where('id', $noteId)->value('content');
        $this->assertNotSame('Seguimiento clínico estructurado y relevante para la atención.', $raw);

        app()->instance('currentTenant', $this->tenant);
        $note = ClinicalNote::query()->findOrFail($noteId);
        $note->content = 'Alteración no autorizada.';
        $this->expectException(DomainException::class);
        $note->save();
    }

    public function test_signing_requires_recent_mfa_and_creates_an_immutable_signed_note(): void
    {
        $this->actingAs($this->clinician);
        $payload = ['content' => 'Valoración firmada tras revisar el plan terapéutico y los riesgos.'];

        $stale = $this->withSession($this->sessionContext([
            'auth.assurance.amr' => ['otp'],
            'auth.assurance.authenticated_at' => now()->subMinutes(16)->getTimestamp(),
        ]))
            ->withHeader('Idempotency-Key', 'clinical-note-sign-stale')
            ->postJson('/api/v1/patients/'.$this->patient->getKey().'/clinical-notes/sign', $payload);
        $stale->assertForbidden();

        $signed = $this->withSession($this->sessionContext([
            'auth.assurance.amr' => ['otp'],
            'auth.assurance.authenticated_at' => now()->getTimestamp(),
        ]))
            ->withHeader('Idempotency-Key', 'clinical-note-sign-current')
            ->postJson('/api/v1/patients/'.$this->patient->getKey().'/clinical-notes/sign', $payload);

        $signed->assertCreated()
            ->assertJsonPath('data.status', 'signed')
            ->assertJsonPath('data.content', $payload['content']);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $this->tenant->getKey(),
            'event_type' => 'clinical_note.signed',
            'actor_user_id' => $this->clinician->getKey(),
        ]);
    }

    public function test_operational_patient_write_access_does_not_grant_clinical_note_creation(): void
    {
        $receptionist = User::factory()->create(['is_active' => true]);
        $membership = Membership::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'user_id' => $receptionist->getKey(),
            'is_active' => true,
        ]);
        $role = Role::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'permissions' => ['patients.read', 'patients.write'],
        ]);
        $membership->roles()->attach($role, [
            'tenant_id' => $this->tenant->getKey(),
            'granted_by' => $this->clinician->getKey(),
            'granted_at' => now(),
        ]);

        $this->actingAs($receptionist)
            ->withSession($this->sessionContextFor($membership))
            ->withHeader('Idempotency-Key', 'clinical-note-reception-denied')
            ->postJson('/api/v1/patients/'.$this->patient->getKey().'/clinical-notes', [
                'content' => 'Contenido clínico que no debe crear recepción.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('clinical_notes', 0);
    }

    /** @param array<string, int|string|array<int, string>> $assurance */
    private function sessionContext(array $assurance = []): array
    {
        return [
            'auth.session.absolute_expires_at' => time() + 3600,
            'auth.context.membership_id' => $this->membership->getKey(),
            'auth.context.clinic_id' => $this->clinic->getKey(),
            ...$assurance,
        ];
    }

    /** @return array<string, int|string> */
    private function sessionContextFor(Membership $membership): array
    {
        return [
            'auth.session.absolute_expires_at' => time() + 3600,
            'auth.context.membership_id' => $membership->getKey(),
            'auth.context.clinic_id' => $this->clinic->getKey(),
        ];
    }
}
