<?php

namespace App\Policies;

use App\Models\PublicationVersion;
use App\Models\User;

class PublicationVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PublicationVersion $version): bool
    {
        return $version->isOwnedBy($user);
    }

    public function restore(User $user, PublicationVersion $version): bool
    {
        return $version->isOwnedBy($user);
    }

    public function delete(User $user, PublicationVersion $version): bool
    {
        return false;
    }
}
