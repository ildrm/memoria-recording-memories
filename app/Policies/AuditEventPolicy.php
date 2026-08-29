<?php

namespace App\Policies;

use App\Models\AuditEvent;
use App\Models\User;

class AuditEventPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AuditEvent $event): bool
    {
        return $user->is($event->actor) || $user->hasPermissionTo('audit-events.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditEvent $event): bool
    {
        return false;
    }

    public function delete(User $user, AuditEvent $event): bool
    {
        return false;
    }
}
