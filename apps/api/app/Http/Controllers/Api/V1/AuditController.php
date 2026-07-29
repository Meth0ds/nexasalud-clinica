<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AuditEventResource;
use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Provides authorized, paginated access to tenant-scoped privacy-preserving audit events.
 */
final class AuditController extends Controller
{
    /**
     * Return validated audit events after enforcing the audit-view authorization policy.
     */
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AuditEvent::class);
        $filters = $request->validate([
            'eventType' => ['nullable', 'string', 'max:120'],
            'actorId' => ['nullable', 'string', 'max:64'],
            'resourceType' => ['nullable', 'string', 'max:160'],
            'resourceId' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'perPage' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $events = AuditEvent::query()
            ->with('actor')
            ->when(isset($filters['eventType']), fn ($query) => $query->where('event_type', $filters['eventType']))
            ->when(isset($filters['actorId']), fn ($query) => $query->where('actor_user_id', $filters['actorId']))
            ->when(isset($filters['resourceType']), fn ($query) => $query->where('auditable_type', $filters['resourceType']))
            ->when(isset($filters['resourceId']), fn ($query) => $query->where('auditable_id', $filters['resourceId']))
            ->when(isset($filters['from']), fn ($query) => $query->where('occurred_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->where('occurred_at', '<=', $filters['to']))
            ->latest('occurred_at')
            ->paginate((int) ($filters['perPage'] ?? 50));

        return AuditEventResource::collection($events);
    }
}
