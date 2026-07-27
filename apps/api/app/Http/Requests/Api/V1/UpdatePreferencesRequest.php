<?php

namespace App\Http\Requests\Api\V1;

class UpdatePreferencesRequest extends TenantFormRequest
{
    public function authorize(): bool
    {
        return app()->bound('currentMembership');
    }

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
