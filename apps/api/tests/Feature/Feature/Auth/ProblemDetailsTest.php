<?php

namespace Tests\Feature\Feature\Auth;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ProblemDetailsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/_test/internal-problem', static function (): never {
            throw new RuntimeException('Patient Jane Doe has diagnosis HIV and token secret-token.');
        });
        Route::post('/api/_test/validation-problem', static function (): JsonResponse {
            validator(
                ['patient_email' => 'jane.doe-sensitive-record'],
                ['patient_email' => ['required', 'email', 'max:10']],
            )->validate();

            return response()->json();
        });
        Route::get('/api/_test/hidden-resource', static function (): never {
            throw (new AuthorizationException('Patient record exists.'))->asNotFound();
        });
        Route::post('/api/_test/clinical-conflict', static function (): never {
            throw new DomainException('Patient Jane Doe overlaps appointment 123.');
        });
    }

    public function test_internal_errors_are_generic_rfc9457_documents_with_server_trace_ids(): void
    {
        Exceptions::fake(RuntimeException::class);

        $response = $this->withHeader('X-Request-ID', 'attacker-controlled-id')
            ->getJson('/api/_test/internal-problem');

        $response->assertStatus(500)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertJsonPath('type', '/problems/internal-error')
            ->assertJsonPath('status', 500)
            ->assertJsonStructure(['type', 'title', 'status', 'detail', 'instance', 'traceId']);
        $traceId = $response->json('traceId');
        $this->assertIsString($traceId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $traceId,
        );
        $this->assertSame($traceId, $response->headers->get('X-Trace-Id'));
        $this->assertNotSame('attacker-controlled-id', $traceId);
        $this->assertStringNotContainsString('Jane Doe', $response->getContent());
        $this->assertStringNotContainsString('HIV', $response->getContent());
        $this->assertStringNotContainsString('secret-token', $response->getContent());
        $this->assertStringNotContainsString('trace', mb_strtolower((string) $response->json('detail')));
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_validation_errors_disclose_only_field_and_rule_codes(): void
    {
        $response = $this->postJson('/api/_test/validation-problem');

        $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', '/problems/validation-failed')
            ->assertJsonPath('errors.patient_email', ['email', 'max']);
        $this->assertStringNotContainsString('jane.doe-sensitive-record', $response->getContent());
        $this->assertStringNotContainsString('The patient email', $response->getContent());
    }

    public function test_authorization_as_not_found_preserves_tenant_resource_concealment(): void
    {
        $response = $this->getJson('/api/_test/hidden-resource');

        $response->assertNotFound()
            ->assertJsonPath('type', '/problems/not-found')
            ->assertJsonPath('detail', 'El recurso solicitado no existe o no está disponible.');
        $this->assertStringNotContainsString('Patient record exists', $response->getContent());
    }

    public function test_domain_conflicts_are_safe_409_problems_without_exception_details(): void
    {
        $response = $this->postJson('/api/_test/clinical-conflict');

        $response->assertStatus(409)
            ->assertJsonPath('type', '/problems/clinical-conflict')
            ->assertJsonPath('detail', 'La operación entra en conflicto con el estado actual del recurso.');
        $this->assertStringNotContainsString('Jane Doe', $response->getContent());
        $this->assertStringNotContainsString('appointment 123', $response->getContent());
    }

    public function test_authentication_and_route_errors_share_the_safe_contract(): void
    {
        $this->getJson('/api/session')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', '/problems/authentication-required');

        $this->getJson('/api/does-not-exist')
            ->assertNotFound()
            ->assertJsonPath('type', '/problems/not-found');

        $this->postJson('/api/session')
            ->assertStatus(405)
            ->assertHeader('Allow', 'GET, HEAD')
            ->assertJsonPath('type', '/problems/method-not-allowed');
    }

    public function test_credentialed_cors_allows_only_the_configured_angular_origin_and_headers(): void
    {
        config()->set('cors.allowed_origins', ['http://localhost:4200']);

        $response = $this->withHeaders([
            'Origin' => 'http://localhost:4200',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Content-Type, X-XSRF-TOKEN, X-Request-ID, Idempotency-Key',
        ])->options('/api/session/context');

        $response->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $allowedHeaders = mb_strtolower((string) $response->headers->get('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('x-xsrf-token', $allowedHeaders);
        $this->assertStringContainsString('x-request-id', $allowedHeaders);
        $this->assertStringContainsString('idempotency-key', $allowedHeaders);

        $untrusted = $this->withHeaders([
            'Origin' => 'https://attacker.example',
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/session');
        $this->assertNotSame(
            'https://attacker.example',
            $untrusted->headers->get('Access-Control-Allow-Origin'),
        );
    }
}
