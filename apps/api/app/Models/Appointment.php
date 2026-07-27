<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\AppointmentFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

#[Fillable(['tenant_id', 'clinic_id', 'room_id', 'appointment_type_id', 'patient_id', 'clinician_user_id', 'starts_at', 'ends_at', 'status', 'channel', 'reason', 'clinical_notes', 'lock_version', 'created_by', 'updated_by', 'cancelled_at'])]
#[Hidden(['reason', 'clinical_notes'])]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'status' => AppointmentStatus::Scheduled->value,
        'channel' => 'in_person',
        'lock_version' => 1,
    ];

    private bool $allowsScheduleMutation = false;

    private bool $allowsStatusMutation = false;

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function appointmentType(): BelongsTo
    {
        return $this->belongsTo(AppointmentType::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function clinician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'clinician_user_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(AppointmentStatusHistory::class)->orderBy('occurred_at');
    }

    public function changeHistory(): HasMany
    {
        return $this->hasMany(AppointmentChangeHistory::class)->orderBy('occurred_at');
    }

    public function transitionTo(AppointmentStatus $next, User $actor, ?string $reason = null): self
    {
        return DB::transaction(function () use ($next, $actor, $reason): self {
            /** @var self $appointment */
            $appointment = self::query()->lockForUpdate()->findOrFail($this->getKey());
            $current = $appointment->status;

            if (! $current->canTransitionTo($next)) {
                throw new DomainException("Appointment cannot transition from {$current->value} to {$next->value}.");
            }

            $appointment->allowsStatusMutation = true;
            $appointment->status = $next;
            $appointment->updated_by = $actor->getKey();
            $appointment->lock_version++;

            if ($next === AppointmentStatus::Cancelled) {
                $appointment->cancelled_at = now();
            }

            $appointment->save();
            $appointment->statusHistory()->create([
                'tenant_id' => $appointment->tenant_id,
                'from_status' => $current,
                'to_status' => $next,
                'changed_by' => $actor->getKey(),
                'reason' => $reason,
                'context' => ['lock_version' => $appointment->lock_version],
            ]);

            if ($next === AppointmentStatus::Cancelled) {
                $appointment->changeHistory()->create([
                    'tenant_id' => $appointment->tenant_id,
                    'changed_by' => $actor->getKey(),
                    'change_type' => 'cancelled',
                    'from_starts_at' => $appointment->starts_at,
                    'from_ends_at' => $appointment->ends_at,
                    'reason' => $reason,
                ]);
            }

            return $appointment->refresh();
        });
    }

    public function reschedule(
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        User $actor,
        ?Room $room = null,
        ?User $clinician = null,
        ?string $reason = null,
    ): self {
        if (! $endsAt->isAfter($startsAt)) {
            throw new DomainException('Appointment end time must be after its start time.');
        }

        return DB::transaction(function () use ($startsAt, $endsAt, $actor, $room, $clinician, $reason): self {
            /** @var self $appointment */
            $appointment = self::query()->lockForUpdate()->findOrFail($this->getKey());
            $before = [
                'starts_at' => $appointment->starts_at,
                'ends_at' => $appointment->ends_at,
                'room_id' => $appointment->room_id,
                'clinician_user_id' => $appointment->clinician_user_id,
            ];
            $appointment->allowsScheduleMutation = true;
            $appointment->starts_at = $startsAt;
            $appointment->ends_at = $endsAt;
            $appointment->room_id = $room?->getKey() ?? $appointment->room_id;
            $appointment->clinician_user_id = $clinician?->getKey() ?? $appointment->clinician_user_id;
            $appointment->updated_by = $actor->getKey();
            $appointment->lock_version++;
            $appointment->save();
            $appointment->changeHistory()->create([
                'tenant_id' => $appointment->tenant_id,
                'changed_by' => $actor->getKey(),
                'change_type' => 'rescheduled',
                'from_starts_at' => $before['starts_at'],
                'from_ends_at' => $before['ends_at'],
                'to_starts_at' => $appointment->starts_at,
                'to_ends_at' => $appointment->ends_at,
                'from_room_id' => $before['room_id'],
                'to_room_id' => $appointment->room_id,
                'from_clinician_user_id' => $before['clinician_user_id'],
                'to_clinician_user_id' => $appointment->clinician_user_id,
                'reason' => $reason,
            ]);

            return $appointment->refresh();
        });
    }

    protected static function booted(): void
    {
        static::saving(function (self $appointment): void {
            if (! $appointment->starts_at || ! $appointment->ends_at || ! $appointment->ends_at->isAfter($appointment->starts_at)) {
                throw new DomainException('Appointment end time must be after its start time.');
            }

            $status = $appointment->status instanceof AppointmentStatus
                ? $appointment->status
                : AppointmentStatus::from($appointment->status);

            if (! $status->blocksAvailability()) {
                return;
            }

            $conflict = self::query()
                ->when($appointment->exists, fn (Builder $query) => $query->whereKeyNot($appointment->getKey()))
                ->where('starts_at', '<', $appointment->ends_at)
                ->where('ends_at', '>', $appointment->starts_at)
                ->whereIn('status', array_map(
                    static fn (AppointmentStatus $candidate): string => $candidate->value,
                    array_filter(AppointmentStatus::cases(), static fn (AppointmentStatus $candidate): bool => $candidate->blocksAvailability()),
                ))
                ->where(function (Builder $query) use ($appointment): void {
                    $query->where('patient_id', $appointment->patient_id)
                        ->orWhere('clinician_user_id', $appointment->clinician_user_id);

                    if ($appointment->room_id) {
                        $query->orWhere('room_id', $appointment->room_id);
                    }
                })
                ->exists();

            if ($conflict) {
                throw new DomainException('The appointment overlaps an occupied patient, clinician, or room interval.');
            }
        });

        static::updating(function (self $appointment): void {
            if ($appointment->isDirty('status') && ! $appointment->allowsStatusMutation) {
                throw new DomainException('Appointment status must be changed through transitionTo().');
            }

            if ($appointment->isDirty(['starts_at', 'ends_at', 'room_id', 'clinician_user_id']) && ! $appointment->allowsScheduleMutation) {
                throw new DomainException('Appointment scheduling fields must be changed through reschedule().');
            }
        });

        static::created(function (self $appointment): void {
            $appointment->statusHistory()->create([
                'tenant_id' => $appointment->tenant_id,
                'from_status' => null,
                'to_status' => $appointment->status,
                'changed_by' => $appointment->created_by,
                'context' => ['lock_version' => $appointment->lock_version],
            ]);
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'status' => AppointmentStatus::class,
            'reason' => 'encrypted',
            'clinical_notes' => 'encrypted',
            'lock_version' => 'integer',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
