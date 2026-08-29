<?php

namespace App\Filament\Admin\Resources;

use App\Enums\ReportStatus;
use App\Filament\Admin\Resources\ReportResource\Pages;
use App\Models\Report;
use App\Services\EligibleReportAssignees;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Public content';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Public content report'))
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('Reason'))
                            ->disabled()
                            ->dehydrated(false),
                        Textarea::make('details')
                            ->label(__('Reporter details'))
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(__('Moderation outcome'))
                    ->schema([
                        Select::make('status')
                            ->options(self::statusOptions())
                            ->required(),
                        Select::make('assigned_to_user_id')
                            ->label(__('Assigned moderator'))
                            ->relationship(
                                name: 'assignee',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => app(EligibleReportAssignees::class)->constrain($query),
                            )
                            ->searchable(['name', 'email'])
                            ->preload()
                            ->helperText(__('Only active moderators and administrators can receive reports.')),
                        Textarea::make('moderation_notes')
                            ->label(__('Internal notes'))
                            ->maxLength(5000)
                            ->columnSpanFull(),
                        Textarea::make('resolution')
                            ->label(__('Resolution'))
                            ->maxLength(5000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reason')
                    ->label(__('Reason'))
                    ->formatStateUsing(fn (string $state): string => Str::headline($state))
                    ->badge()
                    ->searchable(),
                TextColumn::make('publication.title')
                    ->label(__('Public story'))
                    ->placeholder(__('Comment report'))
                    ->limit(45),
                TextColumn::make('comment.body')
                    ->label(__('Reported comment'))
                    ->placeholder(__('Publication report'))
                    ->limit(65)
                    ->wrap(),
                TextColumn::make('status')
                    ->formatStateUsing(fn (ReportStatus|string $state): string => $state instanceof ReportStatus ? $state->label() : Str::headline($state))
                    ->badge()
                    ->color(fn (ReportStatus|string $state): string => match ($state instanceof ReportStatus ? $state : ReportStatus::tryFrom($state)) {
                        ReportStatus::Open => 'danger',
                        ReportStatus::InReview => 'warning',
                        ReportStatus::Resolved => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('assignee.name')
                    ->label(__('Assigned to'))
                    ->placeholder(__('Unassigned')),
                TextColumn::make('created_at')
                    ->label(__('Reported'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(self::statusOptions()),
            ])
            ->stackedOnMobile()
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make()->label(__('Review')),
            ])
            ->emptyStateHeading(__('No public-content reports'))
            ->emptyStateDescription(__('New reports will appear here for moderator review.'))
            ->emptyStateIcon(Heroicon::OutlinedFlag);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReports::route('/'),
            'edit' => Pages\EditReport::route('/{record}/edit'),
        ];
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return collect(ReportStatus::cases())
            ->mapWithKeys(fn (ReportStatus $status): array => [$status->value => $status->label()])
            ->all();
    }
}
