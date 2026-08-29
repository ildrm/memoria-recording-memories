<?php

namespace App\Filament\App\Pages;

use App\Enums\AttachmentMediaType;
use App\Enums\Mood;
use App\Models\Entry;
use App\Models\Journal;
use App\Models\Person;
use App\Models\Tag;
use App\Models\User;
use BackedEnum;
use DateTimeImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use UnitEnum;

class Search extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Write & remember';

    protected static ?int $navigationSort = 12;

    protected static ?string $title = 'Search memories';

    protected string $view = 'filament.app.pages.search';

    #[Url(as: 'q')]
    public string $query = '';

    #[Url]
    public string $journal = '';

    #[Url]
    public bool $favoritesOnly = false;

    #[Url]
    public string $tag = '';

    #[Url]
    public string $person = '';

    #[Url]
    public string $mood = '';

    #[Url(as: 'date_from')]
    public string $dateFrom = '';

    #[Url(as: 'date_to')]
    public string $dateTo = '';

    #[Url(as: 'archived')]
    public string $archivedState = 'exclude';

    #[Url(as: 'media')]
    public string $attachmentMediaType = '';

    public function mount(): void
    {
        $this->normalizeFilters();
    }

    public function updated(string $property): void
    {
        if (! in_array($property, [
            'query',
            'journal',
            'favoritesOnly',
            'tag',
            'person',
            'mood',
            'dateFrom',
            'dateTo',
            'archivedState',
            'attachmentMediaType',
        ], true)) {
            return;
        }

        $this->normalizeFilters();
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset([
            'query',
            'journal',
            'favoritesOnly',
            'tag',
            'person',
            'mood',
            'dateFrom',
            'dateTo',
            'archivedState',
            'attachmentMediaType',
        ]);
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return trim($this->query) !== ''
            || $this->journal !== ''
            || $this->favoritesOnly
            || $this->tag !== ''
            || $this->person !== ''
            || $this->mood !== ''
            || $this->dateFrom !== ''
            || $this->dateTo !== ''
            || $this->archivedState !== 'exclude'
            || $this->attachmentMediaType !== '';
    }

    /** @return Collection<int, Journal> */
    #[Computed]
    public function journals(): Collection
    {
        return Journal::query()
            ->ownedBy($this->user())
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** @return Collection<int, Tag> */
    #[Computed]
    public function tags(): Collection
    {
        return Tag::query()
            ->ownedBy($this->user())
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** @return Collection<int, Person> */
    #[Computed]
    public function people(): Collection
    {
        return Person::query()
            ->ownedBy($this->user())
            ->orderBy('display_name')
            ->get(['id', 'display_name']);
    }

    /** @return LengthAwarePaginator<int, Entry> */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        $search = trim($this->query);
        $journalId = $this->ownedId(Journal::query()->ownedBy($this->user()), $this->journal);
        $tagId = $this->ownedId(Tag::query()->ownedBy($this->user()), $this->tag);
        $personId = $this->ownedId(Person::query()->ownedBy($this->user()), $this->person);
        $mood = Mood::tryFrom($this->mood);
        $mediaType = AttachmentMediaType::tryFrom($this->attachmentMediaType);
        $dateFrom = $this->validDate($this->dateFrom);
        $dateTo = $this->validDate($this->dateTo);

        return Entry::query()
            ->ownedBy($this->user())
            ->with('journal:id,name')
            ->when($this->archivedState === 'exclude', fn (Builder $query): Builder => $query->whereNull('archived_at'))
            ->when($this->archivedState === 'only', fn (Builder $query): Builder => $query->whereNotNull('archived_at'))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->whereLike('title', '%'.$search.'%')
                        ->orWhereLike('body', '%'.$search.'%')
                        ->orWhereLike('location_name', '%'.$search.'%');
                });
            })
            ->when($journalId !== null, fn (Builder $query): Builder => $query->where('journal_id', $journalId))
            ->when($tagId !== null, fn (Builder $query): Builder => $query->whereHas('tags', fn (Builder $tags): Builder => $tags->whereKey($tagId)))
            ->when($personId !== null, fn (Builder $query): Builder => $query->whereHas('people', fn (Builder $people): Builder => $people->whereKey($personId)))
            ->when($mood !== null, fn (Builder $query): Builder => $query->where('mood', $mood))
            ->when($dateFrom !== null, fn (Builder $query): Builder => $query->whereDate('occurred_on', '>=', $dateFrom))
            ->when($dateTo !== null, fn (Builder $query): Builder => $query->whereDate('occurred_on', '<=', $dateTo))
            ->when($mediaType !== null, fn (Builder $query): Builder => $query->whereHas(
                'attachments',
                fn (Builder $attachments): Builder => $attachments->where('media_type', $mediaType),
            ))
            ->when($this->favoritesOnly, fn (Builder $query): Builder => $query->where('is_favorite', true))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(20);
    }

    private function normalizeFilters(): void
    {
        $this->journal = (string) ($this->ownedId(Journal::query()->ownedBy($this->user()), $this->journal) ?? '');
        $this->tag = (string) ($this->ownedId(Tag::query()->ownedBy($this->user()), $this->tag) ?? '');
        $this->person = (string) ($this->ownedId(Person::query()->ownedBy($this->user()), $this->person) ?? '');
        $mood = Mood::tryFrom($this->mood);
        $mediaType = AttachmentMediaType::tryFrom($this->attachmentMediaType);
        $this->mood = $mood instanceof Mood ? $mood->value : '';
        $this->attachmentMediaType = $mediaType instanceof AttachmentMediaType ? $mediaType->value : '';
        $this->dateFrom = $this->validDate($this->dateFrom) ?? '';
        $this->dateTo = $this->validDate($this->dateTo) ?? '';
        $this->archivedState = in_array($this->archivedState, ['exclude', 'include', 'only'], true)
            ? $this->archivedState
            : 'exclude';
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function ownedId(Builder $query, string $value): ?int
    {
        if ($value === '' || ! ctype_digit($value) || (int) $value < 1) {
            return null;
        }

        $id = $query->whereKey((int) $value)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function validDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value
            ? $value
            : null;
    }

    private function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
