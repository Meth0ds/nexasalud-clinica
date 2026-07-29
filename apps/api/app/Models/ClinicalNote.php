<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClinicalNoteStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'patient_id', 'appointment_id', 'author_user_id', 'supersedes_id', 'status', 'content', 'signature_context', 'signed_at'])]
#[Hidden(['content', 'signature_context'])]
/**
 * Represents an append-only clinical note containing encrypted content and signature context.
 */
final class ClinicalNote extends Model
{
    use BelongsToTenant, HasUlids, IsAppendOnly;

    protected $attributes = [
        'status' => ClinicalNoteStatus::Draft->value,
    ];

    /**
     * Return the patient whose clinical record contains this note.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Return the optional appointment associated with this note.
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * Return the clinician who authored this note.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * Return the prior note superseded by this append-only revision, when applicable.
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /**
     * Define note status, encrypted content and immutable signature timestamp casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ClinicalNoteStatus::class,
            'content' => 'encrypted',
            'signature_context' => 'encrypted:array',
            'signed_at' => 'immutable_datetime',
        ];
    }
}
