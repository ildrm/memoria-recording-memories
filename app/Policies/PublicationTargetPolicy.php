<?php

namespace App\Policies;

use App\Models\PublicationTarget;
use App\Models\User;

class PublicationTargetPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PublicationTarget $target): bool
    {
        return $target->isOwnedBy($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PublicationTarget $target): bool
    {
        return $target->isOwnedBy($user);
    }

    public function delete(User $user, PublicationTarget $target): bool
    {
        return $target->isOwnedBy($user);
    }
}
