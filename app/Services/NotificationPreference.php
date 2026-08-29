<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPreference;

class NotificationPreference
{
    public function allows(User $user, string $preference, bool $default = true): bool
    {
        $preferences = UserPreference::query()
            ->whereBelongsTo($user)
            ->value('notification_preferences');

        return (bool) data_get(
            is_array($preferences) ? $preferences : [],
            $preference,
            $default,
        );
    }
}
