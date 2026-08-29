<?php

namespace App\Policies;

use App\Models\Publication;
use App\Models\User;

class PublicationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(?User $user, Publication $publication): bool
    {
        return $publication->isPubliclyVisible()
            || ($user !== null && $publication->isOwnedBy($user));
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Publication $publication): bool
    {
        return $publication->isOwnedBy($user);
    }

    public function delete(User $user, Publication $publication): bool
    {
        return $publication->isOwnedBy($user)
            || $this->canModeratePublicPublication($user, $publication);
    }

    public function restore(User $user, Publication $publication): bool
    {
        return $publication->isOwnedBy($user);
    }

    public function forceDelete(User $user, Publication $publication): bool
    {
        return $publication->isOwnedBy($user);
    }

    public function publish(User $user, Publication $publication): bool
    {
        return $publication->isOwnedBy($user);
    }

    public function moderate(User $user, Publication $publication): bool
    {
        return $this->canModeratePublicPublication($user, $publication);
    }

    private function canModeratePublicPublication(User $user, Publication $publication): bool
    {
        return $publication->isPubliclyVisible() && $user->hasPermissionTo('publications.moderate');
    }
}
