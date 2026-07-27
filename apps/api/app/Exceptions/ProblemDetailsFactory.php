<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\OriginMismatchException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Converts expected API failures into non-sensitive RFC 9457 problem responses.
 */
final class ProblemDetailsFactory
{
    /**
     * Builds a cache-safe problem response with a correlation trace identifier.
     */
    public function response(Throwable $exception, Request $request): JsonResponse
    {
        [$status, $slug, $title, $detail] = $this->describe($exception);
        $traceId = $request->attributes->get('trace_id');

        if (! is_string($traceId) || $traceId === '') {
            $traceId = (string) Str::uuid();
        }

        $problem = [
            'type' => '/problems/'.$slug,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => 'urn:uuid:'.$traceId,
            'traceId' => $traceId,
        ];

        if ($exception instanceof ValidationException) {
            $problem['errors'] = $this->validationErrors($exception);
        }

        if ($exception instanceof TenantSelectionRequiredException) {
            $problem['selection'] = $exception->selection();
            $problem['selectionUri'] = '/api/session/context-options';
        }

        $response = new JsonResponse($problem, $status, [
            'Cache-Control' => 'no-store, private',
            'Content-Type' => 'application/problem+json',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Trace-Id' => $traceId,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($slug === 'database-unavailable') {
            $response->headers->set('Retry-After', '5');
        }

        if ($exception instanceof HttpExceptionInterface) {
            foreach (['Retry-After', 'Allow'] as $safeHeader) {
                $value = $exception->getHeaders()[$safeHeader] ?? null;

                if (is_string($value) || is_numeric($value)) {
                    $response->headers->set($safeHeader, (string) $value);
                }
            }
        }

        return $response;
    }

    /**
     * Maps an exception to a stable, client-safe problem definition.
     *
     * @return array{int, string, string, string}
     */
    private function describe(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof IdentityUnavailableException => [503, 'identity-unavailable', 'Identidad no disponible', 'El proveedor de identidad no está disponible temporalmente.'],
            $exception instanceof AuthorizationException && $exception->status() === 404 => [404, 'not-found', 'Recurso no encontrado', 'El recurso solicitado no existe o no está disponible.'],
            $exception instanceof IdentityAccessDeniedException,
            $exception instanceof AuthorizationException => [403, 'access-denied', 'Acceso denegado', 'No tienes autorización para realizar esta operación.'],
            $exception instanceof TenantAccessDeniedException => [403, 'tenant-access-denied', 'Organización no disponible', 'No tienes acceso activo a una organización.'],
            $exception instanceof TenantSelectionRequiredException => [409, 'tenant-selection-required', 'Selección de organización requerida', 'Selecciona una organización o centro para continuar.'],
            $exception instanceof DomainException => [409, 'clinical-conflict', 'Conflicto clínico', 'La operación entra en conflicto con el estado actual del recurso.'],
            $exception instanceof LockTimeoutException => [409, 'resource-busy', 'Recurso en proceso', 'La operación ya está siendo procesada. Inténtalo de nuevo.'],
            $exception instanceof QueryException => $this->databaseProblem($exception),
            $exception instanceof IdentityProtocolException => [401, 'identity-response-invalid', 'Respuesta de identidad no válida', 'No se pudo verificar la respuesta de autenticación.'],
            $exception instanceof InvalidOidcTransactionException => [400, 'identity-transaction-invalid', 'Transacción de identidad no válida', 'La autenticación ha caducado o ya se ha utilizado.'],
            $exception instanceof AuthenticationException => [401, 'authentication-required', 'Autenticación requerida', 'Debes iniciar sesión para continuar.'],
            $exception instanceof ValidationException => [422, 'validation-failed', 'Validación fallida', 'Uno o más campos no cumplen el contrato esperado.'],
            $exception instanceof OriginMismatchException => [403, 'request-origin-invalid', 'Origen no permitido', 'No se pudo verificar el origen de la solicitud.'],
            $exception instanceof TokenMismatchException => [419, 'csrf-token-invalid', 'Sesión caducada', 'Actualiza la sesión e inténtalo de nuevo.'],
            $exception instanceof TooManyRequestsHttpException => [429, 'rate-limit-exceeded', 'Demasiadas solicitudes', 'Espera antes de volver a intentarlo.'],
            $exception instanceof MethodNotAllowedHttpException => [405, 'method-not-allowed', 'Método no permitido', 'El método HTTP no está permitido para este recurso.'],
            $exception instanceof ModelNotFoundException,
            $exception instanceof NotFoundHttpException => [404, 'not-found', 'Recurso no encontrado', 'El recurso solicitado no existe o no está disponible.'],
            $exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 404 => [404, 'not-found', 'Recurso no encontrado', 'El recurso solicitado no existe o no está disponible.'],
            $exception instanceof HttpExceptionInterface => [
                $exception->getStatusCode(),
                'request-failed',
                'Solicitud rechazada',
                'La solicitud no pudo completarse.',
            ],
            default => [500, 'internal-error', 'Error interno', 'La operación no pudo completarse.'],
        };
    }

    /**
     * Classifies database failures without exposing database topology or query details.
     *
     * @return array{int, string, string, string}
     */
    private function databaseProblem(QueryException $exception): array
    {
        $sqlState = is_string($exception->getCode()) ? $exception->getCode() : '';

        if (str_starts_with($sqlState, '23') || in_array($sqlState, ['40001', '40P01'], true)) {
            return [409, 'data-conflict', 'Conflicto de datos', 'Otro cambio concurrente impide completar la operación. Actualiza los datos e inténtalo de nuevo.'];
        }

        if (str_starts_with($sqlState, '08') || in_array($sqlState, ['57P01', '57P02', '57P03'], true)) {
            return [503, 'database-unavailable', 'Servicio temporalmente no disponible', 'No se puede acceder al almacenamiento de forma temporal. Inténtalo de nuevo.'];
        }

        return [500, 'internal-error', 'Error interno', 'La operación no pudo completarse.'];
    }

    /**
     * Produces a field and rule allowlist for validation failures.
     *
     * @return array<string, list<string>>
     */
    private function validationErrors(ValidationException $exception): array
    {
        $errors = [];

        foreach ($exception->validator->failed() as $field => $rules) {
            $safeField = preg_match('/^[A-Za-z0-9_.-]{1,100}$/', (string) $field) === 1
                ? (string) $field
                : 'request';
            $errors[$safeField] = array_values(array_unique(array_map(
                static fn (string $rule): string => Str::snake(class_basename($rule)),
                array_keys($rules),
            )));
        }

        return $errors;
    }
}
