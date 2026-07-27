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
class Insurer extends Model
{
    /** @use HasFactory<InsurerFactory> */
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $attributes = ['is_active' => true];

    public function coverages(): HasMany
    {
        return $this->hasMany(PatientCoverage::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
