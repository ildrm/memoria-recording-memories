<?php

namespace App\Policies;

use App\Models\Entry;
use App\Models\User;

class EntryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Entry $entry): bool
    {
        return $entry->isOwnedBy($user) || $entry->isSharedWith($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Entry $entry): bool
    {
        return $entry->isOwnedBy($user);
    }

    public function delete(User $user, Entry $entry): bool
    {
        return $entry->isOwnedBy($user);
    }

    public function deleteAny(User $user): bool
    {
        return true;
    }

    public function restore(User $user, Entry $entry): bool
    {
        return $entry->isOwnedBy($user);
    }

    public function forceDelete(User $user, Entry $entry): bool
    {
        return $entry->isOwnedBy($user);
    }

    public function share(User $user, Entry $entry): bool
    {
        return $entry->isOwnedBy($user);
    }

    public function publish(User $user, Entry $entry): bool
    {
        return $entry->isOwnedBy($user);
    }
}
