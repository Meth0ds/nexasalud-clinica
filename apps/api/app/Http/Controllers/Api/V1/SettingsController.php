<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdatePreferencesRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserPreference;
use App\Support\Auditing\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class SettingsController extends Controller
{
    private const KEY = 'workspace';

    public function __construct(private readonly AuditRecorder $audit) {}

    public function show(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        Gate::authorize('viewAny', UserPreference::class);
        $preference = UserPreference::query()
            ->where('user_id', $user->getKey())
            ->where('key', self::KEY)
            ->first();

        return response()->json(['data' => [
            'version' => $preference?->lock_version ?? 0,
            'preferences' => array_replace_recursive($this->defaults(), $preference?->value ?? []),
        ]]);
    }

    public function update(UpdatePreferencesRequest $request): JsonResponse
    {
        $user = $this->actor($request);
        $tenant = $this->tenant();
        $changes = $request->validated();

        $preference = DB::transaction(function () use ($changes, $tenant, $user): UserPreference {
            $preference = UserPreference::query()
                ->where('user_id', $user->getKey())
                ->where('key', self::KEY)
                ->lockForUpdate()
                ->first();

            if (! $preference) {
                return UserPreference::create([
                    'tenant_id' => $tenant->getKey(),
                    'user_id' => $user->getKey(),
                    'key' => self::KEY,
                    'value' => array_replace_recursive($this->defaults(), $changes),
                ]);
            }

            Gate::authorize('update', $preference);
            $preference->value = array_replace_recursive($this->defaults(), $preference->value, $changes);
            $preference->lock_version++;
            $preference->save();

            return $preference;
        });

        $this->audit->record($request, 'preferences.updated', $preference, [
            'changed_section_count' => count($changes),
            'version' => $preference->lock_version,
        ]);

        return response()->json(['data' => [
            'version' => $preference->lock_version,
            'preferences' => $preference->value,
        ]]);
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'theme' => ['mode' => 'system', 'density' => 'comfortable', 'reduceMotion' => false],
            'agenda' => ['defaultView' => 'day'],
            'privacy' => ['autoMask' => true],
            'notifications' => ['desktop' => false],
        ];
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        return $user;
    }

    private function tenant(): Tenant
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        abort_unless($tenant instanceof Tenant, Response::HTTP_FORBIDDEN);

        return $tenant;
    }
}
