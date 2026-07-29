<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['tenant_id', 'code', 'name', 'permissions', 'is_system'])]
/**
 * Defines a tenant-scoped role and its granted permissions.
 */
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $attributes = ['is_system' => false];

    /**
     * Return memberships assigned to this role with tenant-scoped grant metadata.
     */
    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(Membership::class)
            ->withPivot(['tenant_id', 'granted_by', 'granted_at']);
    }

    /**
     * Define permission collection and system-role attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_system' => 'boolean',
        ];
    }
}
