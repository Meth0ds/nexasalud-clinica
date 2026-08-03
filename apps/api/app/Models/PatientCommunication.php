<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationContentClass;
use App\Enums\CommunicationStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PatientCommunicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'clinic_id', 'patient_id', 'author_user_id', 'consent_record_id', 'channel', 'content_class', 'template_key', 'subject', 'body', 'status', 'scheduled_at', 'queued_at', 'cancelled_at', 'cancellation_reason', 'compliance_snapshot', 'lock_version'])]
#[Hidden(['body', 'cancellation_reason', 'compliance_snapshot'])]
/**
 * Represents a consent-aware patient communication with encrypted content and compliance metadata.
 */
final class PatientCommunication extends Model
{
    /** @use HasFactory<PatientCommunicationFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $attributes = [
        'status' => CommunicationStatus::Draft->value,
        'content_class' => CommunicationContentClass::Administrative->value,
        'lock_version' => 1,
    ];

    /**
     * Return the patient to whom this communication is directed.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Return the clinic owning this communication thread.
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * Return the user who created this communication.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * Return the consent evidence authorizing this communication, when required.
     */
    public function consentRecord(): BelongsTo
    {
        return $this->belongsTo(ConsentRecord::class);
    }

    /**
     * Define communication enums, encrypted content and immutable lifecycle timestamp casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => CommunicationChannel::class,
            'content_class' => CommunicationContentClass::class,
            'status' => CommunicationStatus::class,
            'subject' => 'encrypted',
            'body' => 'encrypted',
            'scheduled_at' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'cancellation_reason' => 'encrypted',
            'compliance_snapshot' => 'encrypted:array',
            'lock_version' => 'integer',
        ];
    }
}
