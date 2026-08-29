<?php

namespace App\Filament\App\Resources\EntryResource\RelationManagers;

use App\Actions\RevokeEntryShare;
use App\Models\Entry;
use App\Models\EntryShare;
use App\Models\User;
use App\Services\InteractiveActionRateLimiter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class EntrySharesRelationManager extends RelationManager
{
    protected static string $relationship = 'shares';

    protected static bool $isLazy = false;

    protected static ?string $title = 'Registered access';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedUsers;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && $ownerRecord instanceof Entry
            && $ownerRecord->isOwnedBy($user)
            && $user->can('share', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Registered view-only access'))
            ->description(__('Members listed here can read this one memory after signing in. They cannot edit, publish, reshare, or search it.'))
            ->columns([
                TextColumn::make('recipient.name')
                    ->label(__('Member'))
                    ->description(function (EntryShare $record): string {
                        $recipient = $record->recipient;

                        return $recipient instanceof User ? $recipient->email : '';
                    }),
                TextColumn::make('permission')
                    ->label(__('Access'))
                    ->formatStateUsing(fn (): string => __('View only'))
                    ->badge()
                    ->color('gray'),
                IconColumn::make('include_attachments')
                    ->label(__('Attachments'))
                    ->boolean(),
                TextColumn::make('expires_at')
                    ->label(__('Expires'))
                    ->dateTime()
                    ->placeholder(__('No expiry')),
                TextColumn::make('revoked_at')
                    ->label(__('Status'))
                    ->formatStateUsing(fn (mixed $state): string => filled($state) ? __('Revoked') : __('Active'))
                    ->badge()
                    ->color(fn (mixed $state): string => filled($state) ? 'gray' : 'success'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('revoke')
                    ->label(__('Revoke'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->authorize('delete')
                    ->visible(fn (EntryShare $record): bool => $record->revoked_at === null)
                    ->requiresConfirmation()
                    ->modalDescription(__('This member will immediately lose access to the memory and any included attachments.'))
                    ->action(function (EntryShare $record): void {
                        $user = Filament::auth()->user();
                        abort_unless($user instanceof User, 403);
                        app(InteractiveActionRateLimiter::class)->shareAction($user);

                        app(RevokeEntryShare::class)->handle($record, $user);
                    }),
            ])
            ->emptyStateHeading(__('No registered access'))
            ->emptyStateDescription(__('Use Share view-only at the top of this page to grant access to a registered member.'))
            ->emptyStateIcon(Heroicon::OutlinedUsers);
    }
}
