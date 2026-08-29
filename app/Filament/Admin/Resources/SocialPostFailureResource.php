<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\SocialPostFailureResource\Pages;
use App\Models\SocialPostFailure;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

class SocialPostFailureResource extends Resource
{
    protected static ?string $model = SocialPostFailure::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Social delivery failures';

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->hasPermissionTo('social-failures.view');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('socialPost.provider')
                    ->label(__('Provider'))
                    ->formatStateUsing(fn (mixed $state): string => Str::headline($state instanceof BackedEnum ? $state->value : (string) $state))
                    ->badge(),
                TextColumn::make('socialPost.status')
                    ->label(__('Delivery state'))
                    ->formatStateUsing(fn (mixed $state): string => Str::headline($state instanceof BackedEnum ? $state->value : (string) $state))
                    ->badge()
                    ->color('danger'),
                TextColumn::make('attempt')
                    ->label(__('Attempt'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('error_code')
                    ->label(__('Error code'))
                    ->placeholder(__('Unclassified'))
                    ->searchable(),
                TextColumn::make('message')
                    ->label(__('Provider response'))
                    ->limit(90)
                    ->wrap(),
                IconColumn::make('is_retryable')
                    ->label(__('Retryable'))
                    ->boolean(),
                TextColumn::make('occurred_at')
                    ->label(__('Occurred'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_retryable')->label(__('Retryable')),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->emptyStateHeading(__('No social delivery failures'))
            ->emptyStateDescription(__('Provider errors will appear here without exposing account credentials or private memory content.'))
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('socialPost:id,provider,status');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSocialPostFailures::route('/'),
        ];
    }
}
