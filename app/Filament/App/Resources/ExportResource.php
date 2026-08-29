<?php

namespace App\Filament\App\Resources;

use App\Enums\ExportStatus;
use App\Filament\App\Resources\ExportResource\Pages;
use App\Models\Export;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use UnitEnum;

class ExportResource extends OwnedResource
{
    protected static ?string $model = Export::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Account';

    protected static ?int $navigationSort = 80;

    protected static ?string $navigationLabel = 'Data & export';

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('requested_at')
                    ->label(__('Requested'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('State'))
                    ->formatStateUsing(fn (ExportStatus|string $state): string => $state instanceof ExportStatus ? $state->label() : Str::headline($state))
                    ->icon(fn (ExportStatus|string $state): Heroicon => match ($state instanceof ExportStatus ? $state : ExportStatus::tryFrom($state)) {
                        ExportStatus::Ready => Heroicon::OutlinedCheckCircle,
                        ExportStatus::Failed => Heroicon::OutlinedExclamationTriangle,
                        ExportStatus::Expired => Heroicon::OutlinedClock,
                        default => Heroicon::OutlinedArrowPath,
                    })
                    ->badge(),
                TextColumn::make('filename')
                    ->label(__('Archive'))
                    ->placeholder(__('Preparing secure archive')),
                TextColumn::make('size_bytes')
                    ->label(__('Size'))
                    ->formatStateUsing(fn (?int $state): string => $state ? Number::fileSize($state) : __('—')),
                TextColumn::make('expires_at')
                    ->label(__('Download expires'))
                    ->dateTime()
                    ->placeholder(__('Not ready')),
            ])
            ->defaultSort('requested_at', 'desc')
            ->recordActions([
                Action::make('download')
                    ->label(__('Secure download'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn (Export $record): bool => Route::has('exports.download') && $record->isDownloadable())
                    ->url(fn (Export $record): string => route('exports.download', $record)),
            ])
            ->emptyStateHeading(__('No exports requested'))
            ->emptyStateDescription(__('Request a portable archive of your memories and media. Downloads expire automatically.'))
            ->emptyStateIcon(Heroicon::OutlinedArrowDownTray);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExports::route('/'),
        ];
    }
}
