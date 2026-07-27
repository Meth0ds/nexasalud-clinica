<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes a portal communication for its verified patient recipient only.
 *
 * The controller establishes ownership before this resource decrypts the message subject and body.
 */
final class PortalMessageResource extends JsonResource
{
    /**
     * Returns the minimum message payload needed by the patient portal without staff-only identifiers.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'subject' => $this->subject,
            'body' => $this->body,
            'contentClass' => $this->content_class->value,
            'authorDisplayName' => $this->author?->display_name,
            'publishedAt' => ($this->queued_at ?? $this->created_at)?->toIso8601String(),
        ];
    }
}
