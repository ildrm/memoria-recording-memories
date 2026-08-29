<?php

namespace App\Filament\App\Pages;

use App\Enums\AttachmentScanStatus;
use App\Models\Entry;
use App\Models\EntryShare;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use UnitEnum;

class SharedMemories extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Share deliberately';

    protected static ?int $navigationSort = 15;

    protected static ?string $navigationLabel = 'Shared with me';

    protected static ?string $title = 'Shared with me';

    protected static ?string $slug = 'shared-with-me';

    protected string $view = 'filament.app.pages.shared-memories';

    #[Url(as: 'memory', history: true)]
    public ?int $selectedEntryId = null;

    #[Url(as: 'q')]
    public string $search = '';

    /** @return LengthAwarePaginator<int, EntryShare> */
    #[Computed]
    public function shares(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return EntryShare::query()
            ->active()
            ->whereBelongsTo($this->user(), 'recipient')
            ->whereHas('entry')
            ->when($search !== '', fn (Builder $query): Builder => $query
                ->where(function (Builder $query) use ($search): void {
                    $query
                        ->whereHas('entry', fn (Builder $entry): Builder => $entry
                            ->whereLike('title', '%'.$search.'%'))
                        ->orWhereHas('owner', fn (Builder $owner): Builder => $owner
                            ->whereLike('name', '%'.$search.'%'));
                }))
            ->with([
                'owner:id,name',
                'entry' => fn ($query) => $query->select([
                    'id', 'user_id', 'title', 'occurred_at', 'timezone',
                    'mood', 'custom_mood', 'location_name', 'importance',
                ]),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20, pageName: 'sharedPage');
    }

    /** @return array{share: EntryShare, entry: Entry}|null */
    #[Computed]
    public function selectedMemory(): ?array
    {
        if ($this->selectedEntryId === null) {
            return null;
        }

        $share = EntryShare::query()
            ->active()
            ->whereBelongsTo($this->user(), 'recipient')
            ->where('entry_id', $this->selectedEntryId)
            ->with([
                'owner:id,name',
                'entry',
            ])
            ->first();

        if (! $share instanceof EntryShare || ! $share->entry instanceof Entry) {
            return null;
        }

        Gate::forUser($this->user())->authorize('view', $share->entry);

        if ($share->include_attachments) {
            $share->entry->loadMissing([
                'attachments' => fn ($query) => $query
                    ->where('scan_status', AttachmentScanStatus::Clean)
                    ->orderBy('id'),
            ]);
        }

        return [
            'share' => $share,
            'entry' => $share->entry,
        ];
    }

    public function openMemory(int $entryId): void
    {
        $allowed = EntryShare::query()
            ->active()
            ->whereBelongsTo($this->user(), 'recipient')
            ->where('entry_id', $entryId)
            ->exists();

        abort_unless($allowed, 404);

        $this->selectedEntryId = $entryId;
        unset($this->selectedMemory);
        $this->dispatch('shared-memory-opened');
    }

    public function closeMemory(): void
    {
        $this->selectedEntryId = null;
        unset($this->selectedMemory);
    }

    public function updatedSearch(): void
    {
        $this->resetPage(pageName: 'sharedPage');
        unset($this->shares);
    }

    private function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
