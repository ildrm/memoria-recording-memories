<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\AuditEventResource\Pages;
use App\Models\AuditEvent;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

class AuditEventResource extends Resource
{
    protected static ?string $model = AuditEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Governance';

    protected static ?int $navigationSort = 60;

    protected static ?string $navigationLabel = 'Audit metadata';

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->hasPermissionTo('audit-events.view');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('event')
                    ->label(__('Event'))
                    ->formatStateUsing(fn (string $state): string => Str::headline($state))
                    ->badge()
                    ->searchable(),
                TextColumn::make('actor.email')
                    ->label(__('Actor'))
                    ->placeholder(__('System'))
                    ->searchable(),
                TextColumn::make('auditable_type')
                    ->label(__('Subject type'))
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? class_basename($state) : __('None')),
                TextColumn::make('auditable_id')
                    ->label(__('Subject ID'))
                    ->placeholder(__('—')),
                TextColumn::make('occurred_at')
                    ->label(__('Occurred'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->emptyStateHeading(__('No audit events'))
            ->emptyStateDescription(__('Security and state-change metadata will appear here. Private entry bodies are never shown.'))
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentList);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('actor:id,email');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditEvents::route('/'),
        ];
    }
}
