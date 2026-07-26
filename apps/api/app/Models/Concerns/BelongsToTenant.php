<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantId = static::currentTenantId();

            if ($tenantId === null) {
                $builder->whereRaw('0 = 1');

                return;
            }

            $builder->where($builder->qualifyColumn('tenant_id'), $tenantId);
        });

        static::creating(function (self $model): void {
            $tenantId = static::currentTenantId();

            if (! $model->getAttribute('tenant_id')) {
                if ($tenantId === null) {
                    throw new DomainException('A tenant context or explicit tenant_id is required.');
                }

                $model->setAttribute('tenant_id', $tenantId);
            }

            if ($tenantId !== null && $model->getAttribute('tenant_id') !== $tenantId) {
                throw new DomainException('The model tenant does not match the current tenant context.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeForTenant(Builder $query, Tenant|string $tenant): Builder
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        return $query->withoutGlobalScope('tenant')->where($query->qualifyColumn('tenant_id'), $tenantId);
    }

    private static function currentTenantId(): ?string
    {
        if (! app()->bound('currentTenant')) {
            return null;
        }

        $tenant = app('currentTenant');

        if ($tenant instanceof Tenant) {
            return (string) $tenant->getKey();
        }

        return is_string($tenant) && $tenant !== '' ? $tenant : null;
    }
}
