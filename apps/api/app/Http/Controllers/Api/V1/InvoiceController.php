<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreInvoiceRequest;
use App\Http\Resources\Api\V1\InvoiceResource;
use App\Models\Invoice;
use App\Models\PatientCoverage;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Auditing\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class InvoiceController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Invoice::class);
        $filters = $request->validate([
            'patientId' => ['nullable', 'string'],
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
            'perPage' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $invoices = Invoice::query()
            ->with(['patient', 'items', 'payments', 'coverage.insurer', 'clinic'])
            ->when(isset($filters['patientId']), fn ($query) => $query->where('patient_id', $filters['patientId']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->latest('created_at')
            ->paginate((int) ($filters['perPage'] ?? 30));

        return InvoiceResource::collection($invoices);
    }

    public function store(StoreInvoiceRequest $request): InvoiceResource
    {
        $data = $request->validated();
        $this->validateCoverage($data['coverageId'] ?? null, $data['patientId']);

        $invoice = DB::transaction(function () use ($data): Invoice {
            $totals = $this->calculate($data['items']);
            $invoice = Invoice::create([
                'tenant_id' => $this->tenant()->getKey(),
                'clinic_id' => $data['clinicId'],
                'patient_id' => $data['patientId'],
                'patient_coverage_id' => $data['coverageId'] ?? null,
                'appointment_id' => $data['appointmentId'] ?? null,
                'invoice_number' => $this->nextNumber(),
                'currency' => $data['currency'],
                'subtotal_minor' => $totals['subtotal'],
                'tax_minor' => $totals['tax'],
                'total_minor' => $totals['total'],
                'balance_minor' => $totals['total'],
                'due_on' => $data['dueOn'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->replaceItems($invoice, $totals['items']);

            return $invoice;
        });

        $this->audit->record($request, 'invoice.created', $invoice, ['item_count' => count($data['items'])]);

        return new InvoiceResource($this->load($invoice));
    }

    public function show(Request $request, Invoice $invoice): InvoiceResource
    {
        Gate::authorize('view', $invoice);
        $this->audit->record($request, 'invoice.viewed', $invoice);

        return new InvoiceResource($this->load($invoice));
    }

    public function update(Request $request, Invoice $invoice): InvoiceResource
    {
        Gate::authorize('update', $invoice);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'dueOn' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $invoice = DB::transaction(function () use ($data, $invoice): Invoice {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
            $this->requireVersion($locked, (int) $data['version']);
            abort_unless($locked->status === InvoiceStatus::Draft, Response::HTTP_CONFLICT);

            if (array_key_exists('dueOn', $data)) {
                $locked->due_on = $data['dueOn'];
            }
            if (array_key_exists('notes', $data)) {
                $locked->notes = $data['notes'];
            }
            $locked->lock_version++;
            $locked->save();

            return $locked;
        });

        $this->audit->record($request, 'invoice.updated', $invoice, ['version' => $invoice->lock_version]);

        return new InvoiceResource($this->load($invoice));
    }

    public function issue(Request $request, Invoice $invoice): InvoiceResource
    {
        Gate::authorize('update', $invoice);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        $invoice = DB::transaction(function () use ($data, $invoice): Invoice {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
            $this->requireVersion($locked, (int) $data['version']);
            abort_unless($locked->status === InvoiceStatus::Draft && $locked->total_minor > 0, Response::HTTP_CONFLICT);

            $locked->status = InvoiceStatus::Issued;
            $locked->issued_on = today();
            $locked->lock_version++;
            $locked->save();

            return $locked;
        });

        $this->audit->record($request, 'invoice.issued', $invoice, ['version' => $invoice->lock_version]);

        return new InvoiceResource($this->load($invoice));
    }

    public function recordPayment(Request $request, Invoice $invoice): InvoiceResource
    {
        Gate::authorize('update', $invoice);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'amountMinor' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'methodCode' => ['required', 'string', 'in:cash,bank_transfer,card_provider,insurer'],
            'externalReference' => ['nullable', 'string', 'max:160'],
        ]);

        $invoice = DB::transaction(function () use ($data, $invoice): Invoice {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
            $this->requireVersion($locked, (int) $data['version']);
            abort_unless(in_array($locked->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid], true), Response::HTTP_CONFLICT);
            abort_if((int) $data['amountMinor'] > $locked->balance_minor, Response::HTTP_UNPROCESSABLE_ENTITY);

            Payment::create([
                'tenant_id' => $locked->tenant_id,
                'invoice_id' => $locked->getKey(),
                'amount_minor' => (int) $data['amountMinor'],
                'currency' => $locked->currency,
                'status' => PaymentStatus::Completed,
                'method_code' => $data['methodCode'],
                'external_reference' => $data['externalReference'] ?? null,
                'received_at' => now(),
            ]);

            $locked->balance_minor -= (int) $data['amountMinor'];
            $locked->status = $locked->balance_minor === 0 ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid;
            $locked->lock_version++;
            $locked->save();

            return $locked;
        });

        $this->audit->record($request, 'invoice.payment_recorded', $invoice, [
            'method' => $data['methodCode'],
            'settled' => $invoice->balance_minor === 0,
        ]);

        return new InvoiceResource($this->load($invoice));
    }

    public function destroy(): never
    {
        abort(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{subtotal: int, tax: int, total: int, items: list<array<string, mixed>>}
     */
    private function calculate(array $items): array
    {
        $subtotal = 0;
        $tax = 0;
        $normalized = [];

        foreach (array_values($items) as $index => $item) {
            $quantityMilli = $this->quantityMilli((string) $item['quantity']);
            $lineSubtotal = intdiv(((int) $item['unitPriceMinor'] * $quantityMilli) + 500, 1000);
            $lineTax = intdiv(($lineSubtotal * (int) $item['taxRateBasisPoints']) + 5000, 10000);
            $subtotal += $lineSubtotal;
            $tax += $lineTax;
            abort_if($subtotal > 100000000000 || $tax > 100000000000, Response::HTTP_UNPROCESSABLE_ENTITY);

            $normalized[] = [
                'service_code' => $item['serviceCode'] ?? sprintf('SERVICE-%03d', $index + 1),
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price_minor' => (int) $item['unitPriceMinor'],
                'tax_minor' => $lineTax,
                'total_minor' => $lineSubtotal + $lineTax,
                'sort_order' => $index,
            ];
        }

        return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax, 'items' => $normalized];
    }

    private function quantityMilli(string $quantity): int
    {
        abort_unless(preg_match('/^(\d{1,5})(?:\.(\d{1,3}))?$/', $quantity, $matches) === 1, Response::HTTP_UNPROCESSABLE_ENTITY);

        return ((int) $matches[1] * 1000) + (int) str_pad($matches[2] ?? '', 3, '0');
    }

    /** @param list<array<string, mixed>> $items */
    private function replaceItems(Invoice $invoice, array $items): void
    {
        foreach ($items as $item) {
            $invoice->items()->create(['tenant_id' => $invoice->tenant_id, ...$item]);
        }
    }

    private function validateCoverage(?string $coverageId, string $patientId): void
    {
        if ($coverageId === null) {
            return;
        }

        $coverage = PatientCoverage::query()->findOrFail($coverageId);
        abort_if($coverage->patient_id !== $patientId || ! $coverage->is_active, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function nextNumber(): string
    {
        return 'D-'.now()->format('Ym').'-'.Str::upper(Str::substr((string) Str::ulid(), -10));
    }

    private function load(Invoice $invoice): Invoice
    {
        return $invoice->load(['patient', 'items', 'payments', 'coverage.insurer', 'clinic']);
    }

    private function requireVersion(Invoice $invoice, int $version): void
    {
        abort_if($invoice->lock_version !== $version, Response::HTTP_CONFLICT);
    }

    private function tenant(): Tenant
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        abort_unless($tenant instanceof Tenant, Response::HTTP_FORBIDDEN);

        return $tenant;
    }
}
