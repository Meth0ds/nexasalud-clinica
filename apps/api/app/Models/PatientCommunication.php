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

#[Fillable(['tenant_id', 'patient_id', 'author_user_id', 'consent_record_id', 'channel', 'content_class', 'template_key', 'subject', 'body', 'status', 'scheduled_at', 'queued_at', 'cancelled_at', 'cancellation_reason', 'compliance_snapshot', 'lock_version'])]
#[Hidden(['body', 'cancellation_reason', 'compliance_snapshot'])]
final class PatientCommunication extends Model
{
    /** @use HasFactory<PatientCommunicationFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $attributes = [
        'status' => CommunicationStatus::Draft->value,
        'content_class' => CommunicationContentClass::Administrative->value,
        'lock_version' => 1,
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function consentRecord(): BelongsTo
    {
        return $this->belongsTo(ConsentRecord::class);
    }

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
