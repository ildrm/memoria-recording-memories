<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('users.view');
    }

    public function view(User $user, User $subject): bool
    {
        return $user->is($subject) || $user->hasPermissionTo('users.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('users.manage');
    }

    public function update(User $user, User $subject): bool
    {
        return $user->is($subject) || $user->hasPermissionTo('users.manage');
    }

    public function delete(User $user, User $subject): bool
    {
        return ! $user->is($subject)
            && ! $subject->isLastSuperAdministrator()
            && $user->hasPermissionTo('users.manage');
    }

    public function manageRoles(User $user, User $subject): bool
    {
        return $user->isSuperAdministrator();
    }

    public function disable(User $user, User $subject): bool
    {
        return $this->delete($user, $subject);
    }
}
