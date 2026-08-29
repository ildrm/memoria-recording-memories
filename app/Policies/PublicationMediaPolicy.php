<?php

namespace App\Policies;

use App\Models\Publication;
use App\Models\PublicationMedia;
use App\Models\User;

class PublicationMediaPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(?User $user, PublicationMedia $media): bool
    {
        $publication = Publication::query()->find($media->publication_id);

        return ($publication?->isPubliclyVisible() ?? false)
            || ($user !== null && $media->isOwnedBy($user));
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PublicationMedia $media): bool
    {
        return $media->isOwnedBy($user);
    }

    public function delete(User $user, PublicationMedia $media): bool
    {
        return $media->isOwnedBy($user);
    }
}
