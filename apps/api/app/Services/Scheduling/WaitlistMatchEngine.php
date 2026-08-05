<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Enums\AppointmentStatus;
use App\Enums\WaitlistStatus;
use App\Models\Appointment;
use App\Models\AppointmentSlotHold;
use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\Membership;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\WaitlistEntry;
use App\Support\Auditing\AuditRecorder;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Matches waiting waitlist entries to free appointment slots and creates time-bounded offers.
 *
 * The engine is intentionally conservative: it respects the entry's requested
 * window, preferred days and clinician, never offers a slot closer than the
 * configured lead time and is bounded by a hard per-run budget. Every offer is
 * created atomically under a row lock and audited without an HTTP request.
 */
final class WaitlistMatchEngine
{
    /**
     * Create the matching engine with privacy-preserving system auditing.
     */
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Scan waiting entries for one clinic and offer the first free matching slot to each.
     *
     * @return array{scanned: int, offered: int, failed: int}
     */
    public function match(Tenant $tenant, Clinic $clinic, int $budget): array
    {
        if ($budget <= 0 || ! (bool) config('waitlist.matching.enabled')) {
            return ['scanned' => 0, 'offered' => 0, 'failed' => 0];
        }

        $entries = WaitlistEntry::query()
            ->with(['appointmentType'])
            ->where('clinic_id', $clinic->getKey())
            ->where('status', WaitlistStatus::Waiting)
            ->where('desired_to', '>', now())
            ->orderBy('priority')
            ->oldest('created_at')
            ->limit((int) config('waitlist.matching.max_entries_per_run', 200))
            ->get();

        $offered = 0;
        $failed = 0;

        foreach ($entries as $entry) {
            if ($offered >= $budget) {
                break;
            }

            try {
                if ($this->offerIfSlotFound($entry, $clinic)) {
                    $offered++;
                }
            } catch (Throwable $exception) {
                $failed++;
                Log::warning('waitlist.matching.entry_failed', [
                    'event' => 'waitlist.matching.entry_failed',
                    'entry_id' => $entry->getKey(),
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return ['scanned' => $entries->count(), 'offered' => $offered, 'failed' => $failed];
    }

    /**
     * Find a free matching slot for a waiting entry and create the offer atomically.
     */
    private function offerIfSlotFound(WaitlistEntry $entry, Clinic $clinic): bool
    {
        $type = $entry->appointmentType;

        if (! $type instanceof AppointmentType || ! $type->is_active) {
            return false;
        }

        if (! $this->patientActiveInClinic($entry, $clinic)) {
            return false;
        }

        $providers = $this->clinicalProviders($clinic, $entry->preferred_clinician_user_id);

        if ($providers->isEmpty()) {
            return false;
        }

        $slot = $this->firstFreeSlot($entry, $type, $clinic, $providers);

        if ($slot === null) {
            return false;
        }

        return $this->createOfferAtomically($entry, $clinic, $type, $slot);
    }

    /**
     * Persist the provisional appointment and offered state under a row lock.
     *
     * @param  array<string, CarbonInterface|string|null>  $slot
     */
    private function createOfferAtomically(
        WaitlistEntry $entry,
        Clinic $clinic,
        AppointmentType $type,
        array $slot,
    ): bool {
        return DB::transaction(function () use ($entry, $clinic, $type, $slot): bool {
            $locked = WaitlistEntry::query()->lockForUpdate()->find($entry->getKey());

            if (! $locked instanceof WaitlistEntry || $locked->status !== WaitlistStatus::Waiting) {
                return false;
            }

            if (! $this->slotIsFree(
                $locked->patient_id,
                (string) $slot['clinician_user_id'],
                is_string($slot['room_id']) ? $slot['room_id'] : null,
                $slot['starts_at'],
                $slot['ends_at'],
            )) {
                return false;
            }

            $appointment = Appointment::create([
                'tenant_id' => $clinic->tenant_id,
                'clinic_id' => $clinic->getKey(),
                'room_id' => is_string($slot['room_id']) ? $slot['room_id'] : null,
                'appointment_type_id' => $type->getKey(),
                'patient_id' => $locked->patient_id,
                'clinician_user_id' => (string) $slot['clinician_user_id'],
                'starts_at' => $slot['starts_at'],
                'ends_at' => $slot['ends_at'],
                'channel' => (string) $slot['channel'],
                'reason' => 'Oferta automática de lista de espera',
                'created_by' => null,
                'updated_by' => null,
            ]);

            $expiresInMinutes = (int) config('waitlist.matching.offer_valid_minutes', 1440);
            $locked->status = WaitlistStatus::Offered;
            $locked->offered_at = now();
            $locked->offer_expires_at = now()->addMinutes($expiresInMinutes);
            $locked->offer_seen_at = null;
            $locked->fulfilled_appointment_id = $appointment->getKey();
            $locked->offer_created_appointment = true;
            $locked->lock_version++;
            $locked->save();

            $this->audit->recordSystem('waitlist.offer_created_automatically', $locked, [
                'appointment_id' => $appointment->getKey(),
                'expires_in_minutes' => $expiresInMinutes,
                'source' => 'matching_engine',
            ]);

            return true;
        });
    }

    /**
     * Return the first free slot within the entry window and configured workday.
     *
     * @param  Collection<int, Membership>  $providers
     * @return array<string, CarbonInterface|string|null>|null
     */
    private function firstFreeSlot(
        WaitlistEntry $entry,
        AppointmentType $type,
        Clinic $clinic,
        Collection $providers,
    ): ?array {
        AppointmentSlotHold::query()
            ->where('status', 'active')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        $clinicTimezone = $clinic->timezone;
        $minStart = now($clinicTimezone)->addHours((int) config('waitlist.matching.min_lead_hours', 4));
        $horizon = now($clinicTimezone)->addDays((int) config('waitlist.matching.lookahead_days', 30));

        $from = $entry->desired_from->setTimezone($clinicTimezone);
        $to = $entry->desired_to->setTimezone($clinicTimezone);

        if ($horizon->lessThan($to)) {
            $to = $horizon;
        }

        $fromDay = $from->startOfDay();
        $toDay = $to->startOfDay();

        // Never scan calendar days before the configured lead time, even when the
        // requested window started in the past (long-waiting entries).
        $minLeadDay = $minStart->copy()->startOfDay();
        if ($fromDay->lt($minLeadDay)) {
            $fromDay = $minLeadDay;
        }

        if ($toDay->lt($fromDay)) {
            return null;
        }

        $room = Room::query()
            ->where('clinic_id', $clinic->getKey())
            ->where('is_active', true)
            ->orderBy('name')
            ->first();

        if ($type->requires_room && ! $room instanceof Room) {
            return null;
        }

        $preferredDays = array_map('intval', $entry->preferred_days ?? []);
        $stepMinutes = max(15, (int) $type->duration_minutes);
        $workdayStart = (string) config('waitlist.matching.workday_start', '08:00');
        $workdayEnd = (string) config('waitlist.matching.workday_end', '18:00');
        $channel = 'in_person';

        $occupied = $this->occupiedIntervals($entry, $clinic, $providers, $room, $fromDay->utc(), $to->utc());

        for ($day = $fromDay; $day->lte($toDay); $day = $day->addDay()) {
            if ($preferredDays !== [] && ! in_array($day->dayOfWeekIso, $preferredDays, true)) {
                continue;
            }

            if ($preferredDays === [] && $day->isWeekend()) {
                continue;
            }

            $cursor = $day->setTimeFromTimeString($workdayStart);
            $lastStart = $day->setTimeFromTimeString($workdayEnd)->subMinutes((int) $type->duration_minutes);

            while ($cursor->lte($lastStart)) {
                $start = $cursor->copy();
                $end = $start->addMinutes((int) $type->duration_minutes);

                if ($start->gte($minStart) && $end->lte($to)) {
                    foreach ($providers as $provider) {
                        $conflict = $occupied->contains(
                            fn ($item): bool => $item['starts_at']->lt($end)
                                && $item['ends_at']->gt($start)
                                && (
                                    $item['patient_id'] === $entry->patient_id
                                    || $item['clinician_user_id'] === $provider->user_id
                                    || ($room instanceof Room && $item['room_id'] === $room->getKey())
                                ),
                        );

                        if (! $conflict) {
                            return [
                                'clinician_user_id' => (string) $provider->user_id,
                                'room_id' => $room?->getKey(),
                                'starts_at' => $start->utc(),
                                'ends_at' => $end->utc(),
                                'channel' => $channel,
                            ];
                        }
                    }
                }

                $cursor = $cursor->addMinutes($stepMinutes);
            }
        }

        return null;
    }

    /**
     * Load every availability-blocking appointment and active hold overlapping the search range.
     *
     * @param  Collection<int, Membership>  $providers
     * @return Collection<int, array{patient_id: string, clinician_user_id: string, room_id: string|null, starts_at: CarbonInterface, ends_at: CarbonInterface}>
     */
    private function occupiedIntervals(
        WaitlistEntry $entry,
        Clinic $clinic,
        Collection $providers,
        ?Room $room,
        CarbonInterface $rangeStart,
        CarbonInterface $rangeEnd,
    ): Collection {
        $providerIds = $providers->pluck('user_id')->all();
        $blockingStatuses = array_map(
            static fn (AppointmentStatus $status): string => $status->value,
            array_filter(AppointmentStatus::cases(), static fn (AppointmentStatus $status): bool => $status->blocksAvailability()),
        );

        $appointments = Appointment::query()
            ->where('starts_at', '<', $rangeEnd)
            ->where('ends_at', '>', $rangeStart)
            ->whereIn('status', $blockingStatuses)
            ->where(fn ($query) => $query
                ->where('patient_id', $entry->patient_id)
                ->orWhereIn('clinician_user_id', $providerIds)
                ->when($room instanceof Room, fn ($query) => $query->orWhere('room_id', $room->getKey())))
            ->get(['patient_id', 'clinician_user_id', 'room_id', 'starts_at', 'ends_at']);

        $holds = AppointmentSlotHold::query()
            ->where('status', 'active')
            ->where('starts_at', '<', $rangeEnd)
            ->where('ends_at', '>', $rangeStart)
            ->get(['patient_id', 'clinician_user_id', 'room_id', 'starts_at', 'ends_at']);

        return $appointments->concat($holds);
    }

    /**
     * Determine whether the slot is free of appointments and active holds for patient, clinician and room.
     */
    private function slotIsFree(
        string $patientId,
        string $clinicianId,
        ?string $roomId,
        CarbonInterface $start,
        CarbonInterface $end,
    ): bool {
        $blockingStatuses = array_map(
            static fn (AppointmentStatus $status): string => $status->value,
            array_filter(AppointmentStatus::cases(), static fn (AppointmentStatus $status): bool => $status->blocksAvailability()),
        );

        $appointmentConflict = Appointment::query()
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->whereIn('status', $blockingStatuses)
            ->where(fn ($query) => $query
                ->where('patient_id', $patientId)
                ->orWhere('clinician_user_id', $clinicianId)
                ->when($roomId !== null, fn ($query) => $query->orWhere('room_id', $roomId)))
            ->exists();

        $holdConflict = AppointmentSlotHold::query()
            ->where('status', 'active')
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->where(fn ($query) => $query
                ->where('patient_id', $patientId)
                ->orWhere('clinician_user_id', $clinicianId)
                ->when($roomId !== null, fn ($query) => $query->orWhere('room_id', $roomId)))
            ->exists();

        return ! $appointmentConflict && ! $holdConflict;
    }

    /**
     * Return the active clinical memberships of the clinic, optionally restricted to one practitioner.
     *
     * @return Collection<int, Membership>
     */
    private function clinicalProviders(Clinic $clinic, ?string $preferredUserId): Collection
    {
        return Membership::query()
            ->with(['user', 'roles'])
            ->where('is_active', true)
            ->whereExists(function ($grantQuery) use ($clinic): void {
                $grantQuery->selectRaw('1')
                    ->from('membership_clinic')
                    ->whereColumn('membership_clinic.membership_id', 'memberships.id')
                    ->where('membership_clinic.clinic_id', $clinic->getKey())
                    ->where('membership_clinic.tenant_id', $clinic->tenant_id)
                    ->where('membership_clinic.is_active', true);
            })
            ->when($preferredUserId !== null, fn ($query) => $query->where('user_id', $preferredUserId))
            ->get()
            ->filter(fn (Membership $membership): bool => $membership->user?->is_active === true && $membership->roles->contains(
                fn ($role): bool => collect($role->permissions)->contains(
                    fn ($permission): bool => is_string($permission) && (
                        $permission === '*'
                        || $permission === 'appointments.write'
                        || $permission === 'appointments.*'
                        || $permission === 'prescriptions.write'
                        || $permission === 'prescriptions.*'
                    ),
                ),
            ))
            ->values();
    }

    /**
     * Require that the patient is still explicitly linked to the active clinic.
     */
    private function patientActiveInClinic(WaitlistEntry $entry, Clinic $clinic): bool
    {
        return DB::table('clinic_patient')
            ->where('tenant_id', $clinic->tenant_id)
            ->where('clinic_id', $clinic->getKey())
            ->where('patient_id', $entry->patient_id)
            ->where('is_active', true)
            ->exists();
    }
}
