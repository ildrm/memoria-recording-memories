<?php

namespace App\Filament\App\Pages;

use App\Models\Entry;
use App\Models\User;
use App\Models\UserPreference;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class OnThisDay extends MemoryCollectionPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Write & remember';

    protected static ?int $navigationSort = 14;

    protected static ?string $title = 'On this day';

    protected string $view = 'filament.app.pages.on-this-day';

    public static function shouldRegisterNavigation(): bool
    {
        return self::isEnabledFor(Filament::auth()->user());
    }

    public function isEnabled(): bool
    {
        return self::isEnabledFor($this->user());
    }

    /** @return Builder<Entry> */
    protected function entriesQuery(): Builder
    {
        $query = parent::entriesQuery();

        if (! $this->isEnabled()) {
            return $query->whereRaw('1 = 0');
        }

        $today = CarbonImmutable::now(FilamentTimezone::get());

        return $query
            ->whereMonth('occurred_on', $today->month)
            ->whereDay('occurred_on', $today->day)
            ->whereYear('occurred_on', '<', $today->year);
    }

    public function emptyHeading(): string
    {
        return __('No memories from this date yet');
    }

    public function emptyDescription(): string
    {
        return __('As the years gather, memories from this date will return here.');
    }

    private static function isEnabledFor(mixed $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return (bool) UserPreference::query()
            ->whereBelongsTo($user)
            ->value('on_this_day_enabled');
    }
}
