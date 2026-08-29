<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserProfile;

class UserProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(?User $user, UserProfile $profile): bool
    {
        return $profile->is_public
            || ($user !== null && (int) $profile->user_id === (int) $user->getKey());
    }

    public function update(User $user, UserProfile $profile): bool
    {
        return (int) $profile->user_id === (int) $user->getKey();
    }
}
