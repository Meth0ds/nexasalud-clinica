<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ClinicFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'code', 'name', 'timezone', 'color', 'is_active'])]
/**
 * Represents an active clinical location owned by a tenant.
 */
class Clinic extends Model
{
    /** @use HasFactory<ClinicFactory> */
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'timezone' => 'Europe/Madrid',
        'is_active' => true,
    ];

    /**
     * Return rooms available at this clinic.
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /**
     * Return appointments scheduled at this clinic.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Return memberships with access to this clinic.
     */
    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(Membership::class, 'membership_clinic')
            ->withPivot(['tenant_id', 'is_active'])
            ->wherePivot('is_active', true);
    }

    /**
     * Return patients explicitly linked to this clinic.
     */
    public function patients(): BelongsToMany
    {
        return $this->belongsToMany(Patient::class, 'clinic_patient')
            ->withPivot(['tenant_id', 'is_active', 'first_seen_at'])
            ->wherePivot('is_active', true);
    }

    /**
     * Define the clinic activation attribute cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
