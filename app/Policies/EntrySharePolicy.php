<?php

namespace App\Policies;

use App\Models\EntryShare;
use App\Models\User;

class EntrySharePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EntryShare $share): bool
    {
        return $share->isOwnedBy($user) || $user->is($share->recipient);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, EntryShare $share): bool
    {
        return $share->isOwnedBy($user);
    }

    public function delete(User $user, EntryShare $share): bool
    {
        return $share->isOwnedBy($user);
    }
}
