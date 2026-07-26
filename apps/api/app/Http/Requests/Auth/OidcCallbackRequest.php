<?php

namespace App\Http\Requests\Auth;

use App\Exceptions\InvalidOidcTransactionException;
use App\Services\Identity\SecureKeycloakProvider;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OidcCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'state' => ['required', 'string', 'min:32', 'max:256'],
            'code' => ['required_without:error', 'string', 'max:4096'],
            'error' => [
                'nullable',
                'string',
                Rule::in([
                    'access_denied',
                    'account_selection_required',
                    'consent_required',
                    'interaction_required',
                    'invalid_request',
                    'invalid_scope',
                    'login_required',
                    'server_error',
                    'temporarily_unavailable',
                    'unauthorized_client',
                    'unsupported_response_type',
                ]),
            ],
            'iss' => ['nullable', 'string', 'max:512'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $issuer = $this->query('iss');

            if ($this->filled('code') && $this->filled('error')) {
                $validator->errors()->add('code', 'The authorization response is invalid.');
            }

            if (is_string($issuer) && $issuer !== config('services.keycloak.issuer')) {
                $validator->errors()->add('iss', 'The authorization response is invalid.');
            }
        }];
    }

    protected function failedValidation(Validator $validator): never
    {
        $this->session()->forget(SecureKeycloakProvider::TRANSACTION_SESSION_KEY);

        throw new InvalidOidcTransactionException;
    }
}
