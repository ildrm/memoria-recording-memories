<?php

namespace App\Models;

use App\Enums\RoleName;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use LogicException;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use InteractsWithAppAuthentication;
    use InteractsWithAppAuthenticationRecovery;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            if (! $user->isSuperAdministrator()) {
                return;
            }

            if (DB::transactionLevel() === 0) {
                throw new LogicException('Super administrator accounts must be deleted within a protected transaction.');
            }

            Role::query()
                ->where('name', RoleName::SuperAdministrator->value)
                ->lockForUpdate()
                ->first();

            if ($user->isLastSuperAdministrator()) {
                throw new LogicException('The last super administrator cannot be deleted.');
            }
        });
    }

    /** @return HasOne<UserProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    /** @return HasOne<UserPreference, $this> */
    public function preferences(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    /** @return BelongsToMany<Role, $this, RoleUser, 'pivot'> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->using(RoleUser::class)
            ->withPivot(['assigned_by_user_id', 'assigned_at']);
    }

    /** @return Builder<Permission> */
    public function permissions(): Builder
    {
        return Permission::query()->whereHas(
            'roles.users',
            fn ($query) => $query->whereKey($this->getKey()),
        );
    }

    /** @return HasMany<Journal, $this> */
    public function journals(): HasMany
    {
        return $this->hasMany(Journal::class);
    }

    /** @return HasMany<Entry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    /** @return HasMany<Tag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /** @return HasMany<Person, $this> */
    public function people(): HasMany
    {
        return $this->hasMany(Person::class);
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** @return HasMany<Publication, $this> */
    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }

    /** @return HasMany<SocialAccount, $this> */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** @return HasMany<Export, $this> */
    public function exports(): HasMany
    {
        return $this->hasMany(Export::class);
    }

    /** @return HasMany<Reminder, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** @return HasMany<Reaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class);
    }

    /** @return HasMany<Report, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'reporter_user_id');
    }

    /** @return HasMany<AuditEvent, $this> */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class, 'actor_user_id');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! User::query()->whereKey($this->getKey())->whereNull('disabled_at')->exists()) {
            return false;
        }

        if ($panel->getId() === 'admin') {
            return $this->hasAnyRole([
                RoleName::Moderator,
                RoleName::Administrator,
                RoleName::SuperAdministrator,
            ]);
        }

        return true;
    }

    public function hasRole(RoleName|string $role): bool
    {
        $roleName = $role instanceof RoleName ? $role->value : $role;

        return $this->roles()->where('roles.name', $roleName)->exists();
    }

    /**
     * @param  array<int, RoleName|string>  $roles
     */
    public function hasAnyRole(array $roles): bool
    {
        $roleNames = array_map(
            static fn (RoleName|string $role): string => $role instanceof RoleName ? $role->value : $role,
            $roles,
        );

        return $this->roles()->whereIn('roles.name', $roleNames)->exists();
    }

    public function hasPermissionTo(string $permission): bool
    {
        return $this->isSuperAdministrator()
            || $this->roles()->whereHas(
                'permissions',
                fn ($query) => $query->where('permissions.name', $permission),
            )->exists();
    }

    public function isSuperAdministrator(): bool
    {
        return $this->hasRole(RoleName::SuperAdministrator);
    }

    public function isLastSuperAdministrator(): bool
    {
        if (! $this->exists || ! $this->isSuperAdministrator()) {
            return false;
        }

        if (! User::query()->whereKey($this->getKey())->whereNull('disabled_at')->exists()) {
            return false;
        }

        return User::query()
            ->whereNull('disabled_at')
            ->whereHas(
                'roles',
                fn ($query) => $query->where('roles.name', RoleName::SuperAdministrator->value),
            )
            ->count() === 1;
    }

    public function assignRole(Role|RoleName|string $role, ?User $assignedBy = null): void
    {
        $roleModel = $this->resolveRole($role);

        $this->roles()->syncWithoutDetaching([
            $roleModel->getKey() => [
                'assigned_by_user_id' => $assignedBy?->getKey(),
                'assigned_at' => now(),
            ],
        ]);
    }

    public function removeRole(Role|RoleName|string $role): void
    {
        $roleModel = $this->resolveRole($role);

        if ($roleModel->name === RoleName::SuperAdministrator->value && $this->isLastSuperAdministrator()) {
            throw new LogicException('The last super administrator cannot lose that role.');
        }

        $this->roles()->detach($roleModel);
    }

    public function disable(): void
    {
        DB::transaction(function (): void {
            Role::query()
                ->where('name', RoleName::SuperAdministrator->value)
                ->lockForUpdate()
                ->first();

            $user = User::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($user->isLastSuperAdministrator()) {
                throw new LogicException('The last super administrator cannot be disabled.');
            }

            $user->forceFill(['disabled_at' => now()])->save();
        });

        $this->refresh();
    }

    protected function resolveRole(Role|RoleName|string $role): Role
    {
        if ($role instanceof Role) {
            return $role;
        }

        $roleName = $role instanceof RoleName ? $role->value : $role;

        return Role::query()->where('name', $roleName)->firstOrFail();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'immutable_datetime',
            'password_changed_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'account_deletion_requested_at' => 'immutable_datetime',
        ];
    }
}
