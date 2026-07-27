<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Support\Authorization\PermissionService;

class InvoicePolicy
{
    public function __construct(private PermissionService $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->allows($user, 'billing.read');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->permissions->belongsToCurrentTenant($invoice) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->permissions->allows($user, 'billing.write');
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $this->permissions->belongsToCurrentTenant($invoice) && $this->permissions->allows($user, 'billing.write');
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return false;
    }

    public function restore(User $user, Invoice $invoice): bool
    {
        return false;
    }

    public function forceDelete(User $user, Invoice $invoice): bool
    {
        return false;
    }
}
