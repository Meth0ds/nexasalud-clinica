<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Invoice;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends TenantFormRequest
{
    public function authorize(): bool { return $this->authenticatedUser()->can('create', Invoice::class); }
    public function rules(): array
    {
        $tenant = $this->tenantId();
        $exists = fn (string $table) => Rule::exists($table, 'id')->where(fn (Builder $query) => $query->where('tenant_id', $tenant));
        return [
            'clinicId' => ['required', 'string', $exists('clinics')],
            'patientId' => ['required', 'string', $exists('patients')],
            'coverageId' => ['nullable', 'string', $exists('patient_coverages')],
            'appointmentId' => ['nullable', 'string', $exists('appointments')],
            'currency' => ['required', 'string', 'size:3', 'in:EUR'],
            'dueOn' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.serviceCode' => ['nullable', 'string', 'max:80'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:10000'],
            'items.*.unitPriceMinor' => ['required', 'integer', 'min:0', 'max:100000000'],
            'items.*.taxRateBasisPoints' => ['required', 'integer', 'between:0,10000'],
        ];
    }
}
