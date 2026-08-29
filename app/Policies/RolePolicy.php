<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPermissionTo('roles.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('roles.manage');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->hasPermissionTo('roles.manage');
    }

    public function delete(User $user, Role $role): bool
    {
        return ! $role->is_system
            && ! $role->users()->exists()
            && $user->hasPermissionTo('roles.manage');
    }
}
