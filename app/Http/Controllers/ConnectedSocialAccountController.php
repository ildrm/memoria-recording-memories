<?php

namespace App\Http\Controllers;

use App\Actions\ConnectSocialAccount;
use App\Actions\DisconnectSocialAccount;
use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SocialOnboardingReadiness;
use App\Services\Social\Exceptions\SanitizedSocialIntegrationException;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Throwable;

class ConnectedSocialAccountController extends Controller
{
    public function redirect(
        Request $request,
        SocialProvider $provider,
        SocialOnboardingReadiness $readiness,
    ): RedirectResponse {
        Gate::authorize('create', SocialAccount::class);
        $state = $readiness->for($provider);
        abort_unless($state['available'], 422, $state['message']);

        $reconnectAccountId = $request->integer('reconnect');
        $sessionKey = $this->reconnectSessionKey($provider);

        if ($reconnectAccountId > 0) {
            $owner = $request->user();
            abort_unless($owner instanceof User, 403);
            $account = SocialAccount::query()
                ->ownedBy($owner)
                ->where('provider', $provider)
                ->findOrFail($reconnectAccountId);
            Gate::forUser($owner)->authorize('update', $account);
            $request->session()->put($sessionKey, $account->getKey());
        } else {
            $request->session()->forget($sessionKey);
        }

        $configuration = config("memoria.social.providers.{$provider->value}");
        $driver = is_array($configuration) ? ($configuration['socialite_driver'] ?? null) : null;
        abort_unless(is_string($driver) && $driver !== '', 404);

        $socialiteProvider = Socialite::driver($driver);
        abort_unless($socialiteProvider instanceof AbstractProvider, 422);

        return $socialiteProvider->scopes((array) ($configuration['scopes'] ?? []))->redirect();
    }

    public function callback(
        Request $request,
        SocialProvider $provider,
        ConnectSocialAccount $connectSocialAccount,
        SocialOnboardingReadiness $readiness,
    ): RedirectResponse {
        Gate::authorize('create', SocialAccount::class);
        $state = $readiness->for($provider);
        abort_unless($state['available'], 422, $state['message']);
        $configuration = config("memoria.social.providers.{$provider->value}");
        $driver = is_array($configuration) ? ($configuration['socialite_driver'] ?? null) : null;
        abort_unless(is_string($driver) && $driver !== '', 404);
        $expectedAccountId = $request->session()->pull($this->reconnectSessionKey($provider));

        try {
            $owner = $request->user();
            abort_unless($owner instanceof User, 403);
            $providerUser = Socialite::driver($driver)->user();
            if (! $providerUser instanceof SocialiteUser) {
                abort(422, __('This provider does not support the required OAuth flow.'));
            }

            if (is_numeric($expectedAccountId)) {
                $expectedAccount = SocialAccount::query()
                    ->ownedBy($owner)
                    ->where('provider', $provider)
                    ->findOrFail((int) $expectedAccountId);

                if (! hash_equals((string) $expectedAccount->provider_user_id, (string) $providerUser->getId())) {
                    throw ValidationException::withMessages([
                        'social_account' => [__('The provider returned a different account. Sign in with the exact identity you chose to reconnect; no credentials were stored.')],
                    ]);
                }
            }

            $connectSocialAccount->handle($owner, $provider, $providerUser);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            Notification::make()
                ->danger()
                ->title(__('Social account not connected'))
                ->body(is_string($message) ? $message : __('The provider identity could not be verified.'))
                ->persistent()
                ->send();

            return redirect('/app/social-accounts')->withErrors($exception->errors());
        } catch (Throwable $exception) {
            report(new SanitizedSocialIntegrationException(
                operation: 'oauth_callback',
                provider: $provider->value,
                failureClass: class_basename($exception),
            ));

            Notification::make()
                ->danger()
                ->title(__('Social account not connected'))
                ->body(__('The provider connection could not be completed. No credentials were stored.'))
                ->persistent()
                ->send();

            return redirect('/app/social-accounts')->withErrors([
                'social_account' => __('The social account could not be connected. No credentials were stored.'),
            ]);
        }

        Notification::make()
            ->success()
            ->title(__('Social account connected'))
            ->body(__('The exact provider identity is ready. Delivery results will appear in social delivery history.'))
            ->send();

        return redirect('/app/social-accounts')->with('status', __('Social account connected.'));
    }

    public function destroy(
        Request $request,
        SocialAccount $socialAccount,
        DisconnectSocialAccount $disconnectSocialAccount,
    ): RedirectResponse {
        Gate::authorize('delete', $socialAccount);
        $disconnectSocialAccount->handle($socialAccount, $request->user());

        return back()->with('status', __('Social account disconnected.'));
    }

    private function reconnectSessionKey(SocialProvider $provider): string
    {
        return "memoria.social.reconnect.{$provider->value}";
    }
}
