<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        'users.view' => 'View user account metadata',
        'users.manage' => 'Manage user account status',
        'roles.view' => 'View roles and permissions',
        'roles.manage' => 'Manage roles and permissions',
        'roles.assign' => 'Assign roles to users',
        'publications.moderate' => 'Moderate content that is already public',
        'comments.moderate' => 'Moderate comments on public publications',
        'reports.manage' => 'Review and resolve public-content reports',
        'social-failures.view' => 'View sanitized social publishing failure metadata',
        'audit-events.view' => 'View privacy-safe system audit metadata',
        'system.manage' => 'Manage privileged system configuration',
    ];

    public function run(): void
    {
        $permissions = collect(self::PERMISSIONS)->mapWithKeys(function (string $description, string $name): array {
            $permission = Permission::query()->updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => Str::of($name)->replace(['.', '-'], ' ')->title()->toString(),
                    'description' => $description,
                ],
            );

            return [$name => $permission];
        });

        foreach (RoleName::cases() as $roleName) {
            $role = Role::query()->updateOrCreate(
                ['name' => $roleName->value],
                [
                    'display_name' => $roleName->label(),
                    'description' => $this->descriptionFor($roleName),
                    'is_system' => true,
                ],
            );

            $role->permissions()->sync(
                $permissions->only($this->permissionsFor($roleName))->pluck('id')->all(),
            );
        }
    }

    private function descriptionFor(RoleName $roleName): string
    {
        return match ($roleName) {
            RoleName::User => 'Manages only personally owned or explicitly shared content.',
            RoleName::Moderator => 'Moderates public content and reports without private diary access.',
            RoleName::Administrator => 'Manages users and operational metadata without private diary access.',
            RoleName::SuperAdministrator => 'Manages privileged system configuration without ordinary private diary access.',
        };
    }

    /**
     * @return array<int, string>
     */
    private function permissionsFor(RoleName $roleName): array
    {
        return match ($roleName) {
            RoleName::User => [],
            RoleName::Moderator => [
                'publications.moderate',
                'comments.moderate',
                'reports.manage',
            ],
            RoleName::Administrator => [
                'users.view',
                'users.manage',
                'roles.view',
                'publications.moderate',
                'comments.moderate',
                'reports.manage',
                'social-failures.view',
                'audit-events.view',
            ],
            RoleName::SuperAdministrator => array_keys(self::PERMISSIONS),
        };
    }
}
