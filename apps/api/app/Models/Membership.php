<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['tenant_id', 'user_id', 'is_active', 'joined_at'])]
/**
 * Links a user to one tenant and the roles granted within that tenant.
 */
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $attributes = ['is_active' => true];

    /**
     * Return the user who holds this membership.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Return roles granted to this membership with tenant-scoped grant metadata.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withPivot(['tenant_id', 'granted_by', 'granted_at']);
    }

    /**
     * Define membership status and immutable join-date casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'joined_at' => 'immutable_datetime',
        ];
    }
}
