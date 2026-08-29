<?php

namespace App\Filament\App\Pages;

use App\Actions\ListDatabaseSessions;
use App\Actions\RemovePublicProfileImage;
use App\Actions\SignOutOtherDatabaseSessions;
use App\Actions\UpdatePublicProfileImage;
use App\Enums\AppearancePreference;
use App\Models\User;
use App\Models\UserPreference;
use App\Models\UserProfile;
use App\Services\AuditRecorder;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Account';

    protected static ?int $navigationSort = 50;

    protected static ?string $title = 'Settings';

    protected string $view = 'filament.app.pages.settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $profile = $this->profile();
        $preferences = $this->preferences();
        $appearance = $preferences->appearance;
        $supportedLocales = (array) config('memoria.localization.supported_locales', ['en' => 'English']);

        $this->settingsSchema()->fill([
            'username' => $profile->username,
            'display_name' => $profile->display_name,
            'biography' => $profile->biography,
            'website_url' => $profile->website_url,
            'is_public' => $profile->is_public,
            'locale' => array_key_exists($preferences->locale, $supportedLocales)
                ? $preferences->locale
                : (string) config('app.locale', 'en'),
            'timezone' => $preferences->timezone,
            'appearance' => $appearance instanceof AppearancePreference
                ? $appearance->value
                : (string) $appearance,
            'on_this_day_enabled' => $preferences->on_this_day_enabled,
            'notification_writing_reminders' => (bool) data_get($preferences->notification_preferences, 'writing_reminders', true),
            'notification_export_ready' => (bool) data_get($preferences->notification_preferences, 'export_ready', true),
            'notification_publication_activity' => (bool) data_get($preferences->notification_preferences, 'publication_activity', true),
            'privacy_share_view_tracking_default' => (bool) data_get($preferences->privacy_preferences, 'share_view_tracking_default', false),
            'privacy_include_attachments_default' => (bool) data_get($preferences->privacy_preferences, 'include_attachments_default', false),
            'privacy_search_engine_indexing_default' => (bool) data_get($preferences->privacy_preferences, 'search_engine_indexing_default', false),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Public profile'))
                    ->description(__('A profile is private until you switch it on. Only separately published stories can appear there.'))
                    ->icon(Heroicon::OutlinedIdentification)
                    ->schema([
                        Toggle::make('is_public')
                            ->label(__('Show my public profile'))
                            ->helperText(__('Turning this off hides your profile and website publications and permanently removes the sanitized public portrait and cover copies. It never changes private memories.'))
                            ->live(),
                        TextInput::make('username')
                            ->label(__('Public username'))
                            ->prefix('@')
                            ->helperText(__('3–40 characters. Start and end with a letter or number; dots, underscores, and dashes may appear inside. Usernames are saved in lowercase.'))
                            ->required(fn (Get $get): bool => (bool) $get('is_public'))
                            ->dehydrateStateUsing(fn (mixed $state): ?string => is_string($state)
                                ? Str::lower(trim($state))
                                : null)
                            ->maxLength(40)
                            ->rules(fn (Get $get): array => ! (bool) $get('is_public') ? [] : [
                                function (string $attribute, mixed $value, Closure $fail): void {
                                    if (! is_string($value) || ! preg_match('/^[a-z0-9](?:[a-z0-9._-]{1,38}[a-z0-9])$/', Str::lower(trim($value)))) {
                                        $fail(__('Use 3–40 safe characters, beginning and ending with a letter or number.'));
                                    }
                                },
                                function (string $attribute, mixed $value, Closure $fail): void {
                                    if (! is_string($value)) {
                                        return;
                                    }

                                    $username = Str::lower(trim($value));
                                    $reserved = ['admin', 'api', 'app', 'login', 'memoria', 'privacy', 'register', 'settings', 'support', 'terms', 'www'];

                                    if (in_array($username, $reserved, true)) {
                                        $fail(__('That username is reserved. Please choose another.'));

                                        return;
                                    }

                                    $exists = UserProfile::query()
                                        ->whereRaw('LOWER(username) = ?', [$username])
                                        ->whereKeyNot($this->profile()->getKey())
                                        ->exists();

                                    if ($exists) {
                                        $fail(__('That username is already in use.'));
                                    }
                                },
                            ]),
                        TextInput::make('display_name')
                            ->label(__('Public display name'))
                            ->maxLength(255),
                        TextInput::make('website_url')
                            ->label(__('Website'))
                            ->url()
                            ->maxLength(2048),
                        Textarea::make('biography')
                            ->label(__('Short biography'))
                            ->rows(4)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(__('Language, time & appearance'))
                    ->description(__('Appearance applies on this device immediately and is also saved to your account.'))
                    ->icon(Heroicon::OutlinedSwatch)
                    ->schema([
                        Select::make('appearance')
                            ->label(__('Appearance'))
                            ->options([
                                AppearancePreference::System->value => __('Follow system'),
                                AppearancePreference::Light->value => __('Light'),
                                AppearancePreference::Dark->value => __('Dark'),
                            ])
                            ->required()
                            ->native(false),
                        Select::make('timezone')
                            ->label(__('Timezone'))
                            ->options(array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                            ->searchable()
                            ->required(),
                        Select::make('locale')
                            ->label(__('Language'))
                            ->options(fn (): array => collect((array) config('memoria.localization.supported_locales', ['en' => 'English']))
                                ->mapWithKeys(fn (mixed $label, mixed $locale): array => [(string) $locale => __((string) $label)])
                                ->all())
                            ->helperText(__('Only installed translations are offered. The interface remains ready for additional languages and RTL layouts.'))
                            ->required()
                            ->native(false),
                        Toggle::make('on_this_day_enabled')
                            ->label(__('On this day memories'))
                            ->helperText(__('Let earlier memories from today return in your private diary.')),
                    ])
                    ->columns(2),
                Section::make(__('Notifications'))
                    ->description(__('Choose which useful account events may notify you. Writing reminders are managed separately.'))
                    ->icon(Heroicon::OutlinedBell)
                    ->schema([
                        Toggle::make('notification_writing_reminders')
                            ->label(__('Writing reminders')),
                        Toggle::make('notification_export_ready')
                            ->label(__('Export is ready or failed')),
                        Toggle::make('notification_publication_activity')
                            ->label(__('Publication and social delivery activity')),
                    ])
                    ->columns(3),
                Section::make(__('Privacy defaults'))
                    ->description(__('Defaults reduce repeated choices. Every share link and public story remains separately reviewable.'))
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->schema([
                        Toggle::make('privacy_share_view_tracking_default')
                            ->label(__('Count private-link opens by default'))
                            ->helperText(__('Counts are approximate and do not identify visitors.')),
                        Toggle::make('privacy_include_attachments_default')
                            ->label(__('Include clean attachments in new links'))
                            ->helperText(__('You can still change this for each link.')),
                        Toggle::make('privacy_search_engine_indexing_default')
                            ->label(__('Allow indexing for new public stories'))
                            ->helperText(__('Off is the privacy-preserving default. Publishing is still a separate action.')),
                    ])
                    ->columns(3),
            ]);
    }

    public function save(): void
    {
        $data = $this->settingsSchema()->getState();
        $user = $this->user();
        $profile = $this->profile();
        $preferences = $this->preferences();
        $isDisablingPublicProfile = ! (bool) ($data['is_public'] ?? false)
            && ($profile->is_public || filled($profile->avatar_path) || filled($profile->cover_image_path));

        Gate::forUser($user)->authorize('update', $profile);
        Gate::forUser($user)->authorize('update', $preferences);

        if ($isDisablingPublicProfile) {
            foreach (['avatar', 'cover'] as $kind) {
                app(RemovePublicProfileImage::class)->handle($profile, $user, $kind);
            }

            $profile->refresh();
        }

        DB::transaction(function () use ($data, $preferences, $profile, $user): void {
            $profile->update([
                'username' => filled($data['username'] ?? null) ? $data['username'] : null,
                'display_name' => filled($data['display_name'] ?? null) ? $data['display_name'] : null,
                'biography' => filled($data['biography'] ?? null) ? $data['biography'] : null,
                'website_url' => filled($data['website_url'] ?? null) ? $data['website_url'] : null,
                'is_public' => (bool) ($data['is_public'] ?? false),
            ]);

            $preferences->update([
                'locale' => $data['locale'],
                'timezone' => $data['timezone'],
                'appearance' => $data['appearance'],
                'on_this_day_enabled' => (bool) ($data['on_this_day_enabled'] ?? false),
                'notification_preferences' => [
                    'writing_reminders' => (bool) ($data['notification_writing_reminders'] ?? false),
                    'export_ready' => (bool) ($data['notification_export_ready'] ?? false),
                    'publication_activity' => (bool) ($data['notification_publication_activity'] ?? false),
                ],
                'privacy_preferences' => [
                    'share_view_tracking_default' => (bool) ($data['privacy_share_view_tracking_default'] ?? false),
                    'include_attachments_default' => (bool) ($data['privacy_include_attachments_default'] ?? false),
                    'search_engine_indexing_default' => (bool) ($data['privacy_search_engine_indexing_default'] ?? false),
                ],
            ]);

            app(AuditRecorder::class)->record(
                event: 'user.settings.updated',
                actor: $user,
                auditable: $preferences,
                metadata: [
                    'public_profile' => (bool) ($data['is_public'] ?? false),
                    'appearance' => $data['appearance'],
                    'on_this_day_enabled' => (bool) ($data['on_this_day_enabled'] ?? false),
                ],
                request: request(),
            );
        });

        $appearance = AppearancePreference::from($data['appearance'])->value;
        $this->js("window.dispatchEvent(new CustomEvent('theme-changed', { detail: ".Js::from($appearance).' }))');

        Notification::make()
            ->success()
            ->title(__('Settings saved'))
            ->body($isDisablingPublicProfile
                ? __('Your public profile is hidden, and its sanitized portrait and cover copies were removed from public storage.')
                : __('Your privacy and notification preferences are up to date.'))
            ->send();
    }

    /**
     * @return array{supported: bool, sessions: array<int, array{id: string, is_current: bool, ip_address: string|null, user_agent: string|null, last_activity: int}>}
     */
    public function databaseSessions(): array
    {
        return app(ListDatabaseSessions::class)->handle($this->user(), $this->currentSessionId());
    }

    public function sessionDevice(?string $userAgent): string
    {
        if (blank($userAgent)) {
            return __('Unknown browser or device');
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => __('Browser'),
        };
        $device = match (true) {
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => __('unknown device'),
        };

        return $browser.' · '.$device;
    }

    public function sessionLastActive(int $timestamp): string
    {
        return CarbonImmutable::createFromTimestamp($timestamp)->diffForHumans();
    }

    public function signOutOtherSessionsAction(): Action
    {
        return Action::make('signOutOtherSessions')
            ->label(__('Sign out other sessions'))
            ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
            ->color('danger')
            ->visible(function (): bool {
                $sessionState = $this->databaseSessions();

                return $sessionState['supported'] && count($sessionState['sessions']) > 1;
            })
            ->requiresConfirmation()
            ->modalHeading(__('Sign out everywhere else?'))
            ->modalDescription(__('Your current session stays signed in. All other remembered browser sessions for this account are removed.'))
            ->schema([
                TextInput::make('current_password')
                    ->label(__('Current password'))
                    ->password()
                    ->revealable()
                    ->autocomplete('current-password')
                    ->required(),
            ])
            ->modalSubmitActionLabel(__('Sign out other sessions'))
            ->action(function (array $data): void {
                $count = app(SignOutOtherDatabaseSessions::class)->handle(
                    owner: $this->user(),
                    currentPassword: (string) $data['current_password'],
                    currentSessionId: $this->currentSessionId(),
                );

                Notification::make()
                    ->success()
                    ->title(trans_choice('{0} No other sessions were active|{1} One other session was signed out|[2,*] :count other sessions were signed out', $count, ['count' => $count]))
                    ->body(__('This browser remains signed in.'))
                    ->send();
            });
    }

    public function updateAvatarAction(): Action
    {
        return $this->profileImageUploadAction('avatar', __('Update portrait'));
    }

    public function updateCoverAction(): Action
    {
        return $this->profileImageUploadAction('cover', __('Update cover'));
    }

    public function removeAvatarAction(): Action
    {
        return $this->profileImageRemovalAction('avatar', __('Remove portrait'));
    }

    public function removeCoverAction(): Action
    {
        return $this->profileImageRemovalAction('cover', __('Remove cover'));
    }

    public function profileImageUrl(string $kind): ?string
    {
        $profile = $this->profile();
        $path = match ($kind) {
            'avatar' => $profile->avatar_path,
            'cover' => $profile->cover_image_path,
            default => null,
        };

        if (! is_string($path)
            || $path === ''
            || ! $profile->is_public
            || ! is_string($profile->username)
            || $profile->username === '') {
            return null;
        }

        return route('profiles.images.show', [
            'username' => $profile->username,
            'kind' => $kind,
        ]);
    }

    public function isPublicProfileEnabled(): bool
    {
        return (bool) $this->profile()->is_public;
    }

    private function profileImageUploadAction(string $kind, string $label): Action
    {
        return Action::make('update'.ucfirst($kind))
            ->label($label)
            ->icon(Heroicon::OutlinedCloudArrowUp)
            ->color('gray')
            ->visible(fn (): bool => (bool) $this->profile()->is_public)
            ->modalHeading($kind === 'avatar' ? __('Update public portrait') : __('Update public profile cover'))
            ->modalDescription(__('The server validates and re-encodes the image into a separate public copy with embedded metadata removed. Inspect the visible image itself for faces, documents, signs, reflections, and location clues.'))
            ->schema([
                FileUpload::make('image')
                    ->label(__('Image'))
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize((int) config('memoria.public_images.maximum_kilobytes', 20480))
                    ->storeFiles(false)
                    ->required()
                    ->helperText(__('JPEG, PNG, or WebP. The original upload is never published as-is.')),
            ])
            ->modalSubmitActionLabel(__('Create sanitized public copy'))
            ->action(function (array $data) use ($kind): void {
                abort_unless($this->profile()->is_public, 422);

                $image = $data['image'] ?? null;
                abort_unless($image instanceof TemporaryUploadedFile, 422);

                app(UpdatePublicProfileImage::class)->handle(
                    image: $image,
                    profile: $this->profile(),
                    owner: $this->user(),
                    kind: $kind,
                );

                Notification::make()
                    ->success()
                    ->title(__('Public profile image updated'))
                    ->body(__('A re-encoded copy with embedded metadata removed is now used.'))
                    ->send();
            });
    }

    private function profileImageRemovalAction(string $kind, string $label): Action
    {
        return Action::make('remove'.ucfirst($kind))
            ->label($label)
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (): bool => $this->profileImageUrl($kind) !== null)
            ->requiresConfirmation()
            ->modalDescription(__('The sanitized public copy will be removed. This does not affect any private diary attachment.'))
            ->action(function () use ($kind): void {
                app(RemovePublicProfileImage::class)->handle(
                    profile: $this->profile(),
                    owner: $this->user(),
                    kind: $kind,
                );

                Notification::make()
                    ->success()
                    ->title(__('Public profile image removed'))
                    ->send();
            });
    }

    private function profile(): UserProfile
    {
        $profile = $this->user()->profile()->firstOrCreate([]);
        abort_unless($profile instanceof UserProfile, 500);

        return $profile;
    }

    private function preferences(): UserPreference
    {
        $preferences = $this->user()->preferences()->firstOrCreate([]);
        abort_unless($preferences instanceof UserPreference, 500);

        return $preferences;
    }

    private function settingsSchema(): Schema
    {
        $schema = $this->getSchema('form');
        abort_unless($schema instanceof Schema, 500);

        return $schema;
    }

    private function currentSessionId(): ?string
    {
        return request()->hasSession() ? request()->session()->getId() : null;
    }

    private function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
