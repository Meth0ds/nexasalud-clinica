<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PatientFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'medical_record_hash', 'medical_record_number', 'first_name', 'last_name', 'birth_date', 'email', 'phone', 'address', 'clinical_notes', 'preferred_language', 'is_active'])]
#[Hidden(['medical_record_number', 'first_name', 'last_name', 'birth_date', 'email', 'phone', 'address', 'clinical_notes'])]
/**
 * Represents a tenant-isolated patient record with encrypted personal and clinical data.
 */
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'preferred_language' => 'es',
        'is_active' => true,
    ];

    /**
     * Return appointments associated with this patient.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Return prescriptions issued for this patient.
     */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    /**
     * Return recorded allergies for this patient.
     */
    public function allergies(): HasMany
    {
        return $this->hasMany(PatientAllergy::class);
    }

    /**
     * Return adverse reactions recorded for this patient.
     */
    public function adverseReactions(): HasMany
    {
        return $this->hasMany(AdverseReaction::class);
    }

    /**
     * Return clinical documents belonging to this patient.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(ClinicalDocument::class);
    }

    /**
     * Return consent records retained for this patient.
     */
    public function consentRecords(): HasMany
    {
        return $this->hasMany(ConsentRecord::class);
    }

    /**
     * Return active and historical coverage records for this patient.
     */
    public function coverages(): HasMany
    {
        return $this->hasMany(PatientCoverage::class);
    }

    /**
     * Return invoices issued to this patient.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Return portal user identities linked to this patient.
     */
    public function userLinks(): HasMany
    {
        return $this->hasMany(PatientUserLink::class);
    }

    /**
     * Build a tenant-bound blind index for privacy-preserving patient record lookups.
     */
    public static function blindIndex(string $tenantId, string $value): string
    {
        $applicationKey = config('app.key');

        if (! is_string($applicationKey) || $applicationKey === '') {
            throw new DomainException('An application key is required for patient blind indexes.');
        }

        $tenantKey = hash_hmac('sha256', $tenantId, $applicationKey, true);

        return hash_hmac('sha256', mb_strtolower(trim($value)), $tenantKey);
    }

    /**
     * Register blind-index synchronization whenever the record number or tenant changes.
     */
    protected static function booted(): void
    {
        $synchronizeBlindIndex = function (self $patient): void {
            $tenantId = $patient->getAttribute('tenant_id');
            $medicalRecordNumber = $patient->getAttribute('medical_record_number');

            if (is_string($tenantId) && is_string($medicalRecordNumber)) {
                $patient->setAttribute('medical_record_hash', self::blindIndex($tenantId, $medicalRecordNumber));
            }
        };

        static::creating($synchronizeBlindIndex);
        static::updating(function (self $patient) use ($synchronizeBlindIndex): void {
            if ($patient->isDirty(['tenant_id', 'medical_record_number'])) {
                $synchronizeBlindIndex($patient);
            }
        });
    }

    /**
     * Define encrypted patient attributes and activation status casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'medical_record_number' => 'encrypted',
            'first_name' => 'encrypted',
            'last_name' => 'encrypted',
            'birth_date' => 'encrypted',
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'address' => 'encrypted',
            'clinical_notes' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }
}
