<?php

namespace App\Actions;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RemoveUserRole
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function handle(
        User $subject,
        RoleName $roleName,
        User $actor,
        ?Request $request = null,
    ): bool {
        Gate::forUser($actor)->authorize('manageRoles', $subject);

        return DB::transaction(function () use ($actor, $request, $roleName, $subject): bool {
            $users = User::query()
                ->whereKey([$actor->getKey(), $subject->getKey()])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (User $user): int|string => $user->getKey());

            /** @var User $lockedActor */
            $lockedActor = $users->get($actor->getKey());
            /** @var User $lockedSubject */
            $lockedSubject = $users->get($subject->getKey());

            Gate::forUser($lockedActor)->authorize('manageRoles', $lockedSubject);

            $role = Role::query()
                ->where('name', $roleName->value)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedSubject->roles()->whereKey($role->getKey())->exists()) {
                return false;
            }

            if ($roleName === RoleName::SuperAdministrator) {
                $superAdministratorCount = $role->users()->count();
                $activeSuperAdministratorCount = $role->users()
                    ->whereNull('users.disabled_at')
                    ->count();

                if ($superAdministratorCount <= 1
                    || ($lockedSubject->disabled_at === null && $activeSuperAdministratorCount <= 1)) {
                    throw ValidationException::withMessages([
                        'role' => [__('Keep at least one active super administrator before removing this role.')],
                    ]);
                }
            }

            $lockedSubject->removeRole($role);

            $this->auditRecorder->record(
                event: 'admin.user.role_removed',
                actor: $lockedActor,
                auditable: $lockedSubject,
                metadata: [
                    'target_user_id' => $lockedSubject->getKey(),
                    'role' => $roleName->value,
                ],
                request: $request,
            );

            return true;
        });
    }
}
