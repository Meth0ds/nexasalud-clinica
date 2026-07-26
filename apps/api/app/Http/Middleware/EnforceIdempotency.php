<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class EnforceIdempotency
{
    /** @param Closure(Request): Response $next */
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
                    ->where('actor_subject', $actor->oidc_subject)
                    ->where('operation', $operation)
                    ->where('key_hash', $keyHash)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
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

    /** @param array<string, mixed> $payload */
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
}
