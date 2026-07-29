<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\IsAppendOnly;
use Database\Factories\AuditEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'actor_user_id', 'actor_subject', 'event_type', 'auditable_type', 'auditable_id', 'request_id', 'ip_hash', 'user_agent_hash', 'metadata', 'occurred_at'])]
#[Hidden(['ip_hash', 'user_agent_hash', 'metadata'])]
/**
 * Stores an append-only, tenant-scoped audit record with privacy-preserving identifiers.
 */
class AuditEvent extends Model
{
    /** @use HasFactory<AuditEventFactory> */
    use BelongsToTenant, HasFactory, HasUlids, IsAppendOnly;

    public $timestamps = false;

    /**
     * Return the local user who initiated the audited action.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Define encrypted audit metadata and immutable timestamp attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'encrypted:array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
