<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'clinic_id', 'code', 'name', 'capacity', 'supports_video', 'is_active'])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'capacity' => 1,
        'supports_video' => false,
        'is_active' => true,
    ];

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'supports_video' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
