<?php

namespace App\Filament\App\Pages;

use App\Models\Entry;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use UnitEnum;

class Calendar extends Page
{
    private const ENTRY_RENDER_LIMIT = 250;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Write & remember';

    protected static ?int $navigationSort = 12;

    protected static ?string $title = 'Memory calendar';

    protected string $view = 'filament.app.pages.calendar';

    #[Url]
    public string $month = '';

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = CarbonImmutable::now(FilamentTimezone::get())->format('Y-m');
        }
    }

    public function previousMonth(): void
    {
        $this->month = $this->currentMonth()->subMonth()->format('Y-m');
        $this->forgetMonthData();
    }

    public function nextMonth(): void
    {
        $this->month = $this->currentMonth()->addMonth()->format('Y-m');
        $this->forgetMonthData();
    }

    public function goToToday(): void
    {
        $this->month = CarbonImmutable::now(FilamentTimezone::get())->format('Y-m');
        $this->forgetMonthData();
    }

    /** @return Collection<int, Entry> */
    #[Computed]
    public function entries(): Collection
    {
        $month = $this->currentMonth();

        return Entry::query()
            ->ownedBy($this->user())
            ->with('journal:id,name')
            ->whereNull('archived_at')
            ->whereDate('occurred_on', '>=', $month->startOfMonth()->toDateString())
            ->whereDate('occurred_on', '<=', $month->endOfMonth()->toDateString())
            ->orderBy('occurred_at')
            ->limit(self::ENTRY_RENDER_LIMIT)
            ->get();
    }

    /** @return SupportCollection<string, int> */
    #[Computed]
    public function dayCounts(): SupportCollection
    {
        $month = $this->currentMonth();

        return Entry::query()
            ->ownedBy($this->user())
            ->whereNull('archived_at')
            ->whereDate('occurred_on', '>=', $month->startOfMonth()->toDateString())
            ->whereDate('occurred_on', '<=', $month->endOfMonth()->toDateString())
            ->select('occurred_on')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('occurred_on')
            ->get()
            ->mapWithKeys(function (Entry $entry): array {
                $occurredOn = $entry->getAttribute('occurred_on');

                return $occurredOn instanceof CarbonImmutable
                    ? [$occurredOn->toDateString() => (int) $entry->getAttribute('aggregate')]
                    : [];
            });
    }

    #[Computed]
    public function totalEntries(): int
    {
        return $this->dayCounts()->sum();
    }

    public function isCapped(): bool
    {
        return $this->totalEntries() > self::ENTRY_RENDER_LIMIT;
    }

    public function hiddenEntryCount(): int
    {
        return max(0, $this->totalEntries() - $this->entries()->count());
    }

    public function searchUrl(?CarbonImmutable $day = null): string
    {
        $from = ($day ?? $this->currentMonth()->startOfMonth())->toDateString();
        $to = ($day ?? $this->currentMonth()->endOfMonth())->toDateString();

        return Search::getUrl([
            'date_from' => $from,
            'date_to' => $to,
        ]);
    }

    /** @return array<int, CarbonImmutable> */
    #[Computed]
    public function days(): array
    {
        $month = $this->currentMonth();
        $start = $month->startOfMonth()->startOfWeek();
        $end = $month->endOfMonth()->endOfWeek();
        $days = [];

        for ($day = $start; $day <= $end; $day = $day->addDay()) {
            $days[] = $day;
        }

        return $days;
    }

    public function currentMonth(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->month, FilamentTimezone::get())
            ?: CarbonImmutable::now(FilamentTimezone::get())->startOfMonth();
    }

    private function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function forgetMonthData(): void
    {
        unset($this->entries, $this->days, $this->dayCounts, $this->totalEntries);
    }
}
