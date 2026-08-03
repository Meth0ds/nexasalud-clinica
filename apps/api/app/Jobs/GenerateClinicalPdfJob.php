<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Clinic;
use App\Models\ClinicalDocument;
use App\Models\Tenant;
use App\Services\Documents\ClinicalPdfGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Render one clinical PDF outside the HTTP request lifecycle.
 *
 * The job only carries opaque identifiers. It never serializes patient fields,
 * medication instructions, invoice lines or the PDF payload into the queue.
 */
final class GenerateClinicalPdfJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public bool $failOnTimeout = true;

    public int $maxExceptions = 1;

    public function __construct(
        public readonly string $documentId,
        public readonly string $tenantId,
        public readonly string $clinicId,
    ) {
        $this->tries = (int) config('documents.max_attempts', 3);
        $this->timeout = (int) config('documents.timeout_seconds', 60);
        $this->onQueue((string) config('documents.queue', 'clinical-pdf'));
    }

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->documentId;
    }

    public function uniqueFor(): int
    {
        return max(120, (int) config('documents.retry_after_seconds', 90) * 2);
    }

    /**
     * Serialize PDF rendering per clinic. This is an intentional hard quota:
     * one expensive Dompdf render cannot starve all clinical request workers.
     * Horizontal scaling can raise the quota only after measuring memory use.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('clinical-pdf:'.$this->tenantId.':'.$this->clinicId))
                ->releaseAfter(5)
                ->expireAfter($this->timeout + 30),
        ];
    }

    public function handle(ClinicalPdfGenerator $generator): void
    {
        $startedAt = hrtime(true);
        $this->setTenantContext();

        try {
            $document = ClinicalDocument::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $this->tenantId)
                ->where('clinic_id', $this->clinicId)
                ->whereKey($this->documentId)
                ->first();

            if (! $document instanceof ClinicalDocument) {
                Log::warning('clinical_pdf.document_missing', [
                    'event' => 'clinical_pdf.document_missing',
                    'document_id' => $this->documentId,
                    'tenant_id' => $this->tenantId,
                    'clinic_id' => $this->clinicId,
                ]);

                return;
            }

            if ($document->status->value !== 'generating') {
                Log::info('clinical_pdf.skipped_terminal_document', [
                    'event' => 'clinical_pdf.skipped_terminal_document',
                    'document_id' => $document->getKey(),
                    'status' => $document->status->value,
                ]);

                return;
            }

            Log::info('clinical_pdf.generation_started', [
                'event' => 'clinical_pdf.generation_started',
                'document_id' => $document->getKey(),
                'tenant_id' => $this->tenantId,
                'clinic_id' => $this->clinicId,
                'attempt' => $this->attempts(),
            ]);

            $ready = $generator->renderPendingDocument($document);

            Log::info('clinical_pdf.generation_completed', [
                'event' => 'clinical_pdf.generation_completed',
                'document_id' => $ready->getKey(),
                'tenant_id' => $this->tenantId,
                'clinic_id' => $this->clinicId,
                'bytes' => $ready->metadata['bytes'] ?? null,
                'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
                'attempt' => $this->attempts(),
            ]);
        } catch (Throwable $exception) {
            Log::error('clinical_pdf.generation_failed', [
                'event' => 'clinical_pdf.generation_failed',
                'document_id' => $this->documentId,
                'tenant_id' => $this->tenantId,
                'clinic_id' => $this->clinicId,
                'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
                'attempt' => $this->attempts(),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            $this->clearTenantContext();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->setTenantContext();

        try {
            $document = ClinicalDocument::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $this->tenantId)
                ->where('clinic_id', $this->clinicId)
                ->whereKey($this->documentId)
                ->first();

            if ($document instanceof ClinicalDocument) {
                app(ClinicalPdfGenerator::class)->markPendingDocumentFailed($document);
            }

            Log::critical('clinical_pdf.job_failed', [
                'event' => 'clinical_pdf.job_failed',
                'document_id' => $this->documentId,
                'tenant_id' => $this->tenantId,
                'clinic_id' => $this->clinicId,
                'exception' => $exception instanceof Throwable ? $exception::class : null,
            ]);
        } catch (Throwable $failure) {
            Log::critical('clinical_pdf.failure_persistence_failed', [
                'event' => 'clinical_pdf.failure_persistence_failed',
                'document_id' => $this->documentId,
                'tenant_id' => $this->tenantId,
                'clinic_id' => $this->clinicId,
                'exception' => $failure::class,
            ]);
        } finally {
            $this->clearTenantContext();
        }
    }

    private function setTenantContext(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$this->tenantId]);
        }

        $tenant = Tenant::query()
            ->withoutGlobalScope('tenant')
            ->whereKey($this->tenantId)
            ->first();

        if ($tenant instanceof Tenant) {
            app()->instance('currentTenant', $tenant);
        }

        $clinic = Clinic::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->clinicId)
            ->first();

        if ($clinic instanceof Clinic) {
            app()->instance('currentClinic', $clinic);
        }
    }

    private function clearTenantContext(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT set_config('app.tenant_id', '', false)");
        }

        app()->forgetInstance('currentClinic');
        app()->forgetInstance('currentTenant');
    }
}
