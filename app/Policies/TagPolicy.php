<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

class TagPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Tag $tag): bool
    {
        return $tag->isOwnedBy($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Tag $tag): bool
    {
        return $tag->isOwnedBy($user);
    }

    public function delete(User $user, Tag $tag): bool
    {
        return $tag->isOwnedBy($user);
    }

    public function deleteAny(User $user): bool
    {
        return true;
    }
}
