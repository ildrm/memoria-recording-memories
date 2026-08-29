<?php

namespace App\Filament\Admin\Resources;

use App\Actions\ModeratePublicPublication;
use App\Filament\Admin\Resources\PublicationResource\Pages;
use App\Models\Publication;
use App\Models\User;
use App\Models\UserProfile;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use UnitEnum;

class PublicationResource extends Resource
{
    protected static ?string $model = Publication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Public content';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Public publications';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('Public story'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('owner.name')
                    ->label(__('Publisher'))
                    ->description(fn (Publication $record): ?string => self::username($record) ? '@'.self::username($record) : null)
                    ->sortable(),
                TextColumn::make('published_at')
                    ->label(__('Published'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('comments_count')
                    ->label(__('Comments'))
                    ->counts('comments'),
                TextColumn::make('reports_count')
                    ->label(__('Reports'))
                    ->counts('reports')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                IconColumn::make('search_engine_indexing')
                    ->label(__('Indexable'))
                    ->boolean(),
            ])
            ->defaultSort('published_at', 'desc')
            ->recordActions([
                Action::make('openPublicPage')
                    ->label(__('Open public page'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->visible(fn (Publication $record): bool => Route::has('publications.show') && filled(self::username($record)))
                    ->url(fn (Publication $record): string => route('publications.show', [
                        'username' => self::username($record),
                        'publicationSlug' => $record->slug,
                    ]))
                    ->openUrlInNewTab(),
                Action::make('removeFromPublicView')
                    ->label(__('Remove from public view'))
                    ->icon(Heroicon::OutlinedEyeSlash)
                    ->color('danger')
                    ->authorize('moderate')
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('Moderation reason'))
                            ->helperText(__('Internal audit context only. Do not copy private material here.'))
                            ->rows(3)
                            ->maxLength(500),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading(__('Remove this publication from public view?'))
                    ->modalDescription(__('The public page will stop working immediately. Memoria will request asynchronous social-post removal where supported, but provider copies may remain if authorization or removal fails.'))
                    ->modalSubmitActionLabel(__('Remove public access'))
                    ->action(function (Publication $record, array $data): void {
                        $moderator = Filament::auth()->user();
                        abort_unless($moderator instanceof User, 403);

                        app(ModeratePublicPublication::class)->handle(
                            publication: $record,
                            moderator: $moderator,
                            reason: filled($data['reason'] ?? null) ? (string) $data['reason'] : null,
                        );

                        Notification::make()
                            ->success()
                            ->title(__('Publication removed from public view'))
                            ->body(__('The action was recorded. Asynchronous provider removal was requested where supported, but provider copies may remain.'))
                            ->send();
                    }),
            ])
            ->emptyStateHeading(__('No public publications'))
            ->emptyStateDescription(__('Private memories never appear in this panel.'))
            ->emptyStateIcon(Heroicon::OutlinedGlobeAlt);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('id', Publication::query()->websitePublished()->select('id'))
            ->with(['owner.profile']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPublications::route('/'),
        ];
    }

    private static function username(Publication $publication): ?string
    {
        $owner = $publication->owner;

        if (! $owner instanceof User) {
            return null;
        }

        $profile = $owner->profile;

        return $profile instanceof UserProfile ? $profile->username : null;
    }
}
