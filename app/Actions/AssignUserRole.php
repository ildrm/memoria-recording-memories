<?php

namespace App\Actions;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AssignUserRole
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
            $role = Role::query()
                ->where('name', $roleName->value)
                ->lockForUpdate()
                ->firstOrFail();

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

            if ($lockedSubject->roles()->whereKey($role->getKey())->exists()) {
                return false;
            }

            $lockedSubject->assignRole($role, $lockedActor);

            $this->auditRecorder->record(
                event: 'admin.user.role_assigned',
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
