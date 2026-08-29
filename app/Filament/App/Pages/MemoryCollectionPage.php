<?php

namespace App\Filament\App\Pages;

use App\Models\Entry;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use UnitEnum;

abstract class MemoryCollectionPage extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Write & remember';

    protected string $view = 'filament.app.pages.memory-collection';

    /** @return LengthAwarePaginator<int, Entry> */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        return $this->entriesQuery()
            ->with('journal:id,name')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(20);
    }

    public function emptyHeading(): string
    {
        return __('No memories here');
    }

    public function emptyDescription(): string
    {
        return __('Memories will appear here when they match this view.');
    }

    /** @return Builder<Entry> */
    protected function entriesQuery(): Builder
    {
        return Entry::query()
            ->ownedBy($this->user())
            ->whereNull('archived_at');
    }

    protected function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
