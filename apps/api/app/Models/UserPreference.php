<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\UserPreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'user_id', 'key', 'value', 'lock_version'])]
#[Hidden(['value'])]
class UserPreference extends Model
{
    /** @use HasFactory<UserPreferenceFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $attributes = ['lock_version' => 1];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'value' => 'encrypted:array',
            'lock_version' => 'integer',
        ];
    }
}
