<x-filament-panels::page>
    <form wire:submit="save" class="grid gap-6">
        {{ $this->form }}

        <div class="flex flex-wrap items-center gap-3">
            <x-filament::button type="submit" icon="heroicon-o-check">
                {{ __('Save settings') }}
            </x-filament::button>
            <span wire:loading wire:target="save" class="text-sm text-gray-600 dark:text-gray-300" role="status">
                {{ __('Saving your preferences…') }}
            </span>
        </div>
    </form>

    <x-filament::section
        :heading="__('Public profile images')"
        :description="__('Profile images are separate public copies. Uploading one never publishes a diary attachment or a memory.')"
        icon="heroicon-o-photo"
    >
        @if (! $this->isPublicProfileEnabled())
            <p class="mb-5 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm leading-6 text-gray-700 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">
                {{ __('Enable and save your public profile before adding a portrait or cover. Memoria does not stage anonymously reachable profile images for a hidden profile.') }}
            </p>
        @endif
        <div class="grid gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
            <div>
                <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Portrait') }}</p>
                <div class="mt-3 flex size-32 items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-950">
                    @if ($this->profileImageUrl('avatar'))
                        <img src="{{ $this->profileImageUrl('avatar') }}" alt="{{ __('Current public portrait') }}" class="size-full object-cover">
                    @else
                        <x-filament::icon icon="heroicon-o-user" class="size-9 text-gray-400" />
                    @endif
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    {{ $this->updateAvatarAction }}
                    {{ $this->removeAvatarAction }}
                </div>
            </div>

            <div>
                <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Cover image') }}</p>
                <div class="mt-3 flex aspect-[3/1] min-h-32 w-full items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-950">
                    @if ($this->profileImageUrl('cover'))
                        <img src="{{ $this->profileImageUrl('cover') }}" alt="{{ __('Current public profile cover') }}" class="size-full object-cover">
                    @else
                        <x-filament::icon icon="heroicon-o-photo" class="size-9 text-gray-400" />
                    @endif
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    {{ $this->updateCoverAction }}
                    {{ $this->removeCoverAction }}
                </div>
            </div>
        </div>
        <p class="mt-5 text-sm leading-6 text-gray-600 dark:text-gray-300">
            {{ __('Memoria re-encodes accepted images and removes embedded metadata such as EXIF. That cannot hide information visible in the pixels, so inspect the final portrait and cover carefully.') }}
        </p>
    </x-filament::section>

    <x-filament::section
        :heading="__('Account & security')"
        :description="__('Manage identity, password, verified email, two-factor authentication, connected accounts, and portable copies of your data.')"
        icon="heroicon-o-lock-closed"
    >
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-filament::button
                :href="route('filament.app.auth.profile')"
                tag="a"
                color="gray"
                outlined
                icon="heroicon-o-identification"
            >
                {{ __('Profile & security') }}
            </x-filament::button>
            <x-filament::button
                :href="\App\Filament\App\Resources\SocialAccountResource::getUrl()"
                tag="a"
                color="gray"
                outlined
                icon="heroicon-o-share"
            >
                {{ __('Connected accounts') }}
            </x-filament::button>
            <x-filament::button
                :href="\App\Filament\App\Resources\ExportResource::getUrl()"
                tag="a"
                color="gray"
                outlined
                icon="heroicon-o-arrow-down-tray"
            >
                {{ __('Data & exports') }}
            </x-filament::button>
            <x-filament::button
                :href="\App\Filament\App\Resources\SecurityActivityResource::getUrl()"
                tag="a"
                color="gray"
                outlined
                icon="heroicon-o-shield-check"
            >
                {{ __('Security activity') }}
            </x-filament::button>
        </div>
        <p class="mt-4 text-sm leading-6 text-gray-600 dark:text-gray-300">
            {{ __('Two-factor authentication and recovery codes are available under Profile & security. Appearance can also be changed at any time from the top bar.') }}
        </p>

        @php($sessionState = $this->databaseSessions())
        <div class="mt-7 border-t border-gray-200 pt-6 dark:border-gray-700">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-2xl">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Active browser sessions') }}</h3>
                    <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">
                        {{ __('Review browsers signed in to your account. Approximate IP and device details can help you spot access you do not recognize.') }}
                    </p>
                </div>
                @if ($sessionState['supported'])
                    {{ $this->signOutOtherSessionsAction }}
                @endif
            </div>

            @if (! $sessionState['supported'])
                <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm leading-6 text-gray-700 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200" role="status">
                    {{ __('Session details are unavailable with the current server session storage. You can still change your password and manage two-factor authentication under Profile & security.') }}
                </div>
            @elseif ($sessionState['sessions'] === [])
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-300" role="status">
                    {{ __('No database-backed browser session records are available for this account.') }}
                </p>
            @else
                <ul class="mt-4 divide-y divide-gray-200 rounded-xl border border-gray-200 dark:divide-gray-700 dark:border-gray-700" aria-label="{{ __('Active browser sessions') }}">
                    @foreach ($sessionState['sessions'] as $session)
                        <li class="flex flex-wrap items-center justify-between gap-4 p-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="font-semibold text-gray-950 dark:text-white">
                                        {{ $this->sessionDevice($session['user_agent']) }}
                                    </p>
                                    @if ($session['is_current'])
                                        <x-filament::badge color="success">{{ __('This session') }}</x-filament::badge>
                                    @endif
                                </div>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                    {{ $session['ip_address'] ?: __('IP unavailable') }}
                                    <span aria-hidden="true"> · </span>
                                    {{ __('Active :time', ['time' => $this->sessionLastActive($session['last_activity'])]) }}
                                </p>
                            </div>
                            @if (! $session['is_current'])
                                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Other session') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </x-filament::section>

    <x-filament::section
        :heading="__('Delete account')"
        :description="__('Permanently delete your account, private diary data, public publications, and stored files. Supported social-post removals are queued before credentials are cleared, but provider copies may remain if authorization or removal fails. Export anything you want to keep first.')"
        icon="heroicon-o-exclamation-triangle"
        collapsible
        collapsed
    >
        <form method="POST" action="{{ route('account.destroy') }}" class="grid max-w-xl gap-4" data-turbo="false">
            @csrf
            @method('DELETE')
            <div>
                <label for="delete-account-password" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Current password') }}</label>
                <input id="delete-account-password" name="password" type="password" required autocomplete="current-password" class="fi-input mt-2 block min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 dark:border-gray-700 dark:bg-gray-950">
                @error('password')
                    <p class="mt-2 text-sm text-danger-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="delete-account-confirmation" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Type DELETE to confirm') }}</label>
                <input id="delete-account-confirmation" name="confirmation" type="text" required autocomplete="off" pattern="DELETE" class="fi-input mt-2 block min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 dark:border-gray-700 dark:bg-gray-950">
                @error('confirmation')
                    <p class="mt-2 text-sm text-danger-600">{{ $message }}</p>
                @enderror
            </div>
            <x-filament::button type="submit" color="danger" icon="heroicon-o-trash">
                {{ __('Permanently delete my account') }}
            </x-filament::button>
        </form>
    </x-filament::section>
</x-filament-panels::page>
