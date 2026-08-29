<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class EligibleReportAssignees
{
    /** @param Builder<User> $query
     * @return Builder<User>
     */
    public function constrain(Builder $query): Builder
    {
        return $query
            ->whereNull('users.disabled_at')
            ->whereHas(
                'roles',
                fn (Builder $roleQuery): Builder => $roleQuery->whereIn('roles.name', [
                    RoleName::Moderator->value,
                    RoleName::Administrator->value,
                    RoleName::SuperAdministrator->value,
                ]),
            );
    }

    public function find(int $userId): ?User
    {
        return $this->constrain(User::query())
            ->whereKey($userId)
            ->first();
    }
}
