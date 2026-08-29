<?php

namespace App\Policies;

use App\Models\ShareLink;
use App\Models\User;

class ShareLinkPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ShareLink $shareLink): bool
    {
        return $shareLink->isOwnedBy($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ShareLink $shareLink): bool
    {
        return $shareLink->isOwnedBy($user);
    }

    public function delete(User $user, ShareLink $shareLink): bool
    {
        return $shareLink->isOwnedBy($user);
    }

    public function revoke(User $user, ShareLink $shareLink): bool
    {
        return $shareLink->isOwnedBy($user);
    }
}
