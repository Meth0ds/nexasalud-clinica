<?php

namespace App\Services\Identity;

use App\Exceptions\IdentityAccessDeniedException;
use App\Exceptions\IdentityProtocolException;
use App\Models\User;
use App\Services\Identity\Data\OidcIdentity;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a verified OpenID Connect identity to an active local user.
 */
final class OidcUserResolver
{
    /**
     * Create or update the local user that belongs to the verified identity.
     */
    public function resolve(OidcIdentity $identity): User
    {
        if (
            mb_strlen($identity->issuer) > 255 ||
            mb_strlen($identity->subject) > 255 ||
            mb_strlen($identity->displayName) > 120
        ) {
            throw new IdentityProtocolException;
        }

        return DB::transaction(function () use ($identity): User {
            $user = User::query()->firstOrCreate(
                [
                    'oidc_issuer' => $identity->issuer,
                    'oidc_subject' => $identity->subject,
                ],
                [
                    'display_name' => $identity->displayName,
                    'email' => $identity->email,
                    'is_active' => true,
                ],
            );

            if (! $user->is_active) {
                throw new IdentityAccessDeniedException;
            }

            $user->fill([
                'display_name' => $identity->displayName,
                'email' => $identity->email,
                'last_login_at' => now(),
            ])->save();

            return $user;
        });
    }
}
