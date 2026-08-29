<?php

namespace App\Providers\Filament;

use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Resources\EntryResource;
use App\Http\Middleware\ApplyUserPreferences;
use App\Http\Middleware\PrivateResponse;
use App\Http\Middleware\SecurityHeaders;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app')
            ->path('app')
            ->login()
            ->registration()
            ->passwordReset()
            ->emailVerification()
            ->emailChangeVerification()
            ->profile(isSimple: false)
            ->databaseNotifications()
            ->multiFactorAuthentication(
                AppAuthentication::make()
                    ->brandName(config('app.name', 'Memoria'))
                    ->recoverable(),
            )
            ->brandName(config('app.name', 'Memoria'))
            ->brandLogo(fn () => view('filament.app.logo'))
            ->darkModeBrandLogo(fn () => view('filament.app.logo'))
            ->brandLogoHeight('2rem')
            ->favicon('/favicon.svg')
            ->colors([
                'primary' => Color::Emerald,
                'gray' => Color::Stone,
            ])
            ->viteTheme('resources/css/filament/app/theme.css')
            ->maxContentWidth(Width::Full)
            ->sidebarCollapsibleOnDesktop()
            ->collapsibleNavigationGroups(false)
            ->unsavedChangesAlerts()
            ->spa(hasPrefetching: true)
            ->strictAuthorization()
            ->navigationGroups([
                'Write & remember',
                'Organize',
                'Share deliberately',
                'Account',
            ])
            ->navigationItems([
                NavigationItem::make(__('Write a memory'))
                    ->group('Write & remember')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->activeIcon(Heroicon::PencilSquare)
                    ->sort(1)
                    ->url(fn (): string => EntryResource::getUrl('create'))
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.app.resources.entries.create')),
            ])
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\Filament\App\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\Filament\App\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\Filament\App\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                SecurityHeaders::class,
                PrivateResponse::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->authMiddleware([
                ApplyUserPreferences::class,
            ], isPersistent: true);
    }
}
