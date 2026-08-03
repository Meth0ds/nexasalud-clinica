<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClinicalDocumentStatus;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateClinicalPdfJob;
use App\Models\Appointment;
use App\Models\ClinicalDocument;
use App\Models\Invoice;
use App\Models\Prescription;
use App\Models\User;
use App\Services\Documents\ClinicalPdfGenerator;
use App\Support\Auditing\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use UnexpectedValueException;

/**
 * Generates, verifies and serves protected clinical documents with audit evidence.
 */
final class DocumentController extends Controller
{
    /**
     * Create the document controller with the protected PDF generator and audit recorder.
     */
    public function __construct(
        private readonly ClinicalPdfGenerator $pdfGenerator,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Generate an authorized encrypted appointment-confirmation document.
     */
    public function generateAppointmentConfirmation(Request $request, Appointment $appointment): JsonResponse
    {
        Gate::authorize('view', $appointment);
        Gate::authorize('create', ClinicalDocument::class);

        $actor = $this->actor($request);

        if ($this->asyncGeneration()) {
            $document = $this->pdfGenerator->queueAppointmentConfirmation($appointment, $actor);
            $this->dispatch($document);

            $this->audit->record($request, 'clinical_document.queued', $document, [
                'document_type' => $document->document_type,
                'queue' => config('documents.queue'),
            ]);

            return $this->documentResponse($document, HttpStatus::HTTP_ACCEPTED);
        }

        $document = $this->pdfGenerator->generateAppointmentConfirmation($appointment, $actor);

        $this->audit->record($request, 'clinical_document.generated', $document, [
            'document_type' => $document->document_type,
            'encrypted_at_rest' => true,
        ]);

        return $this->documentResponse($document, HttpStatus::HTTP_CREATED);
    }

    /**
     * Generate an authorized encrypted prescription-plan document.
     */
    public function generatePrescriptionPlan(Request $request, Prescription $prescription): JsonResponse
    {
        Gate::authorize('view', $prescription);
        Gate::authorize('create', ClinicalDocument::class);

        $actor = $this->actor($request);

        if ($this->asyncGeneration()) {
            $document = $this->pdfGenerator->queuePrescriptionPlan($prescription, $actor);
            $this->dispatch($document);

            $this->audit->record($request, 'clinical_document.queued', $document, [
                'document_type' => $document->document_type,
                'queue' => config('documents.queue'),
            ]);

            return $this->documentResponse($document, HttpStatus::HTTP_ACCEPTED);
        }

        $document = $this->pdfGenerator->generatePrescriptionPlan($prescription, $actor);

        $this->audit->record($request, 'clinical_document.generated', $document, [
            'document_type' => $document->document_type,
            'encrypted_at_rest' => true,
        ]);

        return $this->documentResponse($document, HttpStatus::HTTP_CREATED);
    }

    /**
     * Generate an authorized encrypted invoice document.
     */
    public function generateInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        Gate::authorize('view', $invoice);
        Gate::authorize('create', ClinicalDocument::class);

        $actor = $this->actor($request);

        if ($this->asyncGeneration()) {
            $document = $this->pdfGenerator->queueInvoice($invoice, $actor);
            $this->dispatch($document);

            $this->audit->record($request, 'clinical_document.queued', $document, [
                'document_type' => $document->document_type,
                'queue' => config('documents.queue'),
            ]);

            return $this->documentResponse($document, HttpStatus::HTTP_ACCEPTED);
        }

        $document = $this->pdfGenerator->generateInvoice($invoice, $actor);

        $this->audit->record($request, 'clinical_document.generated', $document, [
            'document_type' => $document->document_type,
            'encrypted_at_rest' => true,
        ]);

        return $this->documentResponse($document, HttpStatus::HTTP_CREATED);
    }

    /**
     * Serve a verified ready document with restrictive browser and caching headers.
     */
    public function download(Request $request, ClinicalDocument $document): Response
    {
        Gate::authorize('view', $document);

        if ($document->status === ClinicalDocumentStatus::Voided
            || $document->status === ClinicalDocumentStatus::Expired
            || $document->voided_at !== null
            || ($document->expires_at !== null && $document->expires_at->isPast())) {
            abort(HttpStatus::HTTP_GONE);
        }

        abort_unless($document->status === ClinicalDocumentStatus::Ready, HttpStatus::HTTP_CONFLICT);

        try {
            $contents = $this->pdfGenerator->verifiedContents($document);
        } catch (UnexpectedValueException) {
            $this->audit->record($request, 'clinical_document.integrity_check_failed', $document, [
                'document_type' => $document->document_type,
            ]);

            abort(HttpStatus::HTTP_GONE);
        }

        $this->audit->record($request, 'clinical_document.downloaded', $document, [
            'document_type' => $document->document_type,
            'integrity_verified' => true,
        ]);

        $filename = $this->pdfGenerator->safeDownloadName($document);

        return response($contents, HttpStatus::HTTP_OK, [
            'Cache-Control' => 'private, no-store, no-cache, max-age=0, must-revalidate',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
            'Content-Length' => (string) strlen($contents),
            'Content-Security-Policy' => 'sandbox',
            'Content-Type' => 'application/pdf',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Expires' => '0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Download-Options' => 'noopen',
            'X-Robots-Tag' => 'noindex, noarchive, nosnippet',
        ]);
    }

    /**
     * Return a safe status projection used by clients while a PDF is rendering.
     */
    public function status(Request $request, ClinicalDocument $document): JsonResponse
    {
        Gate::authorize('view', $document);

        return response()->json([
            'data' => [
                'id' => $document->getKey(),
                'documentType' => $document->document_type,
                'status' => $document->status->value,
                'revision' => $document->revision,
                'generatedAt' => $document->generated_at?->toIso8601String(),
                'downloadPath' => $document->status === ClinicalDocumentStatus::Ready
                    ? '/api/v1/documents/'.$document->getKey().'/download'
                    : null,
            ],
        ], HttpStatus::HTTP_OK, [
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Return the authenticated local actor or reject anonymous document operations.
     */
    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, HttpStatus::HTTP_UNAUTHORIZED);

        return $actor;
    }

    /**
     * Return the minimal creation response for a generated clinical document.
     */
    private function documentResponse(ClinicalDocument $document, int $status): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $document->getKey(),
                'documentType' => $document->document_type,
                'status' => $document->status->value,
                'revision' => $document->revision,
                'templateVersion' => $document->template_version,
                'generatedAt' => $document->generated_at?->toIso8601String(),
                'downloadPath' => $document->status === ClinicalDocumentStatus::Ready
                    ? '/api/v1/documents/'.$document->getKey().'/download'
                    : null,
                'statusPath' => '/api/v1/documents/'.$document->getKey(),
                'pollAfterSeconds' => (int) config('documents.poll_after_seconds', 2),
            ],
        ], $status, [
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function asyncGeneration(): bool
    {
        return (bool) config('documents.async_generation', false);
    }

    private function dispatch(ClinicalDocument $document): void
    {
        GenerateClinicalPdfJob::dispatch(
            (string) $document->getKey(),
            (string) $document->tenant_id,
            (string) $document->clinic_id,
        )->onQueue((string) config('documents.queue', 'clinical-pdf'));
    }
}
