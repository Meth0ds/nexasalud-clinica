<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'clinic_id', 'code', 'name', 'capacity', 'supports_video', 'is_active'])]
/**
 * Represents a tenant-scoped clinical room available for appointment scheduling.
 */
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'capacity' => 1,
        'supports_video' => false,
        'is_active' => true,
    ];

    /**
     * Return the clinic that owns this room.
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * Return appointments assigned to this room.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Define room capacity and capability status casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'supports_video' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
