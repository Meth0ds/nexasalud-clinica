<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\InsurerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'code', 'name', 'provider_reference', 'is_active'])]
/**
 * Represents a tenant-scoped insurance provider available for patient coverage records.
 */
class Insurer extends Model
{
    /** @use HasFactory<InsurerFactory> */
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $attributes = ['is_active' => true];

    /**
     * Return patient coverage records supplied by this insurer.
     */
    public function coverages(): HasMany
    {
        return $this->hasMany(PatientCoverage::class);
    }

    /**
     * Define the insurer activation attribute cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
