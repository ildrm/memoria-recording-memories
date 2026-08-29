<?php

namespace App\Models;

use App\Enums\RoleName;
use Illuminate\Database\Eloquent\Relations\Pivot;
use LogicException;

class RoleUser extends Pivot
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'role_user';

    protected static function booted(): void
    {
        static::deleting(function (RoleUser $assignment): void {
            $role = Role::query()->find($assignment->role_id);
            $user = User::query()->find($assignment->user_id);

            if ($role?->name === RoleName::SuperAdministrator->value && $user?->isLastSuperAdministrator()) {
                throw new LogicException('The last super administrator cannot lose that role.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'assigned_at' => 'immutable_datetime',
        ];
    }
}
