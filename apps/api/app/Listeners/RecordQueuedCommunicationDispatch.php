<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CommunicationQueued;
use App\Models\AuditEvent;
use App\Models\PatientCommunication;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Records the post-commit dispatch handoff without leaking communication content to queue telemetry.
 */
final class RecordQueuedCommunicationDispatch implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The listener runs on the isolated operational queue used for communication handoffs.
     */
    public string $queue = 'communications';

    /**
     * A transient persistence failure can be retried without changing the communication itself.
     */
    public int $tries = 3;

    /**
     * Limits the queued audit handoff to a short bounded execution window.
     */
    public int $timeout = 30;

    /**
     * Uses progressive delays to avoid overwhelming a temporarily unavailable database.
     *
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    /**
     * Serializes events for one communication and throttles repeated infrastructure failures.
     *
     * @return list<object>
     */
    public function middleware(CommunicationQueued $event): array
    {
        $throttleKey = 'communication-dispatch:'.$event->communicationId;

        return [
            (new WithoutOverlapping($throttleKey))->releaseAfter(5)->expireAfter(120),
            (new ThrottlesExceptions(5, 300))->by($throttleKey)->backoff(1),
        ];
    }

    /**
     * Persists one idempotent handoff record after the originating transaction has committed.
     */
    public function handle(CommunicationQueued $event): void
    {
        try {
            $this->setDatabaseTenantContext($event->tenantId);

            $alreadyRecorded = AuditEvent::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $event->tenantId)
                ->where('event_type', 'communication.dispatch_registered')
                ->where('auditable_type', PatientCommunication::class)
                ->where('auditable_id', $event->communicationId)
                ->exists();

            if ($alreadyRecorded) {
                return;
            }

            try {
                AuditEvent::create([
                    'tenant_id' => $event->tenantId,
                    'actor_user_id' => $event->actorUserId,
                    'actor_subject' => $event->actorSubject,
                    'event_type' => 'communication.dispatch_registered',
                    'auditable_type' => PatientCommunication::class,
                    'auditable_id' => $event->communicationId,
                    'request_id' => $event->traceId,
                    'metadata' => [
                        'channel' => $event->channel,
                        'content_class' => $event->contentClass,
                        'uses_external_template' => $event->usesExternalTemplate,
                    ],
                    'occurred_at' => now(),
                ]);
            } catch (QueryException $exception) {
                if (! str_contains($exception->getMessage(), 'audit_events_dispatch_unique')) {
                    throw $exception;
                }
            }
        } finally {
            $this->clearDatabaseTenantContext();
        }
    }

    /**
     * Set the PostgreSQL tenant setting for this serialized queue job.
     */
    private function setDatabaseTenantContext(string $tenantId): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
        }
    }

    /**
     * Clear the setting before the worker handles another tenant's job.
     */
    private function clearDatabaseTenantContext(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT set_config('app.tenant_id', '', false)");
        }
    }

    /**
     * Emits safe operational diagnostics when all retry attempts fail.
     */
    public function failed(CommunicationQueued $event, Throwable $exception): void
    {
        Log::error('Communication dispatch handoff failed.', [
            'communication_id' => $event->communicationId,
            'tenant_id' => $event->tenantId,
            'trace_id' => $event->traceId,
            'exception' => $exception::class,
        ]);
    }
}
