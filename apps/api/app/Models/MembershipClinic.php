<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'membership_id', 'clinic_id', 'is_active'])]
/**
 * Grants one tenant membership access to one clinical location.
 */
final class MembershipClinic extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'membership_clinic';

    protected $attributes = ['is_active' => true];

    /**
     * Return the membership receiving this clinic grant.
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * Return the clinic covered by this grant.
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * Define activation state casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
