<?php

namespace App\Filament\App\Resources;

use App\Enums\EntryStatus;
use App\Enums\Mood;
use App\Filament\App\Resources\EntryResource\Pages;
use App\Filament\App\Resources\EntryResource\RelationManagers\AttachmentsRelationManager;
use App\Filament\App\Resources\EntryResource\RelationManagers\EntrySharesRelationManager;
use App\Filament\App\Resources\EntryResource\RelationManagers\VersionsRelationManager;
use App\Models\Entry;
use App\Models\Journal;
use App\Models\Person;
use App\Models\Tag;
use App\Models\User;
use BackedEnum;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class EntryResource extends OwnedResource
{
    protected static ?string $model = Entry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Write & remember';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Memories';

    protected static ?string $modelLabel = 'memory';

    protected static ?string $pluralModelLabel = 'memories';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(12)
            ->components([
                View::make('filament.app.components.autosave-status')
                    ->columnSpanFull(),
                Section::make(__('Write your memory'))
                    ->description(__('This entry is private. Saving, tagging, or adding a file never publishes it.'))
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->schema([
                        TextInput::make('title')
                            ->hiddenLabel()
                            ->placeholder(__('Give this memory a title'))
                            ->maxLength(255)
                            ->extraInputAttributes(['class' => 'text-xl'])
                            ->live(onBlur: true)
                            ->afterStateUpdated(static::autosave(...)),
                        RichEditor::make('body')
                            ->hiddenLabel()
                            ->placeholder(__('What happened? Write it as you remember it…'))
                            ->maxLength((int) config('memoria.rich_text.maximum_characters', 125000))
                            ->helperText(__('Up to 125,000 characters of formatted writing.'))
                            ->toolbarButtons([
                                ['bold', 'italic', 'underline', 'link'],
                                ['h2', 'h3'],
                                ['blockquote', 'bulletList', 'orderedList'],
                                ['undo', 'redo'],
                            ])
                            ->fileAttachments(false)
                            ->columnSpanFull()
                            ->live(debounce: 1500)
                            ->afterStateUpdated(static::autosave(...)),
                    ])
                    ->columnSpan(['default' => 12, 'xl' => 8]),
                Section::make(__('Memory details'))
                    ->description(__('Add context when it helps. You can leave everything optional.'))
                    ->schema([
                        DateTimePicker::make('occurred_at')
                            ->label(__('When it happened'))
                            ->native()
                            ->seconds(false)
                            ->timezone(fn (Get $get): string => (string) ($get('timezone') ?: FilamentTimezone::get()))
                            ->default(now())
                            ->live(onBlur: true)
                            ->afterStateUpdated(static::autosave(...)),
                        Select::make('timezone')
                            ->label(__('Timezone'))
                            ->options(array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                            ->searchable()
                            ->default(fn (): string => FilamentTimezone::get()),
                        Select::make('journal_id')
                            ->label(__('Journal'))
                            ->relationship(
                                'journal',
                                'name',
                                modifyQueryUsing: fn (Builder $query): Builder => self::scopeRelationshipToOwner($query),
                            )
                            ->searchable()
                            ->preload()
                            ->native(false),
                        Select::make('mood')
                            ->label(__('Mood'))
                            ->options(self::enumOptions(Mood::cases()))
                            ->live()
                            ->native(false),
                        TextInput::make('custom_mood')
                            ->label(__('Your words for the mood'))
                            ->maxLength(80)
                            ->visible(fn (Get $get): bool => $get('mood') === Mood::Custom->value),
                        TextInput::make('location_name')
                            ->label(__('Place'))
                            ->placeholder(__('A name only—you control what to record'))
                            ->maxLength(255),
                        Select::make('importance')
                            ->label(__('Significance'))
                            ->options([
                                0 => __('Everyday moment'),
                                1 => __('Meaningful'),
                                2 => __('Important'),
                                3 => __('Milestone'),
                            ])
                            ->native(false)
                            ->default(0),
                        Select::make('tags')
                            ->label(__('Tags'))
                            ->relationship(
                                'tags',
                                'name',
                                modifyQueryUsing: fn (Builder $query): Builder => self::scopeRelationshipToOwner($query),
                            )
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->saveRelationshipsUsing(static::saveOwnedTags(...)),
                        Select::make('people')
                            ->label(__('People'))
                            ->relationship(
                                'people',
                                'display_name',
                                modifyQueryUsing: fn (Builder $query): Builder => self::scopeRelationshipToOwner($query),
                            )
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->saveRelationshipsUsing(static::saveOwnedPeople(...)),
                        Toggle::make('is_favorite')
                            ->label(__('Favorite memory'))
                            ->helperText(__('Favorites stay private.')),
                        Select::make('status')
                            ->label(__('Writing state'))
                            ->options(self::enumOptions(EntryStatus::cases()))
                            ->default(EntryStatus::Draft->value)
                            ->native(false)
                            ->required(),
                        Hidden::make('revision'),
                    ])
                    ->collapsible()
                    ->columnSpan(['default' => 12, 'xl' => 4]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_on')
                    ->label(__('When'))
                    ->date('M j, Y')
                    ->placeholder(__('Date not set'))
                    ->sortable(['occurred_at']),
                TextColumn::make('title')
                    ->label(__('Memory'))
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? $state : __('Untitled memory'))
                    ->description(fn (Entry $record): string => self::journalName($record))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('privacy')
                    ->label(__('Privacy'))
                    ->state(fn (): string => __('Only me'))
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->color('success')
                    ->badge(),
                TextColumn::make('mood')
                    ->label(__('Mood'))
                    ->formatStateUsing(fn (Mood|string|null $state): string => $state instanceof Mood ? $state->label() : Str::headline((string) $state))
                    ->placeholder(__('Not set'))
                    ->toggleable(),
                IconColumn::make('is_favorite')
                    ->label(__('Favorite'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('last_saved_at')
                    ->label(__('Saved'))
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder(__('Not yet'))
                    ->toggleable(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                SelectFilter::make('journal_id')
                    ->label(__('Journal'))
                    ->relationship(
                        'journal',
                        'name',
                        fn (Builder $query): Builder => self::scopeRelationshipToOwner($query),
                    )
                    ->searchable()
                    ->preload(),
                SelectFilter::make('mood')
                    ->label(__('Mood'))
                    ->options(self::enumOptions(Mood::cases())),
                Filter::make('favorites')
                    ->label(__('Favorites'))
                    ->query(fn (Builder $query): Builder => $query->where('is_favorite', true)),
                TernaryFilter::make('archive_state')
                    ->label(__('Archive state'))
                    ->placeholder(__('All memories'))
                    ->trueLabel(__('Archived only'))
                    ->falseLabel(__('Active only'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('archived_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('archived_at'),
                        blank: fn (Builder $query): Builder => $query,
                    )
                    ->default(false),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->recordActions([
                EditAction::make()->label(__('Open')),
                Action::make('toggleFavorite')
                    ->label(fn (Entry $record): string => $record->is_favorite ? __('Remove favorite') : __('Add to favorites'))
                    ->icon(fn (Entry $record): Heroicon => $record->is_favorite ? Heroicon::Heart : Heroicon::OutlinedHeart)
                    ->color('gray')
                    ->authorize('update')
                    ->action(fn (Entry $record): bool => $record->update(['is_favorite' => ! $record->is_favorite])),
                Action::make('toggleArchive')
                    ->label(fn (Entry $record): string => $record->archived_at ? __('Restore from archive') : __('Archive'))
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->color('gray')
                    ->authorize('update')
                    ->action(fn (Entry $record): bool => $record->update(['archived_at' => $record->archived_at ? null : now()])),
                DeleteAction::make()->label(__('Move to trash')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label(__('Move selected to trash'))
                        ->authorizeIndividualRecords(),
                ]),
            ])
            ->emptyStateHeading(__('Your diary is empty'))
            ->emptyStateDescription(__("Write the first memory you'd like to keep."))
            ->emptyStateIcon(Heroicon::OutlinedPencilSquare);
    }

    public static function getRelations(): array
    {
        return [
            AttachmentsRelationManager::class,
            EntrySharesRelationManager::class,
            VersionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEntries::route('/'),
            'create' => Pages\CreateEntry::route('/create'),
            'edit' => Pages\EditEntry::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('journal:id,name');
    }

    /**
     * @param  array<int, BackedEnum>  $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases): array
    {
        return collect($cases)
            ->mapWithKeys(fn (BackedEnum $case): array => [$case->value => method_exists($case, 'label') ? $case->label() : Str::headline($case->name)])
            ->all();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function scopeRelationshipToOwner(Builder $query): Builder
    {
        $user = Filament::auth()->user();

        abort_unless($user instanceof User, 403);

        return $query->whereBelongsTo($user, 'owner');
    }

    private static function autosave(mixed $livewire): void
    {
        if (method_exists($livewire, 'autosave')) {
            $livewire->autosave();
        }
    }

    private static function saveOwnedTags(Select $component): void
    {
        $user = Filament::auth()->user();
        $entry = $component->getRecord();

        abort_unless($user instanceof User && $entry instanceof Entry && $entry->isOwnedBy($user), 403);

        $requestedIds = self::relationshipIds($component);
        $ownedIds = Tag::query()
            ->ownedBy($user)
            ->whereKey($requestedIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if (count($ownedIds) !== count($requestedIds)) {
            throw ValidationException::withMessages([
                'tags' => [__('One or more selected tags are unavailable.')],
            ]);
        }

        $entry->tags()->sync($ownedIds);
    }

    private static function saveOwnedPeople(Select $component): void
    {
        $user = Filament::auth()->user();
        $entry = $component->getRecord();

        abort_unless($user instanceof User && $entry instanceof Entry && $entry->isOwnedBy($user), 403);

        $requestedIds = self::relationshipIds($component);
        $ownedIds = Person::query()
            ->ownedBy($user)
            ->whereKey($requestedIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if (count($ownedIds) !== count($requestedIds)) {
            throw ValidationException::withMessages([
                'people' => [__('One or more selected people are unavailable.')],
            ]);
        }

        $entry->people()->sync($ownedIds);
    }

    /** @return array<int, int> */
    private static function relationshipIds(Select $component): array
    {
        $state = $component->getState();
        $state = is_array($state) ? $state : [$state];
        $ids = array_map(static fn (mixed $id): int => (int) $id, $state);

        return array_values(array_unique(array_filter(
            $ids,
            static fn (int $id): bool => $id > 0,
        )));
    }

    private static function journalName(Entry $entry): string
    {
        $journal = $entry->journal;

        return $journal instanceof Journal ? $journal->name : __('No journal');
    }
}
