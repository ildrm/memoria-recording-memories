<?php

namespace App\Policies;

use App\Models\Journal;
use App\Models\User;

class JournalPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Journal $journal): bool
    {
        return $journal->isOwnedBy($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Journal $journal): bool
    {
        return $journal->isOwnedBy($user);
    }

    public function delete(User $user, Journal $journal): bool
    {
        return $journal->isOwnedBy($user);
    }

    public function deleteAny(User $user): bool
    {
        return true;
    }

    public function reorder(User $user): bool
    {
        return true;
    }

    public function restore(User $user, Journal $journal): bool
    {
        return $journal->isOwnedBy($user);
    }

    public function forceDelete(User $user, Journal $journal): bool
    {
        return $journal->isOwnedBy($user);
    }
}
