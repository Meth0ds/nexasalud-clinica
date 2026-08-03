<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Models\Appointment;
use App\Models\ClinicalDocument;
use App\Models\ClinicalNote;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientCommunication;
use App\Models\Prescription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\Authorization\PermissionService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Ensures a tenant-scoped mutation executes at most once for a valid actor and request payload.
 *
 * Replayed successful JSON responses are returned verbatim. Failed and non-JSON responses are not
 * retained so a transient failure cannot permanently consume a caller's idempotency key.
 */
final class EnforceIdempotency
{
    /**
     * Serializes duplicate requests, fingerprints their canonical payload, and safely replays successful responses.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        abort_unless(
            is_string($key) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/', $key) === 1,
            Response::HTTP_BAD_REQUEST,
        );

        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        $actor = $request->user();
        abort_unless($tenant instanceof Tenant && $actor instanceof User, Response::HTTP_FORBIDDEN);

        $operation = mb_substr((string) ($request->route()?->getName() ?? $request->method().':'.$request->path()), 0, 120);
        $keyHash = hash('sha256', $key);
        $requestHash = hash('sha256', $request->method().'|'.$request->path().'|'.$this->canonicalJson($request->all()));
        $lock = Cache::lock('idempotency:'.hash('sha256', $tenant->getKey().'|'.$actor->getKey().'|'.$operation.'|'.$keyHash), 300);

        abort_unless($lock->get(), Response::HTTP_CONFLICT, headers: ['Retry-After' => '2']);

        try {
            $record = DB::transaction(function () use ($actor, $keyHash, $operation, $requestHash, $tenant): IdempotencyKey {
                $existing = IdempotencyKey::query()
                    ->where('tenant_id', $tenant->getKey())
                    ->where('actor_subject', $actor->oidc_subject)
                    ->where('operation', $operation)
                    ->where('key_hash', $keyHash)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof IdempotencyKey && $existing->expires_at?->isPast()) {
                    $existing->delete();
                    $existing = null;
                }

                if ($existing instanceof IdempotencyKey) {
                    abort_if($existing->request_hash !== $requestHash, Response::HTTP_CONFLICT);

                    return $existing;
                }

                return IdempotencyKey::create([
                    'tenant_id' => $tenant->getKey(),
                    'actor_subject' => $actor->oidc_subject,
                    'operation' => $operation,
                    'key_hash' => $keyHash,
                    'request_hash' => $requestHash,
                    'expires_at' => now()->addDay(),
                ]);
            });

            if ($record->response_status !== null) {
                $this->authorizeReplay($request, $actor);

                return response()->json(
                    $record->response_body ?? [],
                    $record->response_status,
                    ['Idempotency-Replayed' => 'true'],
                );
            }

            if ($record->wasRecentlyCreated === false) {
                abort(Response::HTTP_CONFLICT, headers: ['Retry-After' => '2']);
            }

            try {
                $response = $next($request);
                // Resource responses are Responsable objects until the router prepares the
                // response after the middleware stack. Normalize them here so successful
                // mutations are actually persisted and can be replayed safely.
                if ($response instanceof Responsable) {
                    $response = $response->toResponse($request);
                }
            } catch (Throwable $exception) {
                $record->delete();
                throw $exception;
            }

            if ($response instanceof JsonResponse && $response->isSuccessful()) {
                $content = $response->getData(true);
                if (is_array($content)) {
                    $record->forceFill([
                        'response_status' => $response->getStatusCode(),
                        'response_body' => $content,
                    ])->save();
                }
            } else {
                $record->delete();
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    /**
     * Produces a deterministic representation of an allowlisted request payload for conflict detection.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws \JsonException When the request data cannot be represented safely as JSON.
     */
    private function canonicalJson(array $payload): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }

            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($sort, $value);
        };

        return (string) json_encode($sort($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Re-check the current authorization boundary before returning a cached mutation response.
     * Form requests are intentionally not executed a second time, but a revoked role or clinic
     * grant must never be able to replay a response created under an older session state.
     */
    private function authorizeReplay(Request $request, User $actor): void
    {
        $operation = (string) ($request->route()?->getName() ?? '');
        // API routes carry a shared api.v1. prefix; the authorization matrix
        // is intentionally expressed in resource terms.
        $operation = str_starts_with($operation, 'api.v1.') ? substr($operation, 7) : $operation;
        $portal = app(PermissionService::class);

        if (str_starts_with($operation, 'portal.')) {
            abort_unless($portal->allows($actor, 'portal.self'), Response::HTTP_FORBIDDEN);
            return;
        }

        if (str_starts_with($operation, 'patients.clinical-notes.')) {
            $patient = $request->route('patient');
            abort_unless($patient instanceof Patient, Response::HTTP_NOT_FOUND);
            Gate::forUser($actor)->authorize(str_ends_with($operation, '.sign') ? 'sign' : 'create', [$patient]);

            return;
        }

        $resource = match (true) {
            str_starts_with($operation, 'appointments.documents.') => ClinicalDocument::class,
            str_starts_with($operation, 'appointments.') => Appointment::class,
            str_starts_with($operation, 'patients.clinical-notes.') => ClinicalNote::class,
            str_starts_with($operation, 'patients.') => Patient::class,
            str_starts_with($operation, 'waitlist-entries.') => WaitlistEntry::class,
            str_starts_with($operation, 'communications.') => PatientCommunication::class,
            str_starts_with($operation, 'prescriptions.documents.') => ClinicalDocument::class,
            str_starts_with($operation, 'prescriptions.') => Prescription::class,
            str_starts_with($operation, 'invoices.documents.') => ClinicalDocument::class,
            str_starts_with($operation, 'invoices.') => Invoice::class,
            default => null,
        };

        if ($resource === null) {
            abort(Response::HTTP_CONFLICT);
        }

        $bound = match (true) {
            $resource === Appointment::class => $request->route('appointment'),
            $resource === ClinicalNote::class => $request->route('note'),
            $resource === Patient::class => $request->route('patient'),
            $resource === WaitlistEntry::class => $request->route('waitlistEntry'),
            $resource === PatientCommunication::class => $request->route('communication'),
            $resource === Prescription::class => $request->route('prescription'),
            $resource === Invoice::class => $request->route('invoice'),
            $resource === ClinicalDocument::class => $request->route('document'),
            default => null,
        };

        if ($bound instanceof Appointment && str_ends_with($operation, '.transition')) {
            Gate::forUser($actor)->authorize('transition', [$bound, (string) $request->input('status')]);

            return;
        }

        if ($bound instanceof Prescription && str_ends_with($operation, '.transition')) {
            $ability = match ($request->input('status')) {
                'signed' => 'sign',
                'sent' => 'send',
                default => 'update',
            };
        } else {
            $ability = str_ends_with($operation, '.cancel') || str_ends_with($operation, '.destroy')
            ? 'delete'
            : ($bound === null ? 'create' : 'update');
        }

        if ($bound === null) {
            Gate::forUser($actor)->authorize('create', $resource);

            return;
        }

        Gate::forUser($actor)->authorize($ability, $bound);
    }
}
