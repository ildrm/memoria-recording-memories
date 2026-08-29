<?php

namespace App\Policies;

use App\Models\Publication;
use App\Models\Reaction;
use App\Models\User;

class ReactionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(?User $user, Reaction $reaction): bool
    {
        return $reaction->publication->isPubliclyVisible();
    }

    public function create(User $user, Publication $publication): bool
    {
        return $publication->isPubliclyVisible() && $publication->reactions_enabled;
    }

    public function delete(User $user, Reaction $reaction): bool
    {
        return $reaction->isOwnedBy($user);
    }
}
