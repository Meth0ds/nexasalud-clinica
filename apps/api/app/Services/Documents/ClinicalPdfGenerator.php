<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Enums\AppointmentStatus;
use App\Enums\ClinicalDocumentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PrescriptionStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicalDocument;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class ClinicalPdfGenerator
{
    public const TEMPLATE_VERSION = 'nexasalud-clinical-v1';

    private const MAX_PDF_BYTES = 10 * 1024 * 1024;

    public function generateAppointmentConfirmation(Appointment $appointment, User $actor, ?ClinicalDocument $target = null): ClinicalDocument
    {
        if (in_array($appointment->status, [AppointmentStatus::Cancelled, AppointmentStatus::NoShow], true)) {
            throw new DomainException('A confirmation cannot be generated for an unavailable appointment.');
        }

        $appointment->loadMissing(['patient.tenant', 'clinic', 'room', 'appointmentType', 'clinician']);
        $patient = $this->requirePatient($appointment->patient);
        $timezone = $appointment->clinic?->timezone ?? $patient->tenant?->timezone ?? 'Europe/Madrid';

        return $this->renderAndStore(
            view: 'documents.appointment-confirmation',
            documentType: 'appointment_confirmation',
            patient: $patient,
            actor: $actor,
            clinicId: $appointment->clinic_id,
            associations: ['appointment_id' => $appointment->getKey()],
            payload: [
                'organization' => $this->organizationData($patient, $appointment->clinic?->name),
                'patient' => $this->patientData($patient),
                'appointment' => [
                    'date' => $this->localDateTime($appointment->starts_at, $timezone, 'd/m/Y'),
                    'startTime' => $this->localDateTime($appointment->starts_at, $timezone, 'H:i'),
                    'endTime' => $this->localDateTime($appointment->ends_at, $timezone, 'H:i'),
                    'timezone' => $timezone,
                    'type' => $appointment->appointmentType?->name ?? 'Consulta médica',
                    'professional' => $appointment->clinician?->display_name ?? 'Profesional asignado',
                    'clinic' => $appointment->clinic?->name ?? 'Centro asistencial',
                    'room' => $appointment->room?->name,
                    'channel' => $this->appointmentChannelLabel((string) $appointment->channel),
                    'status' => $this->appointmentStatusLabel($appointment->status),
                ],
            ],
            target: $target,
        );
    }

    public function generatePrescriptionPlan(Prescription $prescription, User $actor, ?ClinicalDocument $target = null): ClinicalDocument
    {
        if (! in_array($prescription->status, [PrescriptionStatus::Signed, PrescriptionStatus::Sent], true)
            || $prescription->signed_at === null) {
            throw new DomainException('Only a signed prescription can be rendered as a clinical document.');
        }

        $prescription->loadMissing(['patient.tenant', 'prescriber', 'items', 'appointment']);
        $patient = $this->requirePatient($prescription->patient);
        $clinic = Clinic::query()->find($prescription->clinic_id);

        if ($prescription->items->isEmpty()) {
            throw new DomainException('A prescription document requires at least one medication.');
        }

        $timezone = $clinic?->timezone ?? $patient->tenant?->timezone ?? 'Europe/Madrid';

        return $this->renderAndStore(
            view: 'documents.prescription-plan',
            documentType: 'prescription_plan',
            patient: $patient,
            actor: $actor,
            clinicId: $prescription->clinic_id,
            associations: ['prescription_id' => $prescription->getKey()],
            payload: [
                'organization' => $this->organizationData($patient, $clinic?->name),
                'patient' => $this->patientData($patient),
                'prescription' => [
                    'reference' => $prescription->external_provider_reference,
                    'professional' => $prescription->prescriber?->display_name ?? 'Profesional prescriptor',
                    'signedAt' => $this->localDateTime($prescription->signed_at, $timezone, 'd/m/Y H:i'),
                    'validUntil' => $prescription->valid_until?->format('d/m/Y'),
                    'status' => $this->prescriptionStatusLabel($prescription->status),
                    'indication' => $prescription->clinical_indication,
                    'medications' => $prescription->items->map(fn ($item): array => [
                        'name' => (string) $item->medication_display,
                        'code' => $item->medication_code,
                        'dose' => trim($this->decimal((string) $item->dose_amount).' '.(string) $item->dose_unit_code),
                        'route' => $this->routeLabel((string) $item->route_code),
                        'frequency' => $this->frequencyLabel((string) $item->frequency_code),
                        'duration' => $item->duration_value === null
                            ? null
                            : $item->duration_value.' '.$this->durationUnitLabel((string) $item->duration_unit, $item->duration_value),
                        'quantity' => trim($this->decimal((string) $item->quantity).' '.(string) $item->quantity_unit_code),
                        'instructions' => $item->instructions,
                    ])->all(),
                ],
            ],
            target: $target,
        );
    }

    public function generateInvoice(Invoice $invoice, User $actor, ?ClinicalDocument $target = null): ClinicalDocument
    {
        if (in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Voided], true)
            || $invoice->issued_on === null) {
            throw new DomainException('Only an issued and active invoice can be rendered.');
        }

        $invoice->loadMissing(['patient.tenant', 'clinic', 'items', 'payments']);
        $patient = $this->requirePatient($invoice->patient);

        if ($invoice->items->isEmpty() || $invoice->total_minor <= 0) {
            throw new DomainException('An invoice document requires at least one billable line.');
        }

        return $this->renderAndStore(
            view: 'documents.invoice',
            documentType: 'invoice',
            patient: $patient,
            actor: $actor,
            clinicId: $invoice->clinic_id,
            associations: ['invoice_id' => $invoice->getKey()],
            payload: [
                'organization' => $this->organizationData($patient, $invoice->clinic?->name),
                'patient' => $this->patientData($patient, includeAddress: true),
                'invoice' => [
                    'number' => $invoice->invoice_number,
                    'status' => $this->invoiceStatusLabel($invoice->status),
                    'issuedOn' => $invoice->issued_on->format('d/m/Y'),
                    'dueOn' => $invoice->due_on?->format('d/m/Y'),
                    'currency' => $invoice->currency,
                    'items' => $invoice->items->map(fn ($item): array => [
                        'code' => $item->service_code,
                        'description' => (string) $item->description,
                        'quantity' => $this->decimal((string) $item->quantity),
                        'unitPrice' => $this->money($item->unit_price_minor, $invoice->currency),
                        'tax' => $this->money($item->tax_minor, $invoice->currency),
                        'total' => $this->money($item->total_minor, $invoice->currency),
                    ])->all(),
                    'subtotal' => $this->money($invoice->subtotal_minor, $invoice->currency),
                    'tax' => $this->money($invoice->tax_minor, $invoice->currency),
                    'total' => $this->money($invoice->total_minor, $invoice->currency),
                    'balance' => $this->money($invoice->balance_minor, $invoice->currency),
                ],
            ],
            target: $target,
        );
    }

    /**
     * Generate a clearly non-fiscal invoice preview for a demonstration environment.
     */
    public function generateDemoInvoicePreview(Invoice $invoice, User $actor, ?ClinicalDocument $target = null): ClinicalDocument
    {
        $invoice->loadMissing(['patient.tenant', 'clinic', 'items']);
        $patient = $this->requirePatient($invoice->patient);

        return $this->renderAndStore(
            view: 'documents.demo-invoice-preview',
            documentType: 'demo_invoice_preview',
            patient: $patient,
            actor: $actor,
            clinicId: $invoice->clinic_id,
            associations: ['invoice_id' => $invoice->getKey()],
            payload: [
                'organization' => $this->organizationData($patient, $invoice->clinic?->name),
                'patient' => $this->patientData($patient),
                'invoice' => [
                    'number' => $invoice->invoice_number,
                    'status' => $this->invoiceStatusLabel($invoice->status),
                    'issuedOn' => $invoice->issued_on?->format('d/m/Y'),
                    'dueOn' => $invoice->due_on?->format('d/m/Y'),
                    'currency' => $invoice->currency,
                    'items' => $invoice->items->map(fn ($item): array => [
                        'description' => (string) $item->description,
                        'quantity' => $this->decimal((string) $item->quantity),
                        'total' => $this->money($item->total_minor, $invoice->currency),
                    ])->all(),
                    'total' => $this->money($invoice->total_minor, $invoice->currency),
                    'balance' => $this->money($invoice->balance_minor, $invoice->currency),
                ],
            ],
            target: $target,
        );
    }

    /**
     * Create an opaque pending document for asynchronous appointment rendering.
     */
    public function queueAppointmentConfirmation(Appointment $appointment, User $actor): ClinicalDocument
    {
        if (in_array($appointment->status, [AppointmentStatus::Cancelled, AppointmentStatus::NoShow], true)) {
            throw new DomainException('A confirmation cannot be generated for an unavailable appointment.');
        }

        $appointment->loadMissing(['patient.tenant', 'clinic']);
        $patient = $this->requirePatient($appointment->patient);

        return $this->createPendingDocument(
            patient: $patient,
            actor: $actor,
            clinicId: (string) $appointment->clinic_id,
            documentType: 'appointment_confirmation',
            associations: ['appointment_id' => $appointment->getKey()],
            renderSpec: ['type' => 'appointment', 'id' => (string) $appointment->getKey()],
        );
    }

    /**
     * Create an opaque pending document for asynchronous prescription rendering.
     */
    public function queuePrescriptionPlan(Prescription $prescription, User $actor): ClinicalDocument
    {
        if (! in_array($prescription->status, [PrescriptionStatus::Signed, PrescriptionStatus::Sent], true)
            || $prescription->signed_at === null) {
            throw new DomainException('Only a signed prescription can be rendered as a clinical document.');
        }

        $prescription->loadMissing(['patient.tenant', 'items']);
        $patient = $this->requirePatient($prescription->patient);

        if ($prescription->items->isEmpty()) {
            throw new DomainException('A prescription document requires at least one medication.');
        }

        return $this->createPendingDocument(
            patient: $patient,
            actor: $actor,
            clinicId: (string) $prescription->clinic_id,
            documentType: 'prescription_plan',
            associations: ['prescription_id' => $prescription->getKey()],
            renderSpec: ['type' => 'prescription', 'id' => (string) $prescription->getKey()],
        );
    }

    /**
     * Create an opaque pending document for asynchronous invoice rendering.
     */
    public function queueInvoice(Invoice $invoice, User $actor): ClinicalDocument
    {
        if (in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Voided], true)
            || $invoice->issued_on === null) {
            throw new DomainException('Only an issued and active invoice can be rendered.');
        }

        $invoice->loadMissing(['patient.tenant', 'items']);
        $patient = $this->requirePatient($invoice->patient);

        if ($invoice->items->isEmpty() || $invoice->total_minor <= 0) {
            throw new DomainException('An invoice document requires at least one billable line.');
        }

        return $this->createPendingDocument(
            patient: $patient,
            actor: $actor,
            clinicId: (string) $invoice->clinic_id,
            documentType: 'invoice',
            associations: ['invoice_id' => $invoice->getKey()],
            renderSpec: ['type' => 'invoice', 'id' => (string) $invoice->getKey()],
        );
    }

    /**
     * Render a pending document using only IDs persisted in its metadata.
     * No clinical payload is serialized into the queue message.
     */
    public function renderPendingDocument(ClinicalDocument $document): ClinicalDocument
    {
        $render = is_array($document->metadata) ? ($document->metadata['render'] ?? null) : null;
        $type = is_array($render) ? ($render['type'] ?? null) : null;
        $sourceId = is_array($render) ? ($render['id'] ?? null) : null;
        $actor = $document->generatedBy()->first();

        if (! is_string($type) || ! is_string($sourceId) || ! $actor instanceof User) {
            throw new UnexpectedValueException('The clinical document render specification is invalid.');
        }

        $source = match ($type) {
            'appointment' => Appointment::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $document->tenant_id)
                ->where('clinic_id', $document->clinic_id)
                ->whereKey($sourceId)
                ->firstOrFail(),
            'prescription' => Prescription::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $document->tenant_id)
                ->where('clinic_id', $document->clinic_id)
                ->whereKey($sourceId)
                ->firstOrFail(),
            'invoice' => Invoice::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $document->tenant_id)
                ->where('clinic_id', $document->clinic_id)
                ->whereKey($sourceId)
                ->firstOrFail(),
            default => throw new UnexpectedValueException('The clinical document source type is unsupported.'),
        };

        return match ($type) {
            'appointment' => $this->generateAppointmentConfirmation($source, $actor, $document),
            'prescription' => $this->generatePrescriptionPlan($source, $actor, $document),
            'invoice' => $this->generateInvoice($source, $actor, $document),
            default => throw new UnexpectedValueException('The clinical document source type is unsupported.'),
        };
    }

    /**
     * Mark a pending document as failed without exposing exception details.
     */
    public function markPendingDocumentFailed(ClinicalDocument $document): void
    {
        if ($document->status === ClinicalDocumentStatus::Ready) {
            return;
        }

        $this->markFailed($document, [
            'content_type' => 'application/pdf',
            'storage_format' => 'laravel-encrypted-v1',
            'self_contained' => true,
        ]);
    }

    public function verifiedContents(ClinicalDocument $document): string
    {
        if ($document->status !== ClinicalDocumentStatus::Ready
            || $document->voided_at !== null
            || ($document->expires_at !== null && $document->expires_at->isPast())) {
            throw new UnexpectedValueException('The clinical document is not available.');
        }

        $expectedHash = $document->sha256;
        if (! is_string($expectedHash) || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1) {
            throw new UnexpectedValueException('The clinical document has no valid integrity digest.');
        }

        $disk = Storage::disk('clinical');
        if (! $disk->exists($document->storage_key)) {
            throw new UnexpectedValueException('The clinical document object is missing.');
        }

        $encryptedContents = $disk->get($document->storage_key);

        try {
            $contents = Crypt::decryptString($encryptedContents);
        } catch (DecryptException $exception) {
            throw new UnexpectedValueException('The clinical document object could not be authenticated.', previous: $exception);
        }

        if (! str_starts_with($contents, '%PDF-')
            || ! hash_equals($expectedHash, hash('sha256', $contents))) {
            throw new UnexpectedValueException('The clinical document integrity check failed.');
        }

        return $contents;
    }

    public function safeDownloadName(ClinicalDocument $document): string
    {
        $prefix = match ($document->document_type) {
            'appointment_confirmation' => 'confirmacion-cita',
            'prescription_plan' => 'plan-medicacion',
            'invoice' => 'factura',
            'demo_invoice_preview' => 'vista-factura-simulada',
            default => 'documento-clinico',
        };
        $date = $document->generated_at?->format('Ymd') ?? 'sin-fecha';
        $shortId = Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $document->getKey()) ?? '', -10));

        return $prefix.'-'.$date.'-'.$shortId.'.pdf';
    }

    /**
     * @param  array<string, string|null>  $associations
     * @param  array<string, string>  $renderSpec
     */
    private function createPendingDocument(
        Patient $patient,
        User $actor,
        string $clinicId,
        string $documentType,
        array $associations,
        array $renderSpec,
    ): ClinicalDocument {
        if ($clinicId === '') {
            throw new DomainException('A clinical document requires a clinic context.');
        }

        $pending = ClinicalDocument::query()
            ->where('tenant_id', $patient->tenant_id)
            ->where('clinic_id', $clinicId)
            ->where('generated_by', $actor->getKey())
            ->where('status', ClinicalDocumentStatus::Generating)
            ->count();

        if ($pending >= (int) config('documents.max_pending_per_actor', 3)) {
            throw new DomainException('The clinical document generation quota has been reached.');
        }

        $metadata = [
            'content_type' => 'application/pdf',
            'storage_format' => 'laravel-encrypted-v1',
            'self_contained' => true,
            'render' => $renderSpec,
        ];

        return ClinicalDocument::create([
            'tenant_id' => $patient->tenant_id,
            'clinic_id' => $clinicId,
            'patient_id' => $patient->getKey(),
            ...$associations,
            'document_type' => $documentType,
            'status' => ClinicalDocumentStatus::Generating,
            'template_version' => self::TEMPLATE_VERSION,
            'storage_key' => $this->opaqueStorageKey(),
            'metadata' => $metadata,
            'generated_by' => $actor->getKey(),
        ]);
    }

    /**
     * @param  array<string, string|null>  $associations
     * @param  array<string, mixed>  $payload
     */
    private function renderAndStore(
        string $view,
        string $documentType,
        Patient $patient,
        User $actor,
        string $clinicId,
        array $associations,
        array $payload,
        ?ClinicalDocument $target = null,
    ): ClinicalDocument {
        $generatedAt = now();
        $storageKey = $this->opaqueStorageKey();
        $metadata = [
            'content_type' => 'application/pdf',
            'storage_format' => 'laravel-encrypted-v1',
            'self_contained' => true,
        ];

        $document = $target ?? ClinicalDocument::create([
            'tenant_id' => $patient->tenant_id,
            'clinic_id' => $clinicId,
            'patient_id' => $patient->getKey(),
            ...$associations,
            'document_type' => $documentType,
            'status' => ClinicalDocumentStatus::Generating,
            'template_version' => self::TEMPLATE_VERSION,
            'storage_key' => $storageKey,
            'metadata' => $metadata,
            'generated_by' => $actor->getKey(),
        ]);

        if ($target instanceof ClinicalDocument) {
            if ($target->tenant_id !== $patient->tenant_id
                || $target->clinic_id !== $clinicId
                || $target->patient_id !== $patient->getKey()) {
                throw new DomainException('The pending clinical document context does not match its source record.');
            }

            $storageKey = $target->storage_key;
            $document->fill([
                'status' => ClinicalDocumentStatus::Generating,
                'document_type' => $documentType,
                'template_version' => self::TEMPLATE_VERSION,
                'generated_by' => $actor->getKey(),
            ]);
            $document->save();
        }

        $stored = false;

        try {
            $contents = Pdf::loadView($view, [
                ...$payload,
                'document' => [
                    'id' => (string) $document->getKey(),
                    'shortId' => Str::upper(Str::substr((string) $document->getKey(), -10)),
                    'generatedAt' => $generatedAt->timezone($patient->tenant?->timezone ?? 'Europe/Madrid')->format('d/m/Y H:i'),
                    'templateVersion' => self::TEMPLATE_VERSION,
                ],
            ])->setPaper('a4', 'portrait')->setWarnings(false)->output();

            if (! str_starts_with($contents, '%PDF-') || strlen($contents) > self::MAX_PDF_BYTES) {
                throw new RuntimeException('The generated clinical PDF is invalid or exceeds the maximum size.');
            }

            $stored = Storage::disk('clinical')->put($storageKey, Crypt::encryptString($contents));
            if (! $stored) {
                throw new RuntimeException('The clinical PDF could not be stored.');
            }

            $document->fill([
                'status' => ClinicalDocumentStatus::Ready,
                'sha256' => hash('sha256', $contents),
                'metadata' => [
                    ...$metadata,
                    'bytes' => strlen($contents),
                ],
                'generated_at' => $generatedAt,
            ])->save();

            return $document->refresh();
        } catch (Throwable $exception) {
            if ($stored) {
                $this->deleteStoredObject($storageKey);
            }

            $this->markFailed($document, $metadata);

            throw $exception;
        }
    }

    /** @return array{name: string, clinicName: string} */
    private function organizationData(Patient $patient, ?string $clinicName): array
    {
        return [
            'name' => $patient->tenant?->name ?? (string) config('app.name', 'NexaSalud'),
            'clinicName' => $clinicName ?? 'Centro asistencial',
        ];
    }

    /** @return array<string, string|null> */
    private function patientData(Patient $patient, bool $includeAddress = false): array
    {
        $recordNumber = (string) $patient->medical_record_number;
        $recordSuffix = Str::substr(preg_replace('/[^A-Za-z0-9]/', '', $recordNumber) ?? '', -4);

        return [
            'name' => trim((string) $patient->first_name.' '.(string) $patient->last_name),
            'recordNumber' => $recordSuffix === '' ? null : '***-'.$recordSuffix,
            'birthDate' => $this->birthDate($patient->birth_date),
            'address' => $includeAddress ? $patient->address : null,
            'email' => $includeAddress ? $patient->email : null,
        ];
    }

    private function requirePatient(?Patient $patient): Patient
    {
        if (! $patient instanceof Patient) {
            throw new DomainException('A patient is required to generate a clinical document.');
        }

        return $patient;
    }

    private function birthDate(mixed $birthDate): ?string
    {
        if (! is_string($birthDate) || $birthDate === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($birthDate)->format('d/m/Y');
        } catch (Throwable) {
            return null;
        }
    }

    private function localDateTime(?CarbonInterface $date, string $timezone, string $format): ?string
    {
        return $date?->timezone($timezone)->format($format);
    }

    private function opaqueStorageKey(): string
    {
        $token = Str::lower(Str::random(64));

        return 'v1/'.Str::substr($token, 0, 2).'/'.Str::substr($token, 2, 2).'/'.$token.'.pdf.enc';
    }

    /** @param array<string, mixed> $metadata */
    private function markFailed(ClinicalDocument $document, array $metadata): void
    {
        try {
            $document->fill([
                'status' => ClinicalDocumentStatus::Failed,
                'metadata' => [...$metadata, 'failure' => 'generation_failed'],
            ])->save();
        } catch (Throwable) {
            // Preserve the original rendering or storage exception.
        }
    }

    private function deleteStoredObject(string $storageKey): void
    {
        try {
            Storage::disk('clinical')->delete($storageKey);
        } catch (Throwable) {
            // The database record remains non-downloadable and will be handled by retention tooling.
        }
    }

    private function appointmentChannelLabel(string $channel): string
    {
        return match ($channel) {
            'video' => 'Videoconsulta',
            'phone' => 'Consulta telefónica',
            default => 'Consulta presencial',
        };
    }

    private function appointmentStatusLabel(AppointmentStatus $status): string
    {
        return match ($status) {
            AppointmentStatus::Scheduled => 'Programada',
            AppointmentStatus::Confirmed => 'Confirmada',
            AppointmentStatus::CheckedIn => 'Admisión realizada',
            AppointmentStatus::InProgress => 'En curso',
            AppointmentStatus::Completed => 'Completada',
            AppointmentStatus::Cancelled => 'Cancelada',
            AppointmentStatus::NoShow => 'No presentada',
        };
    }

    private function prescriptionStatusLabel(PrescriptionStatus $status): string
    {
        return match ($status) {
            PrescriptionStatus::Signed => 'Firmada',
            PrescriptionStatus::Sent => 'Firmada y enviada',
            default => 'No válida',
        };
    }

    private function invoiceStatusLabel(InvoiceStatus $status): string
    {
        return match ($status) {
            InvoiceStatus::Issued => 'Emitida',
            InvoiceStatus::PartiallyPaid => 'Pago parcial',
            InvoiceStatus::Paid => 'Pagada',
            InvoiceStatus::Overdue => 'Vencida',
            InvoiceStatus::Draft => 'Borrador',
            InvoiceStatus::Voided => 'Anulada',
        };
    }

    private function routeLabel(string $route): string
    {
        return match ($route) {
            'oral', 'PO' => 'Vía oral',
            'topical' => 'Vía tópica',
            'inhalation' => 'Vía inhalatoria',
            'subcutaneous', 'SC' => 'Vía subcutánea',
            'intramuscular', 'IM' => 'Vía intramuscular',
            default => $route,
        };
    }

    private function frequencyLabel(string $frequency): string
    {
        return match ($frequency) {
            'QD' => 'Una vez al día',
            'BID' => 'Dos veces al día',
            'TID' => 'Tres veces al día',
            'QID' => 'Cuatro veces al día',
            'PRN' => 'Cuando sea necesario',
            default => $frequency,
        };
    }

    private function durationUnitLabel(string $unit, int $value): string
    {
        return match ($unit) {
            'day', 'days' => $value === 1 ? 'día' : 'días',
            'week', 'weeks' => $value === 1 ? 'semana' : 'semanas',
            'month', 'months' => $value === 1 ? 'mes' : 'meses',
            default => $unit,
        };
    }

    private function decimal(string $value): string
    {
        $normalized = rtrim(rtrim($value, '0'), '.');

        return $normalized === '' ? '0' : str_replace('.', ',', $normalized);
    }

    private function money(int $minorUnits, string $currency): string
    {
        return number_format($minorUnits / 100, 2, ',', '.').' '.$currency;
    }
}
