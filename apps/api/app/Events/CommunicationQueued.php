<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Signals that a consented patient communication was durably queued for downstream processing.
 *
 * The payload intentionally contains identifiers and operational metadata only, never message content or PHI.
 */
final readonly class CommunicationQueued implements ShouldDispatchAfterCommit
{
    /**
     * Creates an event that can be safely retried by asynchronous listeners after the database commit.
     */
    public function __construct(
        public string $communicationId,
        public string $tenantId,
        public string $actorUserId,
        public ?string $actorSubject,
        public ?string $traceId,
        public string $channel,
        public string $contentClass,
        public bool $usesExternalTemplate,
    ) {}
}
