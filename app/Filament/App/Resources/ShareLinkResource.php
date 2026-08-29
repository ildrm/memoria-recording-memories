<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\ShareLinkResource\Pages;
use App\Models\Entry;
use App\Models\ShareLink;
use App\Models\User;
use App\Models\UserPreference;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ShareLinkResource extends OwnedResource
{
    protected static ?string $model = ShareLink::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|UnitEnum|null $navigationGroup = 'Share deliberately';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Private sharing';

    protected static ?string $modelLabel = 'private link';

    protected static ?string $pluralModelLabel = 'private links';

    protected static ?string $recordTitleAttribute = 'label';

    public static function canViewAny(): bool
    {
        return Filament::auth()->user() instanceof User;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                View::make('filament.app.components.created-share-link')
                    ->visible(fn (): bool => session()->has('created_share_url')),
                Section::make(__('Private link'))
                    ->description(__('Unlisted links never appear in public navigation. You can expire or revoke them at any time.'))
                    ->schema([
                        Select::make('entry_id')
                            ->label(__('Memory to share'))
                            ->relationship(
                                name: 'entry',
                                titleAttribute: 'title',
                                modifyQueryUsing: fn (Builder $query): Builder => self::scopeEntriesToOwner($query),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Entry $record): string => self::entryOptionLabel($record))
                            ->searchable(['title', 'location_name'])
                            ->searchPrompt(__('Search all active memories by title or place'))
                            ->searchDebounce(500)
                            ->helperText(__('Results are loaded as you search, so older memories remain available.'))
                            ->required()
                            ->visible(fn (string $operation): bool => $operation === 'create'),
                        TextInput::make('label')
                            ->label(__('Private label'))
                            ->placeholder(__('For Maya · family photos'))
                            ->helperText(__('Only you see this label.'))
                            ->maxLength(255),
                        TextInput::make('password')
                            ->label(__('Optional password'))
                            ->password()
                            ->revealable()
                            ->minLength(10)
                            ->maxLength(255)
                            ->visible(fn (string $operation): bool => $operation === 'create'),
                        DateTimePicker::make('expires_at')
                            ->label(__('Expires'))
                            ->native(false)
                            ->seconds(false)
                            ->minDate(now()->addMinute())
                            ->maxDate(now()->addDays((int) config('memoria.shares.maximum_expiration_days', 365)))
                            ->default(fn (string $operation): mixed => $operation === 'create'
                                ? now()->addDays((int) config('memoria.shares.default_expiration_days', 30))
                                : null)
                            ->helperText(__('A default expiry protects forgotten links. Clear it only when you deliberately want a link that never expires.')),
                        TextInput::make('max_views')
                            ->label(__('Optional view limit'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100000),
                        Toggle::make('track_views')
                            ->label(__('Count link opens'))
                            ->default(fn (): bool => self::privacyDefault('share_view_tracking_default'))
                            ->helperText(__('Counts are approximate and do not identify visitors.')),
                        Toggle::make('include_attachments')
                            ->label(__('Include approved attachments'))
                            ->default(fn (): bool => self::privacyDefault('include_attachments_default'))
                            ->helperText(__('Only files explicitly allowed by the secure sharing endpoint are included.')),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label(__('Private link'))
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? $state : __('Unnamed link'))
                    ->description(fn (ShareLink $record): string => self::entryTitle($record))
                    ->searchable(),
                TextColumn::make('state')
                    ->label(__('State'))
                    ->state(fn (ShareLink $record): string => $record->revoked_at ? __('Revoked') : ($record->isUsable() ? __('Active') : __('Expired')))
                    ->icon(fn (ShareLink $record): Heroicon => $record->revoked_at ? Heroicon::OutlinedLinkSlash : ($record->isUsable() ? Heroicon::OutlinedLink : Heroicon::OutlinedClock))
                    ->badge()
                    ->color(fn (ShareLink $record): string => $record->isUsable() ? 'success' : 'gray'),
                IconColumn::make('password_hash')
                    ->label(__('Password'))
                    ->boolean(fn (mixed $state): bool => filled($state)),
                TextColumn::make('view_count')
                    ->label(__('Opens'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->label(__('Expires'))
                    ->dateTime()
                    ->placeholder(__('Never'))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->since()
                    ->dateTimeTooltip(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('active')
                    ->label(__('Active only'))
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNull('revoked_at')
                        ->where(fn (Builder $query): Builder => $query
                            ->whereNull('expires_at')
                            ->orWhere('expires_at', '>', now()))
                        ->where(fn (Builder $query): Builder => $query
                            ->whereNull('max_views')
                            ->orWhereColumn('view_count', '<', 'max_views'))),
                Filter::make('revoked')
                    ->label(__('Revoked'))
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('revoked_at')),
            ])
            ->recordActions([
                EditAction::make()->label(__('Manage')),
            ])
            ->emptyStateHeading(__('No private links'))
            ->emptyStateDescription(__('Create an expiring, revocable link when you want to share one memory privately.'))
            ->emptyStateIcon(Heroicon::OutlinedLink);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShareLinks::route('/'),
            'create' => Pages\CreateShareLink::route('/create'),
            'edit' => Pages\EditShareLink::route('/{record}/edit'),
        ];
    }

    /**
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    private static function scopeEntriesToOwner(Builder $query): Builder
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $query
            ->whereBelongsTo($user, 'owner')
            ->whereNull('archived_at')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }

    private static function entryTitle(ShareLink $shareLink): string
    {
        $entry = $shareLink->entry;

        return $entry instanceof Entry && filled($entry->title)
            ? $entry->title
            : __('Untitled memory');
    }

    private static function entryOptionLabel(Entry $entry): string
    {
        $title = filled($entry->title) ? $entry->title : __('Untitled memory');
        $date = $entry->localOccurredAt()?->toDateString();

        return $title.($date ? ' · '.$date : '');
    }

    private static function privacyDefault(string $key): bool
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $preferences = $user->preferences;

        return $preferences instanceof UserPreference
            && (bool) data_get($preferences->privacy_preferences, $key, false);
    }
}
