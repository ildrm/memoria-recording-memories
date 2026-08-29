<?php

namespace App\Models;

use App\Enums\RoleName;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;

class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'is_system',
    ];

    protected static function booted(): void
    {
        static::deleting(function (Role $role): void {
            if ($role->name === RoleName::SuperAdministrator->value || $role->users()->exists()) {
                throw new LogicException('Assigned roles and the super-administrator role cannot be deleted.');
            }
        });
    }

    /** @return BelongsToMany<User, $this, RoleUser, 'pivot'> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(RoleUser::class)
            ->withPivot(['assigned_by_user_id', 'assigned_at']);
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withPivot('created_at');
    }

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }
}
