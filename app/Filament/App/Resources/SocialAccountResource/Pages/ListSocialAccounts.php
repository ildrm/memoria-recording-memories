<?php

namespace App\Filament\App\Resources\SocialAccountResource\Pages;

use App\Enums\SocialProvider;
use App\Filament\App\Resources\SocialAccountResource;
use App\Filament\App\Resources\SocialPostResource;
use App\Services\SocialOnboardingReadiness;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Route;

class ListSocialAccounts extends ListRecords
{
    protected static string $resource = SocialAccountResource::class;

    protected function getHeaderActions(): array
    {
        $actions = [
            Action::make('deliveryHistory')
                ->label(__('Delivery history'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->url(SocialPostResource::getUrl()),
        ];

        if (! Route::has('social.redirect')) {
            return $actions;
        }

        return collect(SocialProvider::cases())
            ->map(function (SocialProvider $provider): Action {
                $state = app(SocialOnboardingReadiness::class)->for($provider);

                return Action::make('connect-'.$provider->value)
                    ->label(__('Connect :provider', ['provider' => $provider->label()]))
                    ->icon(Heroicon::OutlinedPlus)
                    ->disabled(! $state['available'])
                    ->tooltip($state['message'])
                    ->url($state['available'] ? route('social.redirect', ['provider' => $provider->value]) : null);
            })
            ->prepend($actions[0])
            ->all();
    }

    public function getSubheading(): ?string
    {
        return match (config('memoria.social.driver')) {
            'real' => __('External delivery is enabled. Connections still require provider-approved applications, scopes, and valid tokens.'),
            'fake' => __('Simulation mode is active. Delivery states can be tested, but no external posts are created.'),
            default => __('External delivery is unavailable until an operator explicitly configures the real social driver.'),
        };
    }
}
