<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\JournalResource\Pages;
use App\Models\Journal;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class JournalResource extends OwnedResource
{
    protected static ?string $model = Journal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Organize';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Journal details'))
                    ->description(__('A journal gathers related memories. Every entry inside remains private.'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->placeholder(__('Travel, family, everyday life…'))
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state): mixed => $set('slug', Str::slug($state ?? ''))),
                        TextInput::make('slug')
                            ->label(__('Address key'))
                            ->helperText(__('Used only inside your private diary.'))
                            ->required()
                            ->maxLength(120)
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule->where(
                                    'user_id',
                                    Filament::auth()->id(),
                                ),
                            ),
                        Textarea::make('description')
                            ->label(__('Description'))
                            ->placeholder(__('What belongs in this journal?'))
                            ->rows(4)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        Select::make('icon')
                            ->label(__('Symbol'))
                            ->options([
                                'book-open' => __('Open book'),
                                'map' => __('Map'),
                                'home' => __('Home'),
                                'heart' => __('Heart'),
                                'sparkles' => __('Sparkles'),
                            ])
                            ->native(false),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Journal'))
                    ->description(fn (Journal $record): string => $record->description ? Str::limit($record->description, 80) : __('Private journal'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('entries_count')
                    ->label(__('Memories'))
                    ->counts('entries')
                    ->badge()
                    ->sortable(),
                TextColumn::make('archived_at')
                    ->label(__('State'))
                    ->state(fn (Journal $record): string => $record->archived_at ? __('Archived') : __('Active'))
                    ->badge()
                    ->color(fn (Journal $record): string => $record->archived_at ? 'gray' : 'success'),
                TextColumn::make('updated_at')
                    ->label(__('Last changed'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([
                TernaryFilter::make('archive_state')
                    ->label(__('Archive state'))
                    ->placeholder(__('All journals'))
                    ->trueLabel(__('Archived only'))
                    ->falseLabel(__('Active only'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('archived_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('archived_at'),
                        blank: fn (Builder $query): Builder => $query,
                    )
                    ->default(false),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('toggleArchive')
                    ->label(fn (Journal $record): string => $record->archived_at ? __('Restore journal') : __('Archive journal'))
                    ->icon(fn (Journal $record): Heroicon => $record->archived_at ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedArchiveBox)
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->action(fn (Journal $record): bool => $record->update([
                        'archived_at' => $record->archived_at ? null : now(),
                    ])),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords(),
                ]),
            ])
            ->emptyStateHeading(__('No journals yet'))
            ->emptyStateDescription(__('Create a journal to give your memories a home.'))
            ->emptyStateIcon(Heroicon::OutlinedBookOpen);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJournals::route('/'),
            'create' => Pages\CreateJournal::route('/create'),
            'edit' => Pages\EditJournal::route('/{record}/edit'),
        ];
    }
}
