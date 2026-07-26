<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\IdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'actor_subject', 'operation', 'key_hash', 'request_hash', 'response_status', 'response_body', 'resource_type', 'resource_id', 'expires_at', 'created_at'])]
#[Hidden(['key_hash', 'request_hash', 'response_body'])]
class IdempotencyKey extends Model
{
    /** @use HasFactory<IdempotencyKeyFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_body' => 'encrypted:array',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
