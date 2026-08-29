<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserPreference;

class UserPreferencePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, UserPreference $preference): bool
    {
        return $user->is($preference->user);
    }

    public function update(User $user, UserPreference $preference): bool
    {
        return $user->is($preference->user);
    }
}
