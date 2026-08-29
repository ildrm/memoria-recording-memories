<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\CommentStatus;
use App\Enums\ReportStatus;
use App\Models\Comment;
use App\Models\Publication;
use App\Models\Report;
use App\Models\SocialPostFailure;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OperationalOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Public operations';

    protected ?string $description = 'Aggregated public-content and delivery metadata only. Private memory content is never queried here.';

    /** @return array<Stat> */
    protected function getStats(): array
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        $canManageReports = $user->hasPermissionTo('reports.manage');
        $canModerateComments = $user->hasPermissionTo('comments.moderate');
        $canViewFailures = $user->hasPermissionTo('social-failures.view');

        return [
            Stat::make(__('Public stories'), Publication::query()
                ->websitePublished()
                ->count())
                ->description(__('Explicitly published on the website'))
                ->descriptionIcon(Heroicon::OutlinedGlobeAlt)
                ->color('success'),
            Stat::make(__('Open reports'), $canManageReports ? Report::query()->where('status', ReportStatus::Open)->count() : __('Restricted'))
                ->description(__('Awaiting moderation'))
                ->descriptionIcon(Heroicon::OutlinedFlag)
                ->color('warning'),
            Stat::make(__('Pending comments'), $canModerateComments ? Comment::query()
                ->where('status', CommentStatus::Pending)
                ->whereIn('publication_id', Publication::query()->websitePublished()->select('id'))
                ->count() : __('Restricted'))
                ->description(__('On public publications'))
                ->descriptionIcon(Heroicon::OutlinedChatBubbleLeftRight)
                ->color('info'),
            Stat::make(__('Delivery failures'), $canViewFailures ? SocialPostFailure::query()->where('is_retryable', true)->count() : __('Restricted'))
                ->description(__('Retryable provider errors'))
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger'),
        ];
    }
}
