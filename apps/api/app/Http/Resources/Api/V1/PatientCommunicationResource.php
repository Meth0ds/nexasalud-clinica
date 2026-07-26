<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\CommunicationChannel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

final class PatientCommunicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $patientName = trim((string) ($this->patient?->first_name ?? '').' '.(string) ($this->patient?->last_name ?? ''));
        $templateLabel = $this->channel === CommunicationChannel::Portal
            ? null
            : self::templateLabel((string) $this->template_key);

        return [
            'id' => $this->getKey(),
            'version' => $this->lock_version,
            'patientId' => $this->patient_id,
            'patientDisplayName' => $patientName !== '' ? $patientName : 'Paciente no disponible',
            'patientInitials' => Str::of($patientName)->explode(' ')->filter()->take(2)->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))->join(''),
            'channel' => $this->channel->value,
            'contentClass' => $this->content_class->value,
            'status' => $this->status->value,
            'subject' => $this->subject ?? $templateLabel,
            'preview' => $this->channel === CommunicationChannel::Portal
                ? Str::limit((string) $this->body, 140)
                : 'Aviso administrativo genérico sin datos clínicos.',
            'templateKey' => $this->template_key,
            'scheduledAt' => $this->scheduled_at?->toIso8601String(),
            'queuedAt' => $this->queued_at?->toIso8601String(),
            'cancelledAt' => $this->cancelled_at?->toIso8601String(),
            'authorDisplayName' => $this->author?->display_name,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }

    private static function templateLabel(string $templateKey): string
    {
        return match ($templateKey) {
            'appointment_reminder' => 'Recordatorio de cita',
            'appointment_change' => 'Actualización de cita',
            'billing_notification' => 'Aviso administrativo de facturación',
            default => 'Nueva notificación del centro',
        };
    }
}
