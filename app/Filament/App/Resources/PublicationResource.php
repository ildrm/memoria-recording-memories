<?php

namespace App\Filament\App\Resources;

use App\Actions\ArchivePublication;
use App\Actions\RestoreArchivedPublication;
use App\Enums\PublicationStatus;
use App\Enums\PublicationTargetStatus;
use App\Enums\PublicationTargetType;
use App\Filament\App\Resources\PublicationResource\Pages;
use App\Filament\App\Resources\PublicationResource\RelationManagers\MediaRelationManager;
use App\Filament\App\Resources\PublicationResource\RelationManagers\SocialPostsRelationManager;
use App\Models\Publication;
use App\Models\PublicationTarget;
use App\Models\User;
use App\Models\UserPreference;
use App\Services\InteractiveActionRateLimiter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class PublicationResource extends OwnedResource
{
    protected static ?string $model = Publication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Share deliberately';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $navigationLabel = 'Publications';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(12)
            ->components([
                Section::make(__('Public version'))
                    ->description(fn (?Publication $record): string => match ($record?->status) {
                        PublicationStatus::Published => __('Live now. Saving any story or audience change immediately withdraws this version and clears its review. You must review, preview, and publish again.'),
                        PublicationStatus::Scheduled => __('Scheduled now. Saving any story or audience change cancels delivery and clears its review. You must review, preview, and schedule again.'),
                        default => __('This is an independent copy. Editing it never changes the private source memory.'),
                    })
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->schema([
                        TextInput::make('title')
                            ->label(__('Public title'))
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set): mixed => $set('privacy_reviewed_at', null)),
                        TextInput::make('slug')
                            ->label(__('Public address'))
                            ->required()
                            ->maxLength(180)
                            ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                            ->helperText(__('Use lowercase letters, numbers, and single hyphens.'))
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule->where(
                                    'user_id',
                                    Filament::auth()->id(),
                                ),
                            ),
                        Textarea::make('excerpt')
                            ->label(__('Public summary'))
                            ->rows(3)
                            ->maxLength(500)
                            ->columnSpanFull()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set): mixed => $set('privacy_reviewed_at', null)),
                        RichEditor::make('body')
                            ->label(__('Public story'))
                            ->required()
                            ->maxLength((int) config('memoria.rich_text.maximum_characters', 125000))
                            ->toolbarButtons([
                                ['bold', 'italic', 'underline', 'link'],
                                ['h2', 'h3'],
                                ['blockquote', 'bulletList', 'orderedList'],
                                ['undo', 'redo'],
                            ])
                            ->fileAttachments(false)
                            ->columnSpanFull()
                            ->live(debounce: 1600)
                            ->afterStateUpdated(fn (Set $set): mixed => $set('privacy_reviewed_at', null)),
                        TagsInput::make('topics')
                            ->label(__('Public topics'))
                            ->helperText(__('Optional public labels only—never copied from private diary tags. Up to 10 topics, 50 characters each. Topics are included in the exact privacy review.'))
                            ->tagPrefix('#')
                            ->splitKeys(['Tab', ','])
                            ->trim()
                            ->reorderable()
                            ->rules(['array', 'max:10'])
                            ->nestedRecursiveRules(['string', 'max:50'])
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set): mixed => $set('privacy_reviewed_at', null))
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpan(['default' => 12, 'xl' => 8]),
                Section::make(__('Audience & discovery'))
                    ->description(__('These settings affect only the public version. Nothing here can publish by itself.'))
                    ->schema([
                        Toggle::make('comments_enabled')
                            ->label(__('Allow comments')),
                        Toggle::make('reactions_enabled')
                            ->label(__('Allow reactions')),
                        Toggle::make('search_engine_indexing')
                            ->label(__('Allow search engine indexing'))
                            ->default(fn (): bool => self::privacyDefault('search_engine_indexing_default'))
                            ->helperText(__('Keep this off if you do not want public search engines invited to index the story.')),
                        TextInput::make('privacy_reviewed_at')
                            ->label(__('Privacy review'))
                            ->formatStateUsing(fn (mixed $state): string => filled($state) ? __('Completed') : __('Required before publishing'))
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columnSpan(['default' => 12, 'xl' => 4]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('Publication'))
                    ->description(fn (Publication $record): string => $record->sourceEntry ? __('Separate version of a private memory') : __('Independent public story'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Visibility'))
                    ->formatStateUsing(function (PublicationStatus|string $state, Publication $record): string {
                        $status = $state instanceof PublicationStatus ? $state : PublicationStatus::tryFrom($state);

                        if ($status === PublicationStatus::Published && ! self::hasWebsiteTarget($record, [PublicationTargetStatus::Published])) {
                            return __('Published · social only');
                        }

                        if ($status === PublicationStatus::Scheduled && ! self::hasWebsiteTarget($record, [PublicationTargetStatus::Scheduled])) {
                            return __('Scheduled · social only');
                        }

                        return $status?->label() ?? Str::headline((string) $state);
                    })
                    ->description(function (Publication $record): ?string {
                        if ($record->status === PublicationStatus::Published) {
                            return self::hasWebsiteTarget($record, [PublicationTargetStatus::Published])
                                ? __('Visible on your public profile')
                                : __('No public website page');
                        }

                        if ($record->status === PublicationStatus::Scheduled) {
                            return self::hasWebsiteTarget($record, [PublicationTargetStatus::Scheduled])
                                ? __('Website delivery included')
                                : __('Social delivery only');
                        }

                        return null;
                    })
                    ->icon(fn (PublicationStatus|string $state): Heroicon => match ($state instanceof PublicationStatus ? $state : PublicationStatus::tryFrom($state)) {
                        PublicationStatus::Published => Heroicon::OutlinedGlobeAlt,
                        PublicationStatus::Scheduled => Heroicon::OutlinedClock,
                        PublicationStatus::Unpublished => Heroicon::OutlinedEyeSlash,
                        PublicationStatus::Archived => Heroicon::OutlinedArchiveBox,
                        default => Heroicon::OutlinedDocument,
                    })
                    ->badge()
                    ->color(fn (PublicationStatus|string $state): string => match ($state instanceof PublicationStatus ? $state : PublicationStatus::tryFrom($state)) {
                        PublicationStatus::Published => 'success',
                        PublicationStatus::Scheduled => 'warning',
                        PublicationStatus::Unpublished => 'gray',
                        PublicationStatus::Archived => 'gray',
                        default => 'info',
                    })
                    ->sortable(),
                TextColumn::make('review_gate')
                    ->label(__('Review gate'))
                    ->state(fn (Publication $record): string => $record->privacy_reviewed_at ? 'recorded' : 'required')
                    ->formatStateUsing(fn (string $state): string => $state === 'recorded' ? __('Recorded') : __('Required'))
                    ->icon(fn (string $state): Heroicon => $state === 'recorded' ? Heroicon::OutlinedShieldCheck : Heroicon::OutlinedExclamationTriangle)
                    ->color(fn (string $state): string => $state === 'recorded' ? 'success' : 'warning')
                    ->badge(),
                TextColumn::make('scheduled_at')
                    ->label(__('Scheduled'))
                    ->dateTime()
                    ->placeholder(__('Not scheduled'))
                    ->toggleable(),
                TextColumn::make('published_at')
                    ->label(__('Published'))
                    ->dateTime()
                    ->placeholder(__('Not published'))
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('Last edited'))
                    ->since()
                    ->dateTimeTooltip()
                    ->toggleable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(self::statusOptions()),
            ])
            ->recordActions([
                EditAction::make()->label(__('Edit public version')),
                Action::make('archive')
                    ->label(__('Archive'))
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->color('gray')
                    ->authorize('update')
                    ->visible(fn (Publication $record): bool => $record->status !== PublicationStatus::Archived)
                    ->requiresConfirmation()
                    ->modalDescription(__('This removes any local public or scheduled delivery and preserves the public version in your archive. Memoria will request asynchronous social-post removal where supported, but provider copies may remain if authorization or removal fails.'))
                    ->action(function (Publication $record): void {
                        $user = Filament::auth()->user();
                        abort_unless($user instanceof User, 403);
                        app(InteractiveActionRateLimiter::class)->publicationAction($user);
                        app(ArchivePublication::class)->handle($record, $user);
                    }),
                Action::make('restore')
                    ->label(__('Restore draft'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->authorize('update')
                    ->visible(fn (Publication $record): bool => $record->status === PublicationStatus::Archived)
                    ->action(function (Publication $record): void {
                        $user = Filament::auth()->user();
                        abort_unless($user instanceof User, 403);
                        app(InteractiveActionRateLimiter::class)->publicationAction($user);
                        app(RestoreArchivedPublication::class)->handle($record, $user);
                    }),
            ])
            ->emptyStateHeading(__('Everything you have written is still private'))
            ->emptyStateDescription(__('Open a private memory and choose Create public version when you are ready.'))
            ->emptyStateIcon(Heroicon::OutlinedGlobeAlt);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPublications::route('/'),
            'create' => Pages\CreatePublication::route('/create'),
            'edit' => Pages\EditPublication::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            MediaRelationManager::class,
            SocialPostsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'sourceEntry:id',
            'targets:id,publication_id,type,status',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return collect(PublicationStatus::cases())
            ->mapWithKeys(fn (PublicationStatus $status): array => [$status->value => $status->label()])
            ->all();
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

    /** @param array<int, PublicationTargetStatus> $statuses */
    private static function hasWebsiteTarget(Publication $publication, array $statuses): bool
    {
        if ($publication->relationLoaded('targets')) {
            return $publication->targets->contains(
                fn (PublicationTarget $target): bool => $target->type === PublicationTargetType::Website
                    && in_array($target->status, $statuses, true),
            );
        }

        return $publication->targets()
            ->where('type', PublicationTargetType::Website)
            ->whereIn('status', $statuses)
            ->exists();
    }
}
