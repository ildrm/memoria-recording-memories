<?php

namespace App\Providers;

use App\Contracts\AttachmentScanner;
use App\Contracts\SocialPublisherRegistry;
use App\Http\Middleware\PrivateResponse;
use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use App\Services\AttachmentScanning\ClamAvAttachmentScanner;
use App\Services\AttachmentScanning\FakeAttachmentScanner;
use App\Services\AttachmentScanning\UnavailableAttachmentScanner;
use App\Services\AuditRecorder;
use App\Services\Social\FacebookPageSocialPublisher;
use App\Services\Social\FakeSocialPublisher;
use App\Services\Social\LinkedInSocialPublisher;
use App\Services\Social\MastodonSocialPublisher;
use App\Services\Social\SocialPublisherManager;
use App\Services\Social\UnavailableSocialPublisher;
use App\Services\Social\XSocialPublisher;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FilePreviewController;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AttachmentScanner::class, function ($app): AttachmentScanner {
            $driver = (string) config('memoria.attachments.scanner.driver');

            if ($app->environment('local', 'testing') && in_array($driver, ['', 'fake'], true)) {
                return $app->make(FakeAttachmentScanner::class);
            }

            if ($driver === 'clamav') {
                return $app->make(ClamAvAttachmentScanner::class);
            }

            return $app->make(UnavailableAttachmentScanner::class);
        });

        $this->app->singleton(SocialPublisherRegistry::class, function ($app) {
            if ($app->environment('local', 'testing')) {
                return new SocialPublisherManager([$app->make(FakeSocialPublisher::class)]);
            }

            if (config('memoria.social.driver') === 'real') {
                return new SocialPublisherManager([
                    $app->make(XSocialPublisher::class),
                    $app->make(LinkedInSocialPublisher::class),
                    $app->make(FacebookPageSocialPublisher::class),
                    $app->make(MastodonSocialPublisher::class),
                ]);
            }

            return new SocialPublisherManager([$app->make(UnavailableSocialPublisher::class)]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        FilePreviewController::$middleware = [
            'web',
            PrivateResponse::class,
            SecurityHeaders::class,
        ];

        Model::preventLazyLoading(! app()->isProduction());
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());
        Model::preventAccessingMissingAttributes(! app()->isProduction());

        Gate::before(function (User $user): ?bool {
            return User::query()
                ->whereKey($user->getAuthIdentifier())
                ->whereNull('disabled_at')
                ->exists() ? null : false;
        });

        RateLimiter::for('share-read', function (Request $request): array {
            $tokenFingerprint = hash('sha256', (string) $request->route('token'));

            return [
                Limit::perMinute(60)->by('share-read-ip:'.$request->ip()),
                Limit::perMinute(30)->by('share-read-token:'.$tokenFingerprint.'|'.$request->ip()),
            ];
        });

        RateLimiter::for('share-password', function (Request $request): array {
            $tokenFingerprint = hash('sha256', (string) $request->route('token'));
            $tokenAndIp = $tokenFingerprint.'|'.$request->ip();

            return [
                Limit::perMinute(5)->by('share-password-minute:'.$tokenAndIp),
                Limit::perHour(20)->by('share-password-hour:'.$tokenAndIp),
                Limit::perHour(100)->by('share-password-token-hour:'.$tokenFingerprint),
            ];
        });

        RateLimiter::for('entry-mutations', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('entry-mutations:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('attachment-uploads', function (Request $request): array {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return [
                Limit::perMinute(10)->by('attachment-uploads-minute:'.$key),
                Limit::perHour(100)->by('attachment-uploads-hour:'.$key),
            ];
        });

        RateLimiter::for('private-downloads', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('private-downloads:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('share-management', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('share-management:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('exports', function (Request $request): array {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return [
                Limit::perHour(5)->by('exports-hour:'.$key),
                Limit::perDay(20)->by('exports-day:'.$key),
            ];
        });

        RateLimiter::for('export-downloads', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('export-downloads:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('export-actions', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('export-actions:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('publication-actions', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('publication:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('publication-previews', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('publication-previews:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('social-oauth-starts', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('social-oauth-starts:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('social-oauth-callbacks', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('social-oauth-callbacks:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('social-account-actions', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('social-account-actions:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('account-deletion', fn (Request $request): Limit => Limit::perDay(3)
            ->by('account-deletion:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('public-read', fn (Request $request): Limit => Limit::perMinute(120)
            ->by('public-read:'.$request->ip()));

        RateLimiter::for('sitemap', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('sitemap:'.$request->ip()));

        RateLimiter::for('public-comments', function (Request $request): array {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return [
                Limit::perMinute(5)->by('comments-minute:'.$key),
                Limit::perHour(30)->by('comments-hour:'.$key),
            ];
        });

        RateLimiter::for('public-comment-deletions', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('comment-deletions:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('public-reactions', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('reactions:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('public-reports', function (Request $request): array {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return [
                Limit::perHour(5)->by('reports-hour:'.$key),
                Limit::perDay(15)->by('reports-day:'.$key),
            ];
        });

        RateLimiter::for('entry-sharing', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('entry-sharing:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('livewire-updates', function (Request $request): array {
            $actor = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return [
                Limit::perMinute(120)->by('livewire-actor:'.$actor),
                Limit::perMinute(240)->by('livewire-ip:'.$request->ip()),
            ];
        });

        Livewire::setUpdateRoute(
            fn (callable|array|string $handle, string $path): RouteDefinition => Route::post($path, $handle)
                ->middleware([
                    'web',
                    'throttle:livewire-updates',
                    PrivateResponse::class,
                    SecurityHeaders::class,
                ]),
        );

        $this->registerSecurityAuditHooks();
        $this->registerAccountDisableHooks();
    }

    private function registerSecurityAuditHooks(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();

            $this->auditRecorder()->record(
                event: 'authentication.login',
                actor: $event->user,
                auditable: $event->user,
                metadata: ['guard' => $event->guard, 'remembered' => $event->remember],
                request: $this->request(),
            );
        });

        Event::listen(Logout::class, function (Logout $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            $persistedUser = User::query()->find($event->user->getAuthIdentifier());

            $this->auditRecorder()->record(
                event: 'authentication.logout',
                actor: $persistedUser,
                auditable: $persistedUser,
                metadata: [
                    'guard' => $event->guard,
                    'account_existed' => $persistedUser !== null,
                ],
                request: $this->request(),
            );
        });

        Event::listen(Failed::class, function (Failed $event): void {
            $user = $event->user instanceof User ? $event->user : null;
            $this->auditRecorder()->record(
                event: 'authentication.failed',
                actor: $user,
                auditable: $user,
                metadata: ['guard' => $event->guard, 'matched_account' => $user !== null],
                request: $this->request(),
            );
        });

        User::updating(function (User $user): void {
            if ($user->isDirty('password')) {
                $user->password_changed_at = now();
            }
        });

        User::updated(function (User $user): void {
            $actor = Auth::user();
            $actor = $actor instanceof User ? $actor : null;

            if ($user->wasChanged('email')) {
                $this->auditRecorder()->record(
                    event: 'account.email_changed',
                    actor: $actor,
                    auditable: $user,
                    metadata: ['self_service' => $actor?->is($user) ?? false],
                    request: $this->request(),
                );
            }

            if ($user->wasChanged('password')) {
                $this->auditRecorder()->record(
                    event: 'account.password_changed',
                    actor: $actor,
                    auditable: $user,
                    metadata: ['self_service' => $actor?->is($user) ?? false],
                    request: $this->request(),
                );
            }

            if ($user->wasChanged('app_authentication_secret')) {
                $this->auditRecorder()->record(
                    event: $user->getAppAuthenticationSecret() === null
                        ? 'account.totp_disabled'
                        : 'account.totp_enabled',
                    actor: $actor,
                    auditable: $user,
                    request: $this->request(),
                );
            } elseif ($user->wasChanged('app_authentication_recovery_codes')) {
                $this->auditRecorder()->record(
                    event: 'account.totp_recovery_codes_regenerated',
                    actor: $actor,
                    auditable: $user,
                    request: $this->request(),
                );
            }
        });
    }

    private function registerAccountDisableHooks(): void
    {
        User::updating(function (User $user): void {
            if (! $user->isDirty('disabled_at') || $user->disabled_at === null) {
                return;
            }

            $user->setRememberToken(Str::random(60));
            $userId = $user->getKey();

            DB::afterCommit(function () use ($userId): void {
                if (config('session.driver') !== 'database') {
                    return;
                }

                DB::table((string) config('session.table', 'sessions'))
                    ->where('user_id', $userId)
                    ->delete();
            });
        });
    }

    private function auditRecorder(): AuditRecorder
    {
        return app(AuditRecorder::class);
    }

    private function request(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request ? $request : null;
    }
}
