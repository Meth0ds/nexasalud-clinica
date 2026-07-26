<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;
use App\Support\Authorization\PermissionService;

class AppointmentPolicy
{
    public function __construct(private PermissionService $permissions) {}

    public function viewAny(User $user): bool { return $this->permissions->allows($user, 'appointments.read'); }
    public function view(User $user, Appointment $appointment): bool { return $this->sameTenant($appointment) && $this->viewAny($user); }
    public function create(User $user): bool { return $this->permissions->allows($user, 'appointments.write'); }
    public function update(User $user, Appointment $appointment): bool { return $this->sameTenant($appointment) && $this->permissions->allows($user, 'appointments.write'); }
    public function delete(User $user, Appointment $appointment): bool { return $this->sameTenant($appointment) && $this->permissions->allows($user, 'appointments.cancel'); }
    public function restore(User $user, Appointment $appointment): bool { return false; }
    public function forceDelete(User $user, Appointment $appointment): bool { return false; }

    private function sameTenant(Appointment $appointment): bool { return $this->permissions->belongsToCurrentTenant($appointment); }
}
