<?php

namespace App\Policies;

use App\Models\Report;
use App\Models\User;

class ReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('reports.manage');
    }

    public function view(User $user, Report $report): bool
    {
        return $user->is($report->reporter) || $user->hasPermissionTo('reports.manage');
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Report $report): bool
    {
        return $user->hasPermissionTo('reports.manage');
    }

    public function assign(User $user, Report $report): bool
    {
        return $user->hasPermissionTo('reports.manage');
    }

    public function resolve(User $user, Report $report): bool
    {
        return $user->hasPermissionTo('reports.manage');
    }
}
