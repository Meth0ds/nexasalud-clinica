<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['oidc_issuer', 'oidc_subject', 'display_name', 'email', 'is_active', 'last_login_at', 'sessions_revoked_at'])]
#[Hidden(['email'])]
/**
 * Represents a locally persisted identity resolved from the trusted OIDC provider.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable;

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Return all tenant memberships associated with this identity.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Return active memberships available to this identity.
     */
    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('is_active', true);
    }

    /**
     * Return appointments for which this user is the assigned clinician.
     */
    public function clinicalAppointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'clinician_user_id');
    }

    /**
     * Return prescriptions issued by this user.
     */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class, 'prescriber_user_id');
    }

    /**
     * Return portal patient records linked to this identity.
     */
    public function patientLinks(): HasMany
    {
        return $this->hasMany(PatientUserLink::class);
    }

    /**
     * Define encrypted identity data and immutable security timestamps.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email' => 'encrypted',
            'is_active' => 'boolean',
            'last_login_at' => 'immutable_datetime',
            'sessions_revoked_at' => 'immutable_datetime',
        ];
    }
}
