<?php

namespace App\Http\Resources\Api\V1;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Transform a patient into a privacy-minimized operational API representation.
 */
class PatientResource extends JsonResource
{
    /**
     * Convert the patient resource into an array for the current request.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $displayName = trim((string) $this->first_name.' '.(string) $this->last_name);
        $birthDate = is_string($this->birth_date) ? CarbonImmutable::parse($this->birth_date) : null;
        $coverage = $this->relationLoaded('coverages') ? $this->coverages->first() : null;
        $lastAppointment = $this->relationLoaded('appointments') ? $this->appointments->sortByDesc('starts_at')->first() : null;
        $nextAppointment = $this->relationLoaded('appointments') ? $this->appointments->where('starts_at', '>=', now())->sortBy('starts_at')->first() : null;

        return [
            'id' => $this->getKey(),
            'displayName' => $displayName,
            'initials' => Str::of($displayName)->explode(' ')->filter()->take(2)->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))->join(''),
            'ageRange' => $birthDate ? self::ageRange($birthDate->age) : 'No disponible',
            'phoneHint' => self::mask((string) $this->phone, 4),
            'emailHint' => self::maskEmail((string) $this->email),
            'coverageLabel' => $coverage?->insurer?->name ?? 'Privado',
            'lastVisitLabel' => $lastAppointment?->starts_at?->diffForHumans() ?? 'Sin visitas',
            'nextAppointmentLabel' => $nextAppointment?->starts_at?->toIso8601String(),
            'consentStatus' => $this->relationLoaded('consentRecords') && $this->consentRecords->contains(
                fn ($record): bool => $record->status->value === 'active',
            ) ? 'current' : 'review',
            'assignedPractitioner' => $nextAppointment?->clinician?->display_name ?? 'Sin asignar',
            'alertLabels' => $this->relationLoaded('allergies') && $this->allergies->isNotEmpty() ? ['Alergia registrada'] : [],
            'isActive' => $this->is_active,
        ];
    }

    /**
     * Group an age into a non-identifying ten-year range.
     */
    private static function ageRange(int $age): string
    {
        $lower = intdiv(max(0, $age), 10) * 10;

        return "{$lower}–".($lower + 9).' años';
    }

    /**
     * Mask a value while retaining its trailing characters for operational recognition.
     */
    private static function mask(string $value, int $visible): string
    {
        if ($value === '') {
            return 'No disponible';
        }

        return str_repeat('•', max(4, mb_strlen($value) - $visible)).mb_substr($value, -$visible);
    }

    /**
     * Mask an email address while retaining its domain for operational recognition.
     */
    private static function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return 'No disponible';
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }
}
