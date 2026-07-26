<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\TenantAccessDeniedException;
use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Identity\OidcAuthenticationService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuthSessionController extends Controller
{
    public function __construct(private OidcAuthenticationService $authentication) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->authentication->currentUser($request);

        if ($user === null) {
            throw new AuthenticationException;
        }

        $tenant = app('currentTenant');
        $membership = app('currentMembership');
        $clinic = app('currentClinic');

        if (! $tenant instanceof Tenant || ! $membership instanceof Membership || ! $clinic instanceof Clinic) {
            throw new TenantAccessDeniedException;
        }

        return response()->json([
            'authenticated' => true,
            'user' => [
                'id' => $user->getKey(),
                'displayName' => $user->display_name,
                'emailHint' => $this->emailHint($user),
                'initials' => $this->initials($user->display_name),
                'professionalTitle' => $this->professionalTitle($membership),
            ],
            'tenant' => [
                'id' => $tenant->getKey(),
                'name' => $tenant->name,
                'clinicId' => $clinic->getKey(),
                'clinicName' => $clinic->name,
            ],
            'permissions' => $this->permissions($membership),
            'idleExpiresAt' => now()->addMinutes($this->idleLifetimeMinutes())->toIso8601String(),
            'absoluteExpiresAt' => $this->absoluteExpiresAt($request)->toIso8601String(),
            'authenticatedAt' => $this->authenticatedAt($request)->toIso8601String(),
            'assuranceLevel' => $this->assuranceLevel($request),
        ]);
    }

    private function emailHint(User $user): string
    {
        $email = $user->email;

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return '';
        }

        [$localPart, $domain] = explode('@', mb_strtolower($email), 2);
        $domainParts = explode('.', $domain);
        $domainLabel = $domainParts[0];
        $topLevelDomain = count($domainParts) > 1 ? '.'.end($domainParts) : '';

        return Str::substr($localPart, 0, 1).'***@'.Str::substr($domainLabel, 0, 1).'***'.$topLevelDomain;
    }

    private function initials(string $displayName): string
    {
        $words = Str::of($displayName)
            ->squish()
            ->explode(' ')
            ->filter()
            ->reject(static fn (string $word): bool => in_array(
                Str::lower(trim($word, '.')),
                ['dr', 'dra', 'sr', 'sra'],
                true,
            ));
        $selectedWords = $words->count() > 1
            ? collect([$words->first(), $words->last()])
            : $words;

        return $selectedWords
            ->map(static fn (string $word): string => Str::upper(Str::substr($word, 0, 1)))
            ->implode('');
    }

    private function professionalTitle(Membership $membership): string
    {
        $role = $membership->roles->first();

        if (! $role instanceof Role || trim($role->name) === '') {
            return 'Profesional de salud';
        }

        return Str::of($role->name)->squish()->limit(80, '')->toString();
    }

    /**
     * @return list<string>
     */
    private function permissions(Membership $membership): array
    {
        $permissions = [];

        foreach ($membership->roles as $role) {
            if (! $role instanceof Role || ! is_array($role->permissions)) {
                continue;
            }

            foreach ($role->permissions as $permission) {
                if (is_string($permission) && preg_match('/^[a-z][a-z0-9._:-]{1,99}$/', $permission) === 1) {
                    $permissions[] = $permission;
                }
            }
        }

        $permissions = array_values(array_unique($permissions));
        sort($permissions);

        return array_slice($permissions, 0, 256);
    }

    private function idleLifetimeMinutes(): int
    {
        $minutes = config('session.lifetime');

        if (! is_int($minutes) || $minutes < 1 || $minutes > 1440) {
            throw new AuthenticationException;
        }

        return $minutes;
    }

    private function absoluteExpiresAt(Request $request): CarbonImmutable
    {
        $expiresAt = $request->session()->get('auth.session.absolute_expires_at');

        if (! is_int($expiresAt)) {
            throw new AuthenticationException;
        }

        return CarbonImmutable::createFromTimestamp($expiresAt)->utc();
    }

    private function assuranceLevel(Request $request): string
    {
        $methods = $request->session()->get('auth.assurance.amr', []);
        $methods = is_array($methods) ? array_values(array_filter($methods, 'is_string')) : [];

        if (array_intersect($methods, ['fido', 'hwk', 'passkey', 'webauthn']) !== []) {
            return 'passkey';
        }

        if (count($methods) > 1 || array_intersect($methods, ['mfa', 'otp', 'sms', 'totp']) !== []) {
            return 'mfa';
        }

        return 'password';
    }

    private function authenticatedAt(Request $request): CarbonImmutable
    {
        $authenticatedAt = $request->session()->get('auth.assurance.authenticated_at');

        if (! is_int($authenticatedAt) || $authenticatedAt < 1 || $authenticatedAt > time() + 300) {
            throw new AuthenticationException;
        }

        return CarbonImmutable::createFromTimestamp($authenticatedAt)->utc();
    }
}
