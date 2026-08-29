<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\SecurityActivityResource\Pages;
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
use UnitEnum;

class SecurityActivityResource extends Resource
{
    /** @var array<int, string> */
    private const AUTHENTICATION_EVENTS = [
        'authentication.login',
        'authentication.logout',
        'authentication.failed',
    ];

    /** @var array<int, string> */
    private const ACCOUNT_EVENTS = [
        'account.password_changed',
        'account.email_changed',
        'account.email_verified',
        'account.totp_enabled',
        'account.totp_disabled',
        'account.totp_recovery_codes_regenerated',
        'account.other_sessions_logout_requested',
        'account.other_sessions_logged_out',
    ];

    protected static ?string $model = AuditEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Account';

    protected static ?int $navigationSort = 55;

    protected static ?string $navigationLabel = 'Security activity';

    protected static ?string $modelLabel = 'security event';

    protected static ?string $pluralModelLabel = 'security activity';

    protected static ?string $slug = 'security-activity';

    public static function canViewAny(): bool
    {
        return Filament::auth()->user() instanceof User;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->heading(__('Security activity'))
            ->description(__('A bounded history of sign-ins and sensitive account-security changes. Network addresses, browser fingerprints, recovery codes, and audit metadata are never displayed.'))
            ->columns([
                TextColumn::make('event')
                    ->label(__('Activity'))
                    ->formatStateUsing(fn (string $state): string => self::eventLabel($state))
                    ->description(fn (AuditEvent $record): string => self::eventDescription($record->event))
                    ->icon(fn (AuditEvent $record): Heroicon => self::eventIcon($record->event))
                    ->color(fn (AuditEvent $record): string => self::eventColor($record->event))
                    ->badge()
                    ->wrap(),
                TextColumn::make('occurred_at')
                    ->label(__('When'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->paginated([10, 25])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading(__('No security activity yet'))
            ->emptyStateDescription(__('Recognized sign-ins and security changes for this account will appear here.'))
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        $userMorphClass = $user->getMorphClass();

        return parent::getEloquentQuery()
            ->where(function (Builder $query) use ($user, $userMorphClass): void {
                $query->where(function (Builder $authenticationQuery) use ($user): void {
                    $authenticationQuery
                        ->where('actor_user_id', $user->getKey())
                        ->whereIn('event', self::AUTHENTICATION_EVENTS);
                })->orWhere(function (Builder $accountQuery) use ($user, $userMorphClass): void {
                    $accountQuery
                        ->where('auditable_type', $userMorphClass)
                        ->where('auditable_id', $user->getKey())
                        ->whereIn('event', self::ACCOUNT_EVENTS);
                });
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSecurityActivities::route('/'),
        ];
    }

    private static function eventLabel(string $event): string
    {
        return match ($event) {
            'authentication.login' => __('Signed in'),
            'authentication.logout' => __('Signed out'),
            'authentication.failed' => __('Sign-in attempt failed'),
            'account.password_changed' => __('Password changed'),
            'account.email_changed' => __('Email address changed'),
            'account.email_verified' => __('Email address verified'),
            'account.totp_enabled' => __('Two-factor authentication enabled'),
            'account.totp_disabled' => __('Two-factor authentication disabled'),
            'account.totp_recovery_codes_regenerated' => __('Recovery codes replaced'),
            'account.other_sessions_logout_requested' => __('Other-session sign-out requested'),
            'account.other_sessions_logged_out' => __('Other sessions signed out'),
            default => __('Security activity'),
        };
    }

    private static function eventDescription(string $event): string
    {
        return match ($event) {
            'authentication.login' => __('A sign-in to this account completed.'),
            'authentication.logout' => __('A signed-in session ended.'),
            'authentication.failed' => __('A sign-in attempt matched this account but did not complete.'),
            'account.password_changed' => __('The password protecting this account was replaced.'),
            'account.email_changed' => __('The account email address was replaced.'),
            'account.email_verified' => __('The account email address was verified.'),
            'account.totp_enabled' => __('Authenticator-based two-factor protection was turned on.'),
            'account.totp_disabled' => __('Authenticator-based two-factor protection was turned off.'),
            'account.totp_recovery_codes_regenerated' => __('Previous recovery codes were invalidated and replaced.'),
            'account.other_sessions_logout_requested' => __('A request to end other browser sessions was recorded.'),
            'account.other_sessions_logged_out' => __('Other database-backed browser sessions were ended.'),
            default => __('A security-sensitive account event was recorded.'),
        };
    }

    private static function eventIcon(string $event): Heroicon
    {
        return match ($event) {
            'authentication.login' => Heroicon::OutlinedArrowRightEndOnRectangle,
            'authentication.logout' => Heroicon::OutlinedArrowLeftStartOnRectangle,
            'authentication.failed' => Heroicon::OutlinedExclamationTriangle,
            'account.password_changed' => Heroicon::OutlinedKey,
            'account.email_changed', 'account.email_verified' => Heroicon::OutlinedEnvelope,
            'account.totp_enabled', 'account.totp_disabled', 'account.totp_recovery_codes_regenerated' => Heroicon::OutlinedDevicePhoneMobile,
            default => Heroicon::OutlinedComputerDesktop,
        };
    }

    private static function eventColor(string $event): string
    {
        return match ($event) {
            'authentication.failed', 'account.totp_disabled' => 'warning',
            'account.totp_enabled', 'account.email_verified', 'account.other_sessions_logged_out' => 'success',
            default => 'gray',
        };
    }
}
