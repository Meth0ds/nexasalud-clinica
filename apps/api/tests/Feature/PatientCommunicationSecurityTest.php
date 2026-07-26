<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ConsentRecord;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\PatientCommunication;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PatientCommunicationSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Tenant $tenant;

    private Clinic $clinic;

    private User $coordinator;

    private Membership $membership;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('c', 32)));
        config()->set('session.absolute_lifetime', 480);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withHeader('Origin', 'http://localhost:4200');

        $this->tenant = Tenant::factory()->create(['timezone' => 'Europe/Madrid']);
        app()->instance('currentTenant', $this->tenant);
        $this->clinic = Clinic::factory()->create(['tenant_id' => $this->tenant->getKey(), 'is_active' => true]);
        $this->coordinator = User::factory()->create(['is_active' => true]);
        $this->membership = Membership::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'user_id' => $this->coordinator->getKey(),
            'is_active' => true,
        ]);
        $role = Role::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'permissions' => [
                'communications.read',
                'communications.write',
                'communications.clinical.read',
                'communications.clinical.write',
            ],
        ]);
        $this->membership->roles()->attach($role, [
            'tenant_id' => $this->tenant->getKey(),
            'granted_by' => $this->coordinator->getKey(),
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

    public function test_portal_messages_are_encrypted_and_can_only_queue_with_current_channel_consent(): void
    {
        $this->actingAs($this->coordinator);
        $payload = [
            'patientId' => $this->patient->getKey(),
            'channel' => 'portal',
            'contentClass' => 'clinical_link',
            'subject' => 'Plan de seguimiento disponible',
            'body' => 'Consulta el documento clínico desde el portal seguro del centro.',
            'mode' => 'queue',
        ];

        $withoutConsent = $this->withSession($this->sessionContext())
            ->withHeader('Idempotency-Key', 'communication-no-consent-0001')
            ->postJson('/api/v1/communications', $payload);
        $withoutConsent->assertUnprocessable();

        app()->instance('currentTenant', $this->tenant);
        $consent = ConsentRecord::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $this->patient->getKey(),
            'recorded_by' => $this->coordinator->getKey(),
            'consent_type' => 'communications.portal',
            'status' => 'active',
            'granted_at' => now()->subMinute(),
        ]);
        app()->forgetInstance('currentTenant');

        $queued = $this->withSession($this->sessionContext())
            ->withHeader('Idempotency-Key', 'communication-portal-queue-01')
            ->postJson('/api/v1/communications', $payload);
        $queued->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.channel', 'portal')
            ->assertJsonPath('data.patientId', $this->patient->getKey());

        $communicationId = (string) $queued->json('data.id');
        $rawBody = DB::table('patient_communications')->where('id', $communicationId)->value('body');
        $this->assertNotSame($payload['body'], $rawBody);
        $this->assertDatabaseHas('patient_communications', [
            'id' => $communicationId,
            'consent_record_id' => $consent->getKey(),
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $this->tenant->getKey(),
            'event_type' => 'communication.queued',
            'actor_user_id' => $this->coordinator->getKey(),
        ]);
    }

    public function test_external_channels_reject_free_text_and_only_accept_consented_generic_templates(): void
    {
        $this->actingAs($this->coordinator);
        $invalidPayload = [
            'patientId' => $this->patient->getKey(),
            'channel' => 'sms',
            'contentClass' => 'clinical_link',
            'subject' => 'Resultado clínico',
            'body' => 'El resultado contiene información sensible.',
            'mode' => 'queue',
        ];

        $invalid = $this->withSession($this->sessionContext())
            ->withHeader('Idempotency-Key', 'communication-sms-reject-001')
            ->postJson('/api/v1/communications', $invalidPayload);
        $invalid->assertUnprocessable();

        app()->instance('currentTenant', $this->tenant);
        ConsentRecord::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $this->patient->getKey(),
            'recorded_by' => $this->coordinator->getKey(),
            'consent_type' => 'communications.sms',
            'status' => 'active',
            'granted_at' => now()->subMinute(),
        ]);
        app()->forgetInstance('currentTenant');

        $allowed = $this->withSession($this->sessionContext())
            ->withHeader('Idempotency-Key', 'communication-sms-template-01')
            ->postJson('/api/v1/communications', [
                'patientId' => $this->patient->getKey(),
                'channel' => 'sms',
                'contentClass' => 'administrative',
                'templateKey' => 'appointment_reminder',
                'mode' => 'queue',
            ]);
        $allowed->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.subject', 'Recordatorio de cita')
            ->assertJsonPath('data.preview', 'Aviso administrativo genérico sin datos clínicos.');
    }

    public function test_draft_queueing_honors_optimistic_versioning_and_safe_idempotent_replay(): void
    {
        app()->instance('currentTenant', $this->tenant);
        $communication = PatientCommunication::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $this->patient->getKey(),
            'author_user_id' => $this->coordinator->getKey(),
        ]);
        ConsentRecord::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $this->patient->getKey(),
            'recorded_by' => $this->coordinator->getKey(),
            'consent_type' => 'communications.portal',
            'status' => 'active',
            'granted_at' => now()->subMinute(),
        ]);
        app()->forgetInstance('currentTenant');

        $this->actingAs($this->coordinator);
        $stale = $this->withSession($this->sessionContext())
            ->withHeader('Idempotency-Key', 'communication-draft-stale-01')
            ->postJson('/api/v1/communications/'.$communication->getKey().'/queue', ['version' => 2]);
        $stale->assertConflict();

        $payload = ['version' => 1];
        $queued = $this->withSession($this->sessionContext())
            ->withHeader('Idempotency-Key', 'communication-draft-queue-01')
            ->postJson('/api/v1/communications/'.$communication->getKey().'/queue', $payload);
        $queued->assertOk()->assertJsonPath('data.version', 2);

        $replay = $this->withSession($this->sessionContext())
            ->withHeader('Idempotency-Key', 'communication-draft-queue-01')
            ->postJson('/api/v1/communications/'.$communication->getKey().'/queue', $payload);
        $replay->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    }

    public function test_administrative_users_cannot_list_or_target_clinical_portal_communications(): void
    {
        app()->instance('currentTenant', $this->tenant);
        $clinical = PatientCommunication::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $this->patient->getKey(),
            'author_user_id' => $this->coordinator->getKey(),
            'content_class' => 'clinical_link',
        ]);
        $administrative = PatientCommunication::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'patient_id' => $this->patient->getKey(),
            'author_user_id' => $this->coordinator->getKey(),
            'content_class' => 'administrative',
        ]);
        $receptionist = User::factory()->create(['is_active' => true]);
        $receptionMembership = Membership::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'user_id' => $receptionist->getKey(),
            'is_active' => true,
        ]);
        $receptionRole = Role::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'permissions' => ['communications.read', 'communications.write'],
        ]);
        $receptionMembership->roles()->attach($receptionRole, [
            'tenant_id' => $this->tenant->getKey(),
            'granted_by' => $this->coordinator->getKey(),
            'granted_at' => now(),
        ]);
        app()->forgetInstance('currentTenant');

        $this->actingAs($receptionist);
        $this->withSession($this->sessionContext($receptionMembership))
            ->getJson('/api/v1/communications')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $administrative->getKey());

        $this->withSession($this->sessionContext($receptionMembership))
            ->getJson('/api/v1/communications/'.$clinical->getKey())
            ->assertForbidden();

        $this->withSession($this->sessionContext($receptionMembership))
            ->withHeader('Idempotency-Key', 'communication-clinical-denied-01')
            ->postJson('/api/v1/communications', [
                'patientId' => $this->patient->getKey(),
                'channel' => 'portal',
                'contentClass' => 'clinical_link',
                'subject' => 'Aviso clínico',
                'body' => 'Contenido sensible limitado al equipo clínico.',
                'mode' => 'draft',
            ])
            ->assertForbidden();
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
