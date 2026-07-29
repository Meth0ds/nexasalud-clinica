<?php

namespace App\Http\Requests\Api\V1;

/**
 * Validate a membership-scoped partial update to user interface preferences.
 */
class UpdatePreferencesRequest extends TenantFormRequest
{
    /**
     * Determine whether a current membership context is available for the request.
     */
    public function authorize(): bool
    {
        return app()->bound('currentMembership');
    }

    /**
     * Return validation rules for supported user preference fields.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'theme.mode' => ['sometimes', 'required', 'in:system,light,dark'],
            'theme.density' => ['sometimes', 'required', 'in:comfortable,compact'],
            'theme.reduceMotion' => ['sometimes', 'boolean'],
            'agenda.defaultView' => ['sometimes', 'required', 'in:day,week,month'],
            'privacy.autoMask' => ['sometimes', 'boolean'],
            'notifications.desktop' => ['sometimes', 'boolean'],
        ];
    }
}
