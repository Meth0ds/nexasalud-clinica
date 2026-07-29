<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transform an audit event into a privacy-conscious client-facing audit record.
 */
class AuditEventResource extends JsonResource
{
    /**
     * Convert the audit event resource into an array for the current request.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'occurredAt' => $this->occurred_at?->toIso8601String(),
            'actorDisplayName' => $this->actor?->display_name ?? 'Sistema',
            'actionLabel' => $this->event_type,
            'resourceType' => class_basename((string) $this->auditable_type),
            'resourceIdHint' => $this->auditable_id ? '••'.substr((string) $this->auditable_id, -4) : null,
            'outcome' => str_contains($this->event_type, 'blocked') ? 'blocked' : 'allowed',
            'traceId' => $this->request_id,
        ];
    }
}
