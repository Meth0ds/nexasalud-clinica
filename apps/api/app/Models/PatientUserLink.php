<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'user_id', 'patient_id', 'relationship', 'is_active', 'verified_at', 'access_expires_at', 'verified_by'])]
/**
 * Links a portal identity to an authorized patient relationship for a bounded access period.
 */
final class PatientUserLink extends Model
{
    use BelongsToTenant, HasUlids;

    /**
     * Return the portal user identity granted this relationship.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Return the patient whose portal data may be accessed through this relationship.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Define activation and immutable verification and access-expiry timestamp casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'verified_at' => 'immutable_datetime',
            'access_expires_at' => 'immutable_datetime',
        ];
    }
}
