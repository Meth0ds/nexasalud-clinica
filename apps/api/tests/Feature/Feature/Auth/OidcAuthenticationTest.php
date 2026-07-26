<?php

namespace Tests\Feature\Feature\Auth;

use App\Exceptions\IdentityAccessDeniedException;
use App\Exceptions\IdentityUnavailableException;
use App\Exceptions\InvalidOidcTransactionException;
use App\Models\Clinic;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Identity\Contracts\OidcClient;
use App\Services\Identity\Data\OidcIdentity;
use App\Services\Identity\Data\OidcLoginResult;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;
use Throwable;

class OidcAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private FakeOidcClient $oidc;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'services.keycloak.frontend_url' => 'http://localhost:4200',
            'services.keycloak.upstream_logout' => false,
            'session.absolute_lifetime' => 480,
            'session.lifetime' => 15,
        ]);
        $this->oidc = new FakeOidcClient(new OidcLoginResult(
            identity: new OidcIdentity(
                issuer: 'https://identity.example.test/realms/nexasalud',
                subject: 'staff-subject',
                displayName: 'Dra. Elena Torres',
                email: 'elena.torres@example.test',
                authenticationContext: '1',
                authenticationMethods: ['pwd', 'otp'],
                authenticatedAt: time() - 30,
            ),
            returnPath: '/agenda',
        ));
        $this->app->instance(OidcClient::class, $this->oidc);
    }

    public function test_login_start_normalizes_return_path_before_redirecting_to_keycloak(): void
    {
        $response = $this->get('/auth/login?returnTo='.urlencode('//attacker.example/steal'));

        $response->assertRedirect('https://identity.example.test/authorize');
        $this->assertSame('/', $this->oidc->redirectReturnPath);
    }

    public function test_callback_upserts_identity_rotates_session_and_never_exposes_tokens(): void
    {
        $response = $this->withSession([
            'legacy-user-state' => 'must-not-survive-account-switch',
            'access_token' => 'legacy-secret',
        ])->get('/auth/callback?'.http_build_query([
            'state' => str_repeat('s', 43),
            'code' => 'one-time-code',
        ]));

        $response->assertRedirect('http://localhost:4200/agenda');
        $user = User::query()->where('oidc_subject', 'staff-subject')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Dra. Elena Torres', $user->display_name);
        $this->assertSame('elena.torres@example.test', $user->email);
        $this->assertNotSame(
            'elena.torres@example.test',
            DB::table('users')->where('id', $user->getKey())->value('email'),
        );
        $response->assertSessionMissing('legacy-user-state');
        $response->assertSessionMissing('access_token');
        $response->assertSessionHas('auth.assurance.amr', ['pwd', 'otp']);
        $response->assertSessionHas('auth.assurance.authenticated_at', $this->oidc->result()->identity->authenticatedAt);
        $response->assertSessionHas('auth.session.absolute_expires_at');
        $this->assertStringNotContainsString('one-time-code', $response->getContent());
    }

    public function test_failed_or_forged_callback_does_not_log_out_an_existing_session(): void
    {
        $existingUser = User::factory()->create();
        $this->oidc->failure = new InvalidOidcTransactionException;
        $originalAuthenticatedAt = time() - 120;

        $response = $this->actingAs($existingUser)
            ->withSession([
                'auth.session.absolute_expires_at' => time() + 300,
                'auth.assurance.authenticated_at' => $originalAuthenticatedAt,
            ])
            ->get('/auth/callback?'.http_build_query([
                'state' => str_repeat('x', 43),
                'code' => 'forged-code',
            ]));

        $response->assertRedirect('http://localhost:4200/login?error=identity_invalid');
        $response->assertSessionHas('auth.assurance.authenticated_at', $originalAuthenticatedAt);
        $this->assertAuthenticatedAs($existingUser);
    }

    public function test_invalid_callback_shape_consumes_only_the_oidc_transaction(): void
    {
        $existingUser = User::factory()->create();

        $response = $this->actingAs($existingUser)
            ->withSession([
                'auth.oidc.transaction' => ['sensitive' => 'transaction'],
                'auth.session.absolute_expires_at' => time() + 300,
            ])
            ->getJson('/auth/callback?state=short&code=x');

        $response->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', '/problems/identity-transaction-invalid')
            ->assertSessionMissing('auth.oidc.transaction');
        $this->assertAuthenticatedAs($existingUser);
    }

    public function test_inactive_identity_is_denied_without_reactivating_the_account(): void
    {
        $user = User::factory()->inactive()->create([
            'oidc_issuer' => 'https://identity.example.test/realms/nexasalud',
            'oidc_subject' => 'staff-subject',
        ]);

        $response = $this->get('/auth/callback?'.http_build_query([
            'state' => str_repeat('s', 43),
            'code' => 'one-time-code',
        ]));

        $response->assertRedirect('http://localhost:4200/login?error=access_denied');
        $this->assertGuest();
        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame(1, User::query()->count());
    }

    public function test_identity_provider_outage_is_reported_safely_and_redirected(): void
    {
        Exceptions::fake(IdentityUnavailableException::class);
        $this->oidc->failure = new IdentityUnavailableException;

        $response = $this->get('/auth/callback?'.http_build_query([
            'state' => str_repeat('s', 43),
            'code' => 'one-time-code',
        ]));

        $response->assertRedirect('http://localhost:4200/login?error=identity_unavailable');
        Exceptions::assertReported(IdentityUnavailableException::class);
        $this->assertGuest();
    }

    public function test_session_contract_contains_authorized_tenant_context_without_sensitive_values(): void
    {
        $user = User::factory()->create([
            'display_name' => 'Dra. Elena Torres',
            'email' => 'doctor@example.test',
        ]);
        [$tenant, $clinic] = $this->createTenantContext($user, ['patients.read', 'appointments.write']);

        $response = $this->actingAs($user)
            ->withSession([
                'auth.session.absolute_expires_at' => time() + 3600,
                'auth.assurance.amr' => ['pwd', 'otp'],
                'auth.assurance.authenticated_at' => time() - 60,
            ])
            ->getJson('/api/session');

        $response->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('user.id', $user->getKey())
            ->assertJsonPath('user.emailHint', 'd***@e***.test')
            ->assertJsonPath('user.initials', 'ET')
            ->assertJsonPath('user.professionalTitle', 'Profesional clínico')
            ->assertJsonPath('tenant.id', $tenant->getKey())
            ->assertJsonPath('tenant.clinicId', $clinic->getKey())
            ->assertJsonPath('permissions', ['appointments.write', 'patients.read'])
            ->assertJsonPath('assuranceLevel', 'mfa')
            ->assertJsonStructure(['authenticatedAt'])
            ->assertJsonMissingPath('csrfToken')
            ->assertJsonMissingPath('user.email')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString('doctor@example.test', $response->getContent());
        $this->assertStringNotContainsString('token', mb_strtolower($response->getContent()));
        $this->assertFalse($this->app->bound('currentTenant'));
        $this->assertFalse($this->app->bound('currentMembership'));
    }

    public function test_multiple_tenants_require_an_explicit_database_validated_selection(): void
    {
        $user = User::factory()->create();
        [$firstTenant, $firstClinic, $firstMembership] = $this->createTenantContext($user);
        $this->createTenantContext($user);
        $session = [
            'auth.session.absolute_expires_at' => time() + 3600,
            'auth.assurance.authenticated_at' => time() - 60,
        ];

        $this->actingAs($user)->withSession($session)->getJson('/api/session')
            ->assertStatus(409)
            ->assertJsonPath('type', '/problems/tenant-selection-required')
            ->assertJsonPath('selection', 'tenant')
            ->assertJsonPath('selectionUri', '/api/session/context-options');

        $this->getJson('/api/session/context-options')
            ->assertOk()
            ->assertJsonCount(2, 'items');

        $this->postJson('/api/session/context', [
            'membershipId' => $firstMembership->getKey(),
            'clinicId' => $firstClinic->getKey(),
        ])->assertOk()
            ->assertJsonPath('selected', true)
            ->assertJsonPath('tenantId', $firstTenant->getKey());

        $this->getJson('/api/session')
            ->assertOk()
            ->assertJsonPath('tenant.id', $firstTenant->getKey());
    }

    public function test_context_selection_rejects_another_users_membership_and_clinic(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        [, $otherClinic, $otherMembership] = $this->createTenantContext($otherUser);

        $response = $this->actingAs($user)
            ->withSession(['auth.session.absolute_expires_at' => time() + 3600])
            ->postJson('/api/session/context', [
                'membershipId' => $otherMembership->getKey(),
                'clinicId' => $otherClinic->getKey(),
            ]);

        $response->assertForbidden()
            ->assertJsonPath('type', '/problems/tenant-access-denied');
        $response->assertSessionMissing('auth.context.membership_id');
    }

    public function test_disabled_user_and_expired_absolute_session_are_terminated(): void
    {
        $inactiveUser = User::factory()->inactive()->create();

        $this->actingAs($inactiveUser)
            ->withSession(['auth.session.absolute_expires_at' => time() + 300])
            ->getJson('/api/session')
            ->assertForbidden()
            ->assertJsonPath('type', '/problems/access-denied');
        $this->assertGuest();

        $activeUser = User::factory()->create();
        $this->actingAs($activeUser)
            ->withSession(['auth.session.absolute_expires_at' => time() - 1])
            ->getJson('/api/session')
            ->assertUnauthorized()
            ->assertJsonPath('type', '/problems/authentication-required');
        $this->assertGuest();
    }

    public function test_logout_invalidates_local_session_and_rotates_csrf_token(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession([
                'auth.session.absolute_expires_at' => time() + 300,
                'sensitive-local-state' => 'remove-me',
            ])
            ->postJson('/auth/logout', ['upstream' => false]);

        $response->assertOk()
            ->assertJson([
                'signedOut' => true,
                'upstreamRedirect' => null,
            ])
            ->assertSessionMissing('sensitive-local-state');
        $this->assertGuest();
    }

    /**
     * @param  list<string>  $permissions
     * @return array{Tenant, Clinic, Membership}
     */
    private function createTenantContext(User $user, array $permissions = ['appointments.read']): array
    {
        $tenant = Tenant::factory()->create();
        $clinic = Clinic::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Clínica Mediterránea',
        ]);
        $membership = Membership::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $user->getKey(),
        ]);
        $role = Role::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Profesional clínico',
            'permissions' => $permissions,
        ]);
        DB::table('membership_role')->insert([
            'tenant_id' => $tenant->getKey(),
            'membership_id' => $membership->getKey(),
            'role_id' => $role->getKey(),
            'granted_by' => null,
            'granted_at' => now(),
        ]);

        return [$tenant, $clinic, $membership];
    }
}

final class FakeOidcClient implements OidcClient
{
    public ?string $redirectReturnPath = null;

    public ?Throwable $failure = null;

    public function __construct(private OidcLoginResult $result) {}

    public function redirect(Request $request, string $returnPath): RedirectResponse
    {
        $this->redirectReturnPath = $returnPath;

        return new RedirectResponse('https://identity.example.test/authorize');
    }

    public function complete(Request $request): OidcLoginResult
    {
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        return $this->result;
    }

    public function result(): OidcLoginResult
    {
        return $this->result;
    }
}
