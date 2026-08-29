<?php

namespace App\Filament\App\Resources;

use App\Actions\RetrySocialPost;
use App\Enums\SocialPostStatus;
use App\Enums\SocialProvider;
use App\Filament\App\Resources\SocialPostResource\Pages;
use App\Filament\App\Support\SocialAccountPresentation;
use App\Filament\App\Support\SocialDeliveryPresentation;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostFailure;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class SocialPostResource extends OwnedResource
{
    protected static ?string $model = SocialPost::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'Share deliberately';

    protected static ?int $navigationSort = 45;

    protected static ?string $navigationLabel = 'Social delivery history';

    protected static ?string $modelLabel = 'social delivery';

    protected static ?string $pluralModelLabel = 'social delivery history';

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->heading(__('Social delivery history'))
            ->description(__('Each row is one exact account destination. Provider confirmation, automatic retries, stopped deliveries, and simulation mode are reported separately.'))
            ->poll('15s')
            ->columns([
                TextColumn::make('publication.title')
                    ->label(__('Public version'))
                    ->limit(52)
                    ->searchable(),
                TextColumn::make('provider')
                    ->label(__('Provider'))
                    ->formatStateUsing(fn (SocialProvider $state): string => $state->label())
                    ->badge(),
                TextColumn::make('socialAccount.display_name')
                    ->label(__('Exact account'))
                    ->formatStateUsing(fn (mixed $state, SocialPost $record): string => $record->socialAccount instanceof SocialAccount
                        ? SocialAccountPresentation::label($record->socialAccount)
                        : __('Account removed'))
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('Delivery state'))
                    ->formatStateUsing(fn (mixed $state, SocialPost $record): string => SocialDeliveryPresentation::label($record))
                    ->description(fn (SocialPost $record): string => SocialDeliveryPresentation::description($record))
                    ->icon(fn (SocialPost $record): Heroicon => SocialDeliveryPresentation::icon($record))
                    ->color(fn (SocialPost $record): string => SocialDeliveryPresentation::color($record))
                    ->badge()
                    ->wrap(),
                TextColumn::make('attempt_count')
                    ->label(__('Attempts'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('error_message')
                    ->label(__('Last safe message'))
                    ->placeholder(__('No delivery error'))
                    ->limit(80)
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Delivery state'))
                    ->options(collect(SocialPostStatus::cases())
                        ->mapWithKeys(fn (SocialPostStatus $status): array => [$status->value => $status->label()])
                        ->all()),
                SelectFilter::make('provider')
                    ->options(collect(SocialProvider::cases())
                        ->mapWithKeys(fn (SocialProvider $provider): array => [$provider->value => $provider->label()])
                        ->all()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('openRemote')
                    ->label(__('Open on provider'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->visible(fn (SocialPost $record): bool => SocialDeliveryPresentation::safeRemoteUrl($record) !== null)
                    ->url(fn (SocialPost $record): string => SocialDeliveryPresentation::safeRemoteUrl($record) ?? '#')
                    ->openUrlInNewTab(),
                Action::make('retry')
                    ->label(__('Retry once'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->authorize('retry')
                    ->visible(fn (SocialPost $record): bool => self::canRetry($record))
                    ->requiresConfirmation()
                    ->modalHeading(__('Retry this exact delivery?'))
                    ->modalDescription(__('Memoria reuses the same local delivery and idempotency key. Some providers do not offer remote duplicate protection, so first check whether the post appeared despite the missing confirmation.'))
                    ->modalSubmitActionLabel(__('Retry exact delivery'))
                    ->action(fn (SocialPost $record) => self::retry($record)),
                Action::make('reconnect')
                    ->label(__('Reconnect exact account'))
                    ->icon(Heroicon::OutlinedLink)
                    ->color('warning')
                    ->visible(fn (SocialPost $record): bool => self::needsReconnect($record) && self::reconnectUrl($record) !== null)
                    ->url(fn (SocialPost $record): string => self::reconnectUrl($record) ?? '#'),
                Action::make('reconnectUnavailable')
                    ->label(__('Reconnect unavailable'))
                    ->icon(Heroicon::OutlinedInformationCircle)
                    ->color('gray')
                    ->disabled()
                    ->visible(fn (SocialPost $record): bool => self::needsReconnect($record) && self::reconnectUrl($record) === null)
                    ->tooltip(fn (SocialPost $record): string => self::reconnectUnavailableMessage($record)),
            ])
            ->emptyStateHeading(__('No social deliveries yet'))
            ->emptyStateDescription(__('Publishing to the website alone does not create a social delivery. Choose an exact connected account when publishing or scheduling.'))
            ->emptyStateIcon(Heroicon::OutlinedPaperAirplane);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSocialPosts::route('/'),
        ];
    }

    private static function canRetry(SocialPost $post): bool
    {
        $account = $post->socialAccount;
        if (! $account instanceof SocialAccount || ! $account->isConnected()) {
            return false;
        }

        if (in_array($post->status, [SocialPostStatus::Disconnected, SocialPostStatus::TokenExpired], true)) {
            return true;
        }

        if ($post->status !== SocialPostStatus::Failed) {
            return false;
        }

        return (bool) SocialPostFailure::query()
            ->whereBelongsTo($post)
            ->latest('occurred_at')
            ->value('is_retryable');
    }

    private static function needsReconnect(SocialPost $post): bool
    {
        $account = $post->socialAccount;

        return in_array($post->status, [SocialPostStatus::Disconnected, SocialPostStatus::TokenExpired], true)
            || ! $account instanceof SocialAccount
            || ! $account->isConnected();
    }

    private static function reconnectUrl(SocialPost $post): ?string
    {
        $account = $post->socialAccount;
        if (! $account instanceof SocialAccount) {
            return null;
        }

        $provider = self::provider($account);
        if (! app(SocialOnboardingReadiness::class)->for($provider)['available']) {
            return null;
        }

        return route('social.redirect', [
            'provider' => $provider->value,
            'reconnect' => $account->getKey(),
        ]);
    }

    private static function reconnectUnavailableMessage(SocialPost $post): string
    {
        $account = $post->socialAccount;

        return $account instanceof SocialAccount
            ? app(SocialOnboardingReadiness::class)->for(self::provider($account))['message']
            : __('The original social account is no longer available.');
    }

    private static function provider(SocialAccount $account): SocialProvider
    {
        return $account->provider;
    }

    private static function retry(SocialPost $post): void
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);
        app(InteractiveActionRateLimiter::class)->socialAction($user);

        try {
            app(RetrySocialPost::class)->handle($post, $user);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            Notification::make()
                ->danger()
                ->title(__('Delivery was not retried'))
                ->body(is_string($message) ? $message : __('Review the account and publication before trying again.'))
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('Delivery queued again'))
            ->body(__('The same local delivery record and idempotency key will be reused.'))
            ->send();
    }
}
