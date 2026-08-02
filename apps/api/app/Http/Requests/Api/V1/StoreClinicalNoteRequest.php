<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\ClinicalNote;
use App\Models\Patient;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

/**
 * Validate creation of a patient-scoped clinical note and optional related records.
 */
final class StoreClinicalNoteRequest extends TenantFormRequest
{
    /**
     * Determine whether the authenticated user may create a clinical note for the routed patient.
     */
    public function authorize(): bool
    {
        $patient = $this->route('patient');
        $ability = $this->route()?->getActionMethod() === 'sign' ? 'sign' : 'create';

        return $patient instanceof Patient && $this->authenticatedUser()->can($ability, [ClinicalNote::class, $patient]);
    }

    /**
     * Return tenant-scoped validation rules for clinical note content and relationships.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'min:10', 'max:20000'],
            'appointmentId' => [
                'nullable',
                'string',
                Rule::exists('appointments', 'id')->where(
                    fn (Builder $query) => $query->where('tenant_id', $this->tenantId()),
                ),
            ],
            'supersedesId' => [
                'nullable',
                'string',
                Rule::exists('clinical_notes', 'id')->where(
                    fn (Builder $query) => $query->where('tenant_id', $this->tenantId()),
                ),
            ],
        ];
    }

    /**
     * Normalize clinical note content before Laravel evaluates its validation rules.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('content'))) {
            $this->merge(['content' => trim($this->input('content'))]);
        }
    }
}
