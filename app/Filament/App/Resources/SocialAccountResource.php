<?php

namespace App\Filament\App\Resources;

use App\Actions\DisconnectSocialAccount;
use App\Enums\SocialProvider;
use App\Filament\App\Resources\SocialAccountResource\Pages;
use App\Filament\App\Support\SocialAccountPresentation;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\InteractiveActionRateLimiter;
use App\Services\SocialOnboardingReadiness;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class SocialAccountResource extends OwnedResource
{
    protected static ?string $model = SocialAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static string|UnitEnum|null $navigationGroup = 'Account';

    protected static ?int $navigationSort = 70;

    protected static ?string $navigationLabel = 'Connected accounts';

    protected static ?string $modelLabel = 'connected account';

    protected static ?string $pluralModelLabel = 'connected accounts';

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->heading(__('Connected accounts'))
            ->description(__('Connections use provider OAuth only; Memoria never asks for a provider password. Account readiness and every delivery result are reported separately.'))
            ->columns([
                TextColumn::make('provider')
                    ->label(__('Provider'))
                    ->formatStateUsing(fn (SocialProvider|string $state): string => $state instanceof SocialProvider ? $state->label() : Str::headline($state))
                    ->icon(Heroicon::OutlinedGlobeAlt)
                    ->badge(),
                TextColumn::make('display_name')
                    ->label(__('Account'))
                    ->description(fn (SocialAccount $record): ?string => $record->username ? '@'.ltrim($record->username, '@') : null)
                    ->placeholder(__('Connected account')),
                TextColumn::make('connection_state')
                    ->label(__('Connection'))
                    ->state(fn (SocialAccount $record): string => SocialAccountPresentation::state($record)['label'])
                    ->description(fn (SocialAccount $record): string => SocialAccountPresentation::state($record)['description'])
                    ->icon(fn (SocialAccount $record): Heroicon => SocialAccountPresentation::state($record)['icon'])
                    ->color(fn (SocialAccount $record): string => SocialAccountPresentation::state($record)['color'])
                    ->badge(),
                TextColumn::make('social_posts_count')
                    ->label(__('Deliveries'))
                    ->counts('socialPosts')
                    ->numeric(),
                TextColumn::make('connected_at')
                    ->label(__('Connected'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('token_expires_at')
                    ->label(__('Connection expires'))
                    ->dateTime()
                    ->placeholder(__('Provider did not specify'))
                    ->toggleable(),
            ])
            ->defaultSort('connected_at', 'desc')
            ->recordActions([
                Action::make('reconnect')
                    ->label(__('Reconnect exact account'))
                    ->icon(Heroicon::OutlinedLink)
                    ->color('warning')
                    ->visible(fn (SocialAccount $record): bool => ! $record->isConnected()
                        && app(SocialOnboardingReadiness::class)->for($record->provider)['available'])
                    ->url(fn (SocialAccount $record): string => route('social.redirect', [
                        'provider' => $record->provider->value,
                        'reconnect' => $record->getKey(),
                    ])),
                Action::make('reconnectUnavailable')
                    ->label(__('Reconnect unavailable'))
                    ->icon(Heroicon::OutlinedInformationCircle)
                    ->color('gray')
                    ->disabled()
                    ->visible(fn (SocialAccount $record): bool => ! $record->isConnected()
                        && ! app(SocialOnboardingReadiness::class)->for($record->provider)['available'])
                    ->tooltip(fn (SocialAccount $record): string => app(SocialOnboardingReadiness::class)->for($record->provider)['message']),
                Action::make('disconnect')
                    ->label(__('Disconnect'))
                    ->icon(Heroicon::OutlinedLinkSlash)
                    ->color('danger')
                    ->authorize('delete')
                    ->visible(fn (SocialAccount $record): bool => $record->revoked_at === null)
                    ->requiresConfirmation()
                    ->modalDescription(__('Scheduled and pending deliveries will be cancelled. Before credentials are cleared, Memoria will queue social-post removal where supported; provider copies may remain if authorization or removal fails.'))
                    ->action(function (SocialAccount $record): void {
                        $user = Filament::auth()->user();
                        abort_unless($user instanceof User, 403);
                        app(InteractiveActionRateLimiter::class)->socialAction($user);
                        app(DisconnectSocialAccount::class)->handle($record, $user);

                        Notification::make()
                            ->success()
                            ->title(__('Account disconnected'))
                            ->body(__('Pending deliveries stopped and supported provider removals were queued. Provider copies may remain if removal fails.'))
                            ->send();
                    }),
            ])
            ->emptyStateHeading(__('No connected accounts'))
            ->emptyStateDescription(__('Connect a social account when you are ready to share beyond your public journal.'))
            ->emptyStateIcon(Heroicon::OutlinedShare);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSocialAccounts::route('/'),
        ];
    }
}
