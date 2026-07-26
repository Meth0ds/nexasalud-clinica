<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PatientCoverageFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'patient_id', 'insurer_id', 'member_number_hash', 'member_number', 'policy_number', 'valid_from', 'valid_until', 'is_primary', 'is_active'])]
#[Hidden(['member_number', 'policy_number'])]
class PatientCoverage extends Model
{
    /** @use HasFactory<PatientCoverageFactory> */
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'is_primary' => false,
        'is_active' => true,
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }

    protected static function booted(): void
    {
        $synchronizeBlindIndex = function (self $coverage): void {
            $tenantId = $coverage->getAttribute('tenant_id');
            $memberNumber = $coverage->getAttribute('member_number');

            if (! is_string($tenantId) || ! is_string($memberNumber)) {
                throw new DomainException('Coverage tenant and member number are required.');
            }

            $coverage->setAttribute('member_number_hash', Patient::blindIndex($tenantId, $memberNumber));
        };

        static::creating($synchronizeBlindIndex);
        static::updating(function (self $coverage) use ($synchronizeBlindIndex): void {
            if ($coverage->isDirty(['tenant_id', 'member_number'])) {
                $synchronizeBlindIndex($coverage);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'member_number' => 'encrypted',
            'policy_number' => 'encrypted',
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
