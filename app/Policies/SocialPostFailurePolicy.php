<?php

namespace App\Policies;

use App\Models\SocialPostFailure;
use App\Models\User;

class SocialPostFailurePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('social-failures.view');
    }

    public function view(User $user, SocialPostFailure $failure): bool
    {
        return $failure->socialPost->isOwnedBy($user);
    }

    public function viewOperationalMetadata(User $user, SocialPostFailure $failure): bool
    {
        return $user->hasPermissionTo('social-failures.view');
    }
}
