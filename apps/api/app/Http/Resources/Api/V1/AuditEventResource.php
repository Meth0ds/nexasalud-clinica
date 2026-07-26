<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditEventResource extends JsonResource
{
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
