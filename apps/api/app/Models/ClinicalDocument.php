<?php

namespace App\Models;

use App\Enums\ClinicalDocumentStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ClinicalDocumentFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'patient_id', 'appointment_id', 'prescription_id', 'consent_record_id', 'document_type', 'status', 'sha256', 'template_version', 'storage_key', 'metadata', 'revision', 'generated_by', 'generated_at', 'expires_at', 'voided_at'])]
#[Hidden(['storage_key'])]
class ClinicalDocument extends Model
{
    /** @use HasFactory<ClinicalDocumentFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $attributes = [
        'status' => ClinicalDocumentStatus::Generating->value,
        'revision' => 1,
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function consentRecord(): BelongsTo
    {
        return $this->belongsTo(ConsentRecord::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function recordAccess(User $actor, string $action, ?string $requestId = null): AuditEvent
    {
        if (! in_array($action, ['clinical_document.viewed', 'clinical_document.downloaded'], true)) {
            throw new DomainException('Unsupported clinical document access event.');
        }

        return AuditEvent::create([
            'tenant_id' => $this->tenant_id,
            'actor_user_id' => $actor->getKey(),
            'actor_subject' => $actor->oidc_subject,
            'event_type' => $action,
            'auditable_type' => self::class,
            'auditable_id' => $this->getKey(),
            'request_id' => $requestId,
            'metadata' => [
                'document_type' => $this->document_type,
                'revision' => $this->revision,
            ],
        ]);
    }

    protected static function booted(): void
    {
        static::saving(function (self $document): void {
            if ($document->sha256 !== null && ! preg_match('/^[a-f0-9]{64}$/', $document->sha256)) {
                throw new DomainException('Clinical document hash must be a lowercase SHA-256 digest.');
            }

            if (str_starts_with($document->storage_key, '/') || str_contains($document->storage_key, '://')) {
                throw new DomainException('Clinical document storage keys must be opaque and non-public.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ClinicalDocumentStatus::class,
            'metadata' => 'array',
            'revision' => 'integer',
            'generated_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }
}
