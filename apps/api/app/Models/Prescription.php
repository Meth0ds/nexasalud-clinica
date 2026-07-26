<?php

namespace App\Models;

use App\Enums\PrescriptionStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PrescriptionFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

#[Fillable(['tenant_id', 'clinic_id', 'patient_id', 'prescriber_user_id', 'appointment_id', 'status', 'external_provider_reference', 'clinical_indication', 'cancellation_reason', 'valid_until', 'signed_at', 'sent_at', 'cancelled_at', 'lock_version'])]
#[Hidden(['clinical_indication', 'cancellation_reason'])]
class Prescription extends Model
{
    /** @use HasFactory<PrescriptionFactory> */
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'status' => PrescriptionStatus::Draft->value,
        'lock_version' => 1,
    ];

    private bool $allowsStatusMutation = false;

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function prescriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescriber_user_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class)->orderBy('sort_order');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(PrescriptionStatusHistory::class)->orderBy('occurred_at');
    }

    public function transitionTo(PrescriptionStatus $next, User $actor, ?string $reason = null): self
    {
        return DB::transaction(function () use ($next, $actor, $reason): self {
            /** @var self $prescription */
            $prescription = self::query()->lockForUpdate()->findOrFail($this->getKey());
            $current = $prescription->status;

            if (! $current->canTransitionTo($next)) {
                throw new DomainException("Prescription cannot transition from {$current->value} to {$next->value}.");
            }

            if ($next === PrescriptionStatus::Signed && ! $prescription->items()->exists()) {
                throw new DomainException('A prescription must contain at least one item before signing.');
            }

            $prescription->allowsStatusMutation = true;
            $prescription->status = $next;
            $prescription->lock_version++;

            if ($next === PrescriptionStatus::Signed) {
                $prescription->signed_at = now();
            } elseif ($next === PrescriptionStatus::Sent) {
                $prescription->sent_at = now();
            } elseif ($next === PrescriptionStatus::Cancelled) {
                $prescription->cancelled_at = now();
                $prescription->cancellation_reason = $reason;
            }

            $prescription->save();
            $prescription->statusHistory()->create([
                'tenant_id' => $prescription->tenant_id,
                'from_status' => $current,
                'to_status' => $next,
                'changed_by' => $actor->getKey(),
                'reason' => $reason,
                'context' => ['lock_version' => $prescription->lock_version],
            ]);

            return $prescription->refresh();
        });
    }

    protected static function booted(): void
    {
        static::updating(function (self $prescription): void {
            if ($prescription->isDirty('status') && ! $prescription->allowsStatusMutation) {
                throw new DomainException('Prescription status must be changed through transitionTo().');
            }
        });

        static::created(function (self $prescription): void {
            $prescription->statusHistory()->create([
                'tenant_id' => $prescription->tenant_id,
                'from_status' => null,
                'to_status' => $prescription->status,
                'changed_by' => $prescription->prescriber_user_id,
                'context' => ['lock_version' => $prescription->lock_version],
            ]);
        });
    }

    protected function casts(): array
    {
        return [
            'status' => PrescriptionStatus::class,
            'clinical_indication' => 'encrypted',
            'cancellation_reason' => 'encrypted',
            'valid_until' => 'immutable_date',
            'signed_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }
}
