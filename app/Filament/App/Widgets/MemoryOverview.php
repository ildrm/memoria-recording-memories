<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Pages\Calendar;
use App\Filament\App\Pages\Timeline;
use App\Filament\App\Resources\EntryResource;
use App\Models\Entry;
use App\Models\Reminder;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Widgets\Widget;

class MemoryOverview extends Widget
{
    protected static ?int $sort = -10;

    protected string $view = 'filament.app.widgets.memory-overview';

    protected int|string|array $columnSpan = 'full';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        $today = now(FilamentTimezone::get());
        $baseQuery = Entry::query()->ownedBy($user)->whereNull('archived_at');

        return [
            'user' => $user,
            'recentEntries' => (clone $baseQuery)
                ->with('journal:id,name')
                ->orderByDesc('occurred_at')
                ->limit(3)
                ->get(),
            'favoriteCount' => (clone $baseQuery)->where('is_favorite', true)->count(),
            'onThisDayCount' => (clone $baseQuery)
                ->whereMonth('occurred_on', $today->month)
                ->whereDay('occurred_on', $today->day)
                ->whereYear('occurred_on', '<', $today->year)
                ->count(),
            'nextReminder' => Reminder::query()
                ->ownedBy($user)
                ->where('is_enabled', true)
                ->whereNotNull('next_run_at')
                ->orderBy('next_run_at')
                ->first(),
            'writeUrl' => EntryResource::getUrl('create'),
            'timelineUrl' => Timeline::getUrl(),
            'calendarUrl' => Calendar::getUrl(),
        ];
    }
}
