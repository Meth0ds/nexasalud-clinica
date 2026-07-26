<?php

declare(strict_types=1);

namespace App\Support\Auditing;

use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class AuditRecorder
{
    /**
     * @param  array<string, bool|int|string|null>  $metadata
     */
    public function record(
        Request $request,
        string $eventType,
        ?Model $resource = null,
        array $metadata = [],
    ): ?AuditEvent {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        $actor = $request->user();

        if (! $tenant instanceof Tenant || ! $actor instanceof User) {
            return null;
        }

        $requestId = $request->attributes->get('trace_id');
        $ip = $request->ip();
        $userAgent = $request->userAgent();

        return AuditEvent::create([
            'tenant_id' => $tenant->getKey(),
            'actor_user_id' => $actor->getKey(),
            'actor_subject' => $actor->oidc_subject,
            'event_type' => $eventType,
            'auditable_type' => $resource ? $resource::class : null,
            'auditable_id' => $resource?->getKey(),
            'request_id' => is_string($requestId) ? $requestId : null,
            'ip_hash' => is_string($ip) && $ip !== '' ? $this->fingerprint($tenant, $ip) : null,
            'user_agent_hash' => is_string($userAgent) && $userAgent !== '' ? $this->fingerprint($tenant, $userAgent) : null,
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now(),
        ]);
    }

    private function fingerprint(Tenant $tenant, string $value): string
    {
        return hash_hmac(
            'sha256',
            $value,
            (string) config('app.key').':'.$tenant->getKey(),
        );
    }
}
