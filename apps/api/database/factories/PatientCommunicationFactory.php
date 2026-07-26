<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientCommunication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientCommunication>
 */
final class PatientCommunicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'tenant_id' => fn (array $attributes): string => Patient::withoutGlobalScope('tenant')->findOrFail($attributes['patient_id'])->tenant_id,
            'author_user_id' => User::factory(),
            'channel' => 'portal',
            'content_class' => 'administrative',
            'subject' => 'Mensaje administrativo de prueba',
            'body' => 'Este contenido es sintético y se utiliza únicamente durante pruebas automatizadas.',
            'status' => 'draft',
            'lock_version' => 1,
        ];
    }
}
