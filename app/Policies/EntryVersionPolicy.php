<?php

namespace App\Policies;

use App\Models\Entry;
use App\Models\EntryVersion;
use App\Models\User;

class EntryVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EntryVersion $version): bool
    {
        return Entry::query()
            ->accessibleTo($user)
            ->whereKey($version->entry_id)
            ->exists();
    }

    public function restore(User $user, EntryVersion $version): bool
    {
        return $version->isOwnedBy($user)
            && Entry::query()
                ->ownedBy($user)
                ->whereKey($version->entry_id)
                ->exists();
    }

    public function delete(User $user, EntryVersion $version): bool
    {
        return false;
    }
}
