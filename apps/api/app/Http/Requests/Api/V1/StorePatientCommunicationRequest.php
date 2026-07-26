<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationContentClass;
use App\Models\PatientCommunication;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StorePatientCommunicationRequest extends TenantFormRequest
{
    /** @var list<string> */
    public const EXTERNAL_TEMPLATE_KEYS = [
        'appointment_reminder',
        'appointment_change',
        'billing_notification',
        'generic_notification',
    ];

    public function authorize(): bool
    {
        return $this->authenticatedUser()->can('create', PatientCommunication::class);
    }

    public function rules(): array
    {
        return [
            'patientId' => [
                'required',
                'string',
                Rule::exists('patients', 'id')->where(
                    fn (Builder $query) => $query
                        ->where('tenant_id', $this->tenantId())
                        ->where('is_active', true),
                ),
            ],
            'channel' => ['required', Rule::enum(CommunicationChannel::class)],
            'contentClass' => ['required', Rule::enum(CommunicationContentClass::class)],
            'subject' => ['nullable', 'string', 'min:3', 'max:160', 'required_if:channel,portal', 'prohibited_unless:channel,portal'],
            'body' => ['nullable', 'string', 'min:2', 'max:4000', 'required_if:channel,portal', 'prohibited_unless:channel,portal'],
            'templateKey' => ['nullable', 'string', Rule::in(self::EXTERNAL_TEMPLATE_KEYS), 'required_unless:channel,portal', 'prohibited_if:channel,portal'],
            'mode' => ['required', Rule::in(['draft', 'queue'])],
            'scheduledAt' => ['nullable', 'date', 'after:now'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['subject', 'body'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (
                $this->input('channel') !== CommunicationChannel::Portal->value
                && $this->input('contentClass') !== CommunicationContentClass::Administrative->value
            ) {
                $validator->errors()->add(
                    'contentClass',
                    'Los canales externos sólo admiten notificaciones administrativas con plantilla.',
                );
            }
        });
    }
}
