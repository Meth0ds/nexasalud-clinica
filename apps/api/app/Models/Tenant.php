<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['slug', 'name', 'timezone', 'locale', 'is_active'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'timezone' => 'Europe/Madrid',
        'locale' => 'es',
        'is_active' => true,
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function clinics(): HasMany
    {
        return $this->hasMany(Clinic::class);
    }

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
