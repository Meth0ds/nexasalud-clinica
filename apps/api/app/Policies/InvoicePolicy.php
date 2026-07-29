<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Support\Authorization\PermissionService;

/**
 * Authorize tenant-scoped invoice reads and permitted billing mutations.
 */
class InvoicePolicy
{
    /**
     * Create the policy with the tenant-aware permission service.
     */
    public function __construct(private PermissionService $permissions) {}

    /**
     * Determine whether the user may list invoices.
     */
    public function viewAny(User $user): bool
    {
        return $this->permissions->allows($user, 'billing.read');
    }

    /**
     * Determine whether the user may view a current-tenant invoice.
     */
    public function view(User $user, Invoice $invoice): bool
    {
        return $this->permissions->belongsToCurrentTenant($invoice) && $this->viewAny($user);
    }

    /**
     * Determine whether the user may create invoices.
     */
    public function create(User $user): bool
    {
        return $this->permissions->allows($user, 'billing.write');
    }

    /**
     * Determine whether the user may update a current-tenant invoice.
     */
    public function update(User $user, Invoice $invoice): bool
    {
        return $this->permissions->belongsToCurrentTenant($invoice) && $this->permissions->allows($user, 'billing.write');
    }

    /**
     * Disallow invoice deletion through policy authorization.
     */
    public function delete(User $user, Invoice $invoice): bool
    {
        return false;
    }

    /**
     * Disallow invoice restoration through policy authorization.
     */
    public function restore(User $user, Invoice $invoice): bool
    {
        return false;
    }

    /**
     * Disallow permanent invoice deletion through policy authorization.
     */
    public function forceDelete(User $user, Invoice $invoice): bool
    {
        return false;
    }
}
