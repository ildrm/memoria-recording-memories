<?php

namespace App\Policies;

use App\Models\SocialAccount;
use App\Models\User;

class SocialAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SocialAccount $account): bool
    {
        return $account->isOwnedBy($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SocialAccount $account): bool
    {
        return $account->isOwnedBy($user);
    }

    public function delete(User $user, SocialAccount $account): bool
    {
        return $account->isOwnedBy($user);
    }

    public function restore(User $user, SocialAccount $account): bool
    {
        return $account->isOwnedBy($user);
    }

    public function forceDelete(User $user, SocialAccount $account): bool
    {
        return $account->isOwnedBy($user);
    }
}
