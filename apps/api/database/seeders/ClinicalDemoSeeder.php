<?php

namespace Database\Seeders;

use App\Models\AdverseReaction;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\ClinicalDocument;
use App\Models\ClinicPatient;
use App\Models\ConsentRecord;
use App\Models\Insurer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Membership;
use App\Models\MembershipClinic;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\PatientCoverage;
use App\Models\PatientUserLink;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Role;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserPreference;
use App\Models\WaitlistEntry;
use App\Services\Identity\LocalhostCurlResolver;
use GuzzleHttp\Client;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Throwable;

class ClinicalDemoSeeder extends Seeder
{
    private const DATASET_VERSION = 2;

    private const TENANT_ID = '01KXVSN5BZK269J2HZCD53EVZ0';

    /**
     * Seed a deterministic, synthetic local dataset aligned with the Keycloak realm.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new LogicException('The synthetic clinical demo seeder may only run in local or testing environments.');
        }

        $issuer = config('services.keycloak.issuer');

        if (! is_string($issuer) || $issuer === '') {
            throw new LogicException('OIDC_ISSUER must be configured before seeding Keycloak-aligned demo identities.');
        }

        $this->assertSchemaReady();

        try {
            DB::transaction(function () use ($issuer): void {
                $tenant = $this->upsertTenant();
                $this->setDatabaseTenantContext($tenant->getKey());
                app()->instance('currentTenant', $tenant);

                $users = $this->upsertUsers($issuer);
                $roles = $this->upsertRoles($tenant);
                $this->upsertMemberships($tenant, $users, $roles);

                [$clinic, $room, $appointmentType] = $this->upsertReferenceData($tenant);
                $this->syncClinicAccess($tenant, $clinic);
                $clinician = $users['clinician'];

                UserPreference::query()->updateOrCreate(
                    ['tenant_id' => $tenant->getKey(), 'user_id' => $clinician->getKey(), 'key' => 'theme'],
                    ['value' => ['mode' => 'system', 'contrast' => 'standard'], 'lock_version' => 1],
                );

                $datasetMarker = UserPreference::query()
                    ->where('user_id', $clinician->getKey())
                    ->where('key', 'clinical_demo_dataset')
                    ->first();

                $existingPatient = Patient::query()->oldest('created_at')->first();

                if ($datasetMarker || $existingPatient) {
                    if ($existingPatient) {
                        $this->syncClinicAccess($tenant, $clinic);
                        $this->upsertPatientUserLink($tenant, $users['patient'], $existingPatient, $clinician);
                    }

                    $this->markDatasetSeeded($tenant, $clinician);

                    return;
                }

                $this->seedClinicalDataset(
                    $tenant,
                    $clinic,
                    $room,
                    $appointmentType,
                    $clinician,
                    $users['patient'],
                );
                $this->markDatasetSeeded($tenant, $clinician);
            });
        } finally {
            $this->clearDatabaseTenantContext();
            app()->forgetInstance('currentMembership');
            app()->forgetInstance('currentTenant');
        }
    }

    private function assertSchemaReady(): void
    {
        $requiredColumns = [
            'users' => ['oidc_issuer', 'oidc_subject'],
            'tenants' => ['slug', 'timezone'],
            'memberships' => ['tenant_id', 'user_id'],
            'membership_clinic' => ['tenant_id', 'membership_id', 'clinic_id'],
            'clinic_patient' => ['tenant_id', 'clinic_id', 'patient_id'],
            'consent_records' => ['tenant_id', 'patient_id', 'status'],
            'patient_user_links' => ['tenant_id', 'user_id', 'patient_id', 'relationship'],
        ];

        foreach ($requiredColumns as $table => $columns) {
            if (! Schema::hasColumns($table, $columns)) {
                throw new LogicException(
                    "The database schema is not current for {$table}; run all Laravel migrations before the demo seeder.",
                );
            }
        }
    }

    /**
     * Allow the synthetic dataset to run through the same PostgreSQL tenant policy
     * as an authenticated request after the target tenant has been discovered.
     */
    private function setDatabaseTenantContext(string $tenantId): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
        }
    }

    /**
     * Never leave the demo tenant in a long-lived CLI/worker database connection.
     */
    private function clearDatabaseTenantContext(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT set_config('app.tenant_id', '', false)");
        }
    }

    private function upsertTenant(): Tenant
    {
        $tenant = Tenant::withTrashed()->where('slug', 'nexasalud-demo')->first();

        if (! $tenant) {
            $tenant = new Tenant;
            $tenant->setAttribute('id', self::TENANT_ID);
            $tenant->setAttribute('slug', 'nexasalud-demo');
        }

        $tenant->fill([
            'name' => 'NexaSalud Demo',
            'timezone' => 'Europe/Madrid',
            'locale' => 'es',
            'is_active' => true,
        ]);
        $tenant->deleted_at = null;
        $tenant->save();

        return $tenant;
    }

    /**
     * @return array<string, User>
     */
    private function upsertUsers(string $issuer): array
    {
        $identities = [
            'clinician' => [
                'username' => '00000000T-DEMO',
                'subject' => '30000000-0000-7000-8000-000000000001',
                'display_name' => 'Alicia Vega (Demo)',
                'email' => 'alicia.vega@nexasalud.example.test',
            ],
            'receptionist' => [
                'username' => '11111111H-DEMO',
                'subject' => '30000000-0000-7000-8000-000000000002',
                'display_name' => 'Noa Soler (Demo)',
                'email' => 'recepcion@nexasalud.example.test',
            ],
            'auditor' => [
                'username' => '22222222J-DEMO',
                'subject' => '30000000-0000-7000-8000-000000000003',
                'display_name' => 'Izan Lago (Demo)',
                'email' => 'auditoria@nexasalud.example.test',
            ],
            'patient' => [
                'username' => '33333333P-DEMO',
                'subject' => '30000000-0000-7000-8000-000000000004',
                'display_name' => 'Paciente Portal (Demo)',
                'email' => 'paciente@nexasalud.example.test',
            ],
        ];

        // Keycloak 25+ ignores the ids pinned by the realm template on import and
        // generates fresh UUIDs, so align local subjects with the running realm.
        // Canonical subjects remain the fallback (e.g. in testing without Keycloak).
        $subjects = $this->resolveKeycloakSubjects();

        $users = [];

        foreach ($identities as $key => $identity) {
            $users[$key] = User::query()->updateOrCreate(
                ['oidc_issuer' => $issuer, 'oidc_subject' => $subjects[$identity['username']] ?? $identity['subject']],
                [
                    'display_name' => $identity['display_name'],
                    'email' => $identity['email'],
                    'is_active' => true,
                ],
            );
        }

        return $users;
    }

    /**
     * Resolve the real Keycloak subjects for the demo identities from the admin
     * API of the running realm. Returns an empty map when the environment or
     * configuration does not allow the lookup, so callers keep the canonical
     * subjects as fallback.
     *
     * @return array<string, string> demo username => Keycloak subject
     */
    private function resolveKeycloakSubjects(): array
    {
        if (! app()->environment('local')) {
            return [];
        }

        $baseUrl = rtrim((string) config('services.keycloak.admin_base_url'), '/');
        $realm = (string) config('services.keycloak.realm');
        $adminUsername = (string) config('services.keycloak.admin_username');
        $adminPassword = (string) config('services.keycloak.admin_password');

        if ($baseUrl === '' || $realm === '' || $adminUsername === '' || $adminPassword === '') {
            return [];
        }

        try {
            $http = new Client([
                'base_uri' => $baseUrl.'/',
                'timeout' => (float) config('services.keycloak.timeout_seconds', 5),
                'connect_timeout' => (float) config('services.keycloak.connect_timeout_seconds', 2),
                ...LocalhostCurlResolver::optionsFor($baseUrl),
            ]);

            $token = $http->post('/realms/master/protocol/openid-connect/token', [
                'form_params' => [
                    'grant_type' => 'password',
                    'client_id' => 'admin-cli',
                    'username' => $adminUsername,
                    'password' => $adminPassword,
                ],
            ]);
            $accessToken = data_get(json_decode((string) $token->getBody(), true), 'access_token');

            if (! is_string($accessToken) || $accessToken === '') {
                return [];
            }

            $subjects = [];

            foreach ($this->demoIdentityUsernames() as $username) {
                $response = $http->get('/admin/realms/'.$realm.'/users', [
                    'headers' => ['Authorization' => 'Bearer '.$accessToken],
                    'query' => ['username' => $username, 'exact' => 'true'],
                ]);
                $users = json_decode((string) $response->getBody(), true);

                if (is_array($users) && isset($users[0]['id']) && is_string($users[0]['id'])) {
                    $subjects[$username] = $users[0]['id'];
                }
            }

            return $subjects;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function demoIdentityUsernames(): array
    {
        return [
            '00000000T-DEMO',
            '11111111H-DEMO',
            '22222222J-DEMO',
            '33333333P-DEMO',
        ];
    }

    /**
     * @return array<string, Role>
     */
    private function upsertRoles(Tenant $tenant): array
    {
        $definitions = [
            'tenant-admin' => [
                'name' => 'Administración integral de clínica',
                'permissions' => ['*'],
            ],
            'clinician' => [
                'name' => 'Profesional sanitario',
                'permissions' => [
                    'appointments.*',
                    'appointments.reason.read',
                    'patients.*',
                    'clinical_notes.*',
                    'prescriptions.*',
                    'documents.*',
                    'analytics.read',
                    'communications.*',
                ],
            ],
            'receptionist' => [
                'name' => 'Recepción y admisión',
                'permissions' => [
                    'appointments.read',
                    'appointments.write',
                    'appointments.cancel',
                    'patients.read',
                    'patients.write',
                    'billing.*',
                    'documents.generate',
                    'communications.read',
                    'communications.write',
                ],
            ],
            'auditor' => [
                'name' => 'Auditoría de solo lectura',
                'permissions' => ['audit.read'],
            ],
            'patient' => [
                'name' => 'Portal del paciente',
                'permissions' => ['portal.*'],
            ],
        ];

        $roles = [];

        foreach ($definitions as $code => $definition) {
            $roles[$code] = Role::query()->updateOrCreate(
                ['tenant_id' => $tenant->getKey(), 'code' => $code],
                [
                    'name' => $definition['name'],
                    'permissions' => $definition['permissions'],
                    'is_system' => true,
                ],
            );
        }

        return $roles;
    }

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Role>  $roles
     */
    private function upsertMemberships(Tenant $tenant, array $users, array $roles): void
    {
        $assignments = [
            'clinician' => ['tenant-admin', 'clinician'],
            'receptionist' => ['receptionist'],
            'auditor' => ['auditor'],
            'patient' => ['patient'],
        ];

        foreach ($assignments as $identity => $roleCodes) {
            $membership = Membership::query()->updateOrCreate(
                ['tenant_id' => $tenant->getKey(), 'user_id' => $users[$identity]->getKey()],
                ['is_active' => true, 'joined_at' => now()],
            );

            $pivot = [];

            foreach ($roleCodes as $roleCode) {
                $pivot[$roles[$roleCode]->getKey()] = [
                    'tenant_id' => $tenant->getKey(),
                    'granted_by' => $users['clinician']->getKey(),
                    'granted_at' => now(),
                ];
            }

            $membership->roles()->sync($pivot);
        }
    }

    /**
     * @return array{Clinic, Room, AppointmentType}
     */
    private function upsertReferenceData(Tenant $tenant): array
    {
        $clinic = Clinic::query()->updateOrCreate(
            ['tenant_id' => $tenant->getKey(), 'code' => 'MAD-CENTRO'],
            [
                'name' => 'Centro Clínico Demo',
                'timezone' => 'Europe/Madrid',
                'color' => '#0D7469',
                'is_active' => true,
            ],
        );

        $room = Room::query()->updateOrCreate(
            ['tenant_id' => $tenant->getKey(), 'clinic_id' => $clinic->getKey(), 'code' => 'C-01'],
            [
                'name' => 'Consulta 01',
                'capacity' => 1,
                'supports_video' => true,
                'is_active' => true,
            ],
        );

        $appointmentType = AppointmentType::query()->updateOrCreate(
            ['tenant_id' => $tenant->getKey(), 'code' => 'FOLLOW-UP'],
            [
                'name' => 'Seguimiento (Demo)',
                'duration_minutes' => 30,
                'buffer_before_minutes' => 0,
                'buffer_after_minutes' => 5,
                'color' => '#0D7469',
                'requires_room' => true,
                'allows_video' => true,
                'is_active' => true,
            ],
        );

        return [$clinic, $room, $appointmentType];
    }

    private function seedClinicalDataset(
        Tenant $tenant,
        Clinic $clinic,
        Room $room,
        AppointmentType $appointmentType,
        User $clinician,
        User $patientUser,
    ): void {
        $patients = Patient::factory()->count(6)->create(['tenant_id' => $tenant->getKey()]);
        foreach ($patients as $patient) {
            ClinicPatient::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->getKey(),
                    'clinic_id' => $clinic->getKey(),
                    'patient_id' => $patient->getKey(),
                ],
                ['is_active' => true, 'first_seen_at' => now()],
            );
        }
        // A few appointments today (local clinic time) so the operational dashboard is not empty.
        foreach ($patients->skip(2)->take(3)->values() as $index => $patient) {
            $startsAt = now($clinic->timezone)->setTime(9, 0)->addMinutes($index * 45);

            Appointment::factory()->create([
                'tenant_id' => $tenant->getKey(),
                'clinic_id' => $clinic->getKey(),
                'room_id' => $room->getKey(),
                'appointment_type_id' => $appointmentType->getKey(),
                'patient_id' => $patient->getKey(),
                'clinician_user_id' => $clinician->getKey(),
                'created_by' => $clinician->getKey(),
                'updated_by' => $clinician->getKey(),
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->clone()->addMinutes(30),
            ]);
        }

        $scheduleStart = now()->addWeek()->startOfWeek()->addDay()->setTime(9, 0);

        foreach ($patients->take(4)->values() as $index => $patient) {
            $startsAt = $scheduleStart->clone()->addMinutes($index * 45);

            Appointment::factory()->create([
                'tenant_id' => $tenant->getKey(),
                'clinic_id' => $clinic->getKey(),
                'room_id' => $room->getKey(),
                'appointment_type_id' => $appointmentType->getKey(),
                'patient_id' => $patient->getKey(),
                'clinician_user_id' => $clinician->getKey(),
                'created_by' => $clinician->getKey(),
                'updated_by' => $clinician->getKey(),
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->clone()->addMinutes(30),
            ]);
        }

        $patient = $patients->firstOrFail();
        $this->upsertPatientUserLink($tenant, $patientUser, $patient, $clinician);

        WaitlistEntry::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'clinic_id' => $clinic->getKey(),
            'patient_id' => $patient->getKey(),
            'appointment_type_id' => $appointmentType->getKey(),
            'preferred_clinician_user_id' => $clinician->getKey(),
        ]);

        $prescription = Prescription::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'clinic_id' => $clinic->getKey(),
            'patient_id' => $patient->getKey(),
            'prescriber_user_id' => $clinician->getKey(),
        ]);
        PrescriptionItem::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'prescription_id' => $prescription->getKey(),
        ]);

        $allergy = PatientAllergy::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'patient_id' => $patient->getKey(),
            'recorded_by' => $clinician->getKey(),
        ]);
        AdverseReaction::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'patient_id' => $patient->getKey(),
            'patient_allergy_id' => $allergy->getKey(),
            'recorded_by' => $clinician->getKey(),
        ]);

        $consent = ConsentRecord::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'patient_id' => $patient->getKey(),
            'recorded_by' => $clinician->getKey(),
        ]);
        $document = ClinicalDocument::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'clinic_id' => $clinic->getKey(),
            'patient_id' => $patient->getKey(),
            'prescription_id' => $prescription->getKey(),
            'consent_record_id' => $consent->getKey(),
            'generated_by' => $clinician->getKey(),
        ]);
        $document->recordAccess($clinician, 'clinical_document.viewed', 'synthetic-demo-seed');

        $insurer = Insurer::factory()->create(['tenant_id' => $tenant->getKey()]);
        $coverage = PatientCoverage::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'patient_id' => $patient->getKey(),
            'insurer_id' => $insurer->getKey(),
        ]);
        $invoice = Invoice::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'clinic_id' => $clinic->getKey(),
            'patient_id' => $patient->getKey(),
            'patient_coverage_id' => $coverage->getKey(),
        ]);
        InvoiceItem::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'invoice_id' => $invoice->getKey(),
        ]);
        Payment::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'invoice_id' => $invoice->getKey(),
        ]);
    }

    /**
     * Grant every synthetic demo identity and patient access to the single demo clinic.
     */
    private function syncClinicAccess(Tenant $tenant, Clinic $clinic): void
    {
        Membership::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('is_active', true)
            ->get()
            ->each(function (Membership $membership) use ($tenant, $clinic): void {
                MembershipClinic::query()->updateOrCreate(
                    [
                        'tenant_id' => $tenant->getKey(),
                        'membership_id' => $membership->getKey(),
                        'clinic_id' => $clinic->getKey(),
                    ],
                    ['is_active' => true],
                );
            });

        Patient::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('is_active', true)
            ->get()
            ->each(function (Patient $patient) use ($tenant, $clinic): void {
                ClinicPatient::query()->updateOrCreate(
                    [
                        'tenant_id' => $tenant->getKey(),
                        'clinic_id' => $clinic->getKey(),
                        'patient_id' => $patient->getKey(),
                    ],
                    ['is_active' => true, 'first_seen_at' => now()],
                );
            });
    }

    private function upsertPatientUserLink(
        Tenant $tenant,
        User $patientUser,
        Patient $patient,
        User $verifiedBy,
    ): void {
        PatientUserLink::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'user_id' => $patientUser->getKey(),
                'patient_id' => $patient->getKey(),
                'relationship' => 'self',
            ],
            [
                'is_active' => true,
                'verified_at' => now(),
                'access_expires_at' => null,
                'verified_by' => $verifiedBy->getKey(),
            ],
        );
    }

    private function markDatasetSeeded(Tenant $tenant, User $clinician): void
    {
        UserPreference::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->getKey(),
                'user_id' => $clinician->getKey(),
                'key' => 'clinical_demo_dataset',
            ],
            [
                'value' => ['version' => self::DATASET_VERSION, 'synthetic' => true],
                'lock_version' => 1,
            ],
        );
    }
}
