<?php

namespace App\Filament\App\Pages;

use App\Actions\ForceDeleteEntry;
use App\Models\Entry;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use UnitEnum;

class Trash extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrash;

    protected static string|UnitEnum|null $navigationGroup = 'Organize';

    protected static ?int $navigationSort = 36;

    protected static ?string $title = 'Trash';

    protected string $view = 'filament.app.pages.trash';

    /** @return LengthAwarePaginator<int, Entry> */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        return Entry::query()
            ->ownedBy($this->user())
            ->onlyTrashed()
            ->with('journal:id,name')
            ->orderByDesc('deleted_at')
            ->paginate(20);
    }

    public function restore(int $entryId): void
    {
        $entry = $this->trashedEntry($entryId);
        Gate::forUser($this->user())->authorize('restore', $entry);
        $entry->restore();
        unset($this->entries);

        Notification::make()->success()->title(__('Memory restored'))->send();
    }

    public function deletePermanently(int $entryId): void
    {
        $entry = $this->trashedEntry($entryId);
        app(ForceDeleteEntry::class)->handle($entry, $this->user());
        unset($this->entries);

        Notification::make()->success()->title(__('Memory permanently deleted'))->send();
    }

    private function trashedEntry(int $entryId): Entry
    {
        return Entry::query()
            ->ownedBy($this->user())
            ->onlyTrashed()
            ->findOrFail($entryId);
    }

    private function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
