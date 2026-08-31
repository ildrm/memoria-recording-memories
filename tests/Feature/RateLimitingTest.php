<?php

use App\Actions\CreateShareLink;
use App\Enums\CommentStatus;
use App\Enums\ReactionType;
use App\Enums\SocialProvider;
use App\Filament\App\Resources\EntryResource\Pages\EditEntry;
use App\Filament\App\Resources\EntryResource\RelationManagers\AttachmentsRelationManager;
use App\Filament\App\Resources\ExportResource\Pages\ListExports;
use App\Filament\App\Resources\PublicationResource\Pages\CreatePublication;
use App\Filament\App\Resources\ShareLinkResource\Pages\CreateShareLink as CreateShareLinkPage;
use App\Filament\App\Resources\ShareLinkResource\Pages\EditShareLink as EditShareLinkPage;
use App\Filament\App\Resources\SocialAccountResource\Pages\ListSocialAccounts;
use App\Http\Middleware\PrivateResponse;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Comment;
use App\Models\Entry;
use App\Models\Export;
use App\Models\Publication;
use App\Models\PublicationTarget;
use App\Models\ShareLink;
use App\Models\SocialAccount;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function installSingleAttemptLimiter(string $name): void
{
    RateLimiter::for(
        $name,
        fn (Request $request): Limit => Limit::perMinute(1)->by('rate-limit-test:'.$name),
    );
}

function interactiveRateLimitKey(string $bucket, string $window, User $user): string
{
    return implode(':', [
        'memoria',
        'interactive',
        $bucket,
        $window,
        (string) $user->getAuthIdentifier(),
    ]);
}

test('every first party mutation route has a named rate limiter', function (): void {
    $unthrottledRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route): bool => str_starts_with(
            $route->getActionName(),
            'App\\Http\\Controllers\\',
        ))
        ->filter(fn (RouteDefinition $route): bool => array_intersect(
            $route->methods(),
            ['POST', 'PUT', 'PATCH', 'DELETE'],
        ) !== [])
        ->reject(fn (RouteDefinition $route): bool => collect($route->gatherMiddleware())
            ->contains(fn (string $middleware): bool => str_starts_with($middleware, 'throttle:')))
        ->map(fn (RouteDefinition $route): string => $route->getName() ?? $route->uri())
        ->values()
        ->all();

    expect($unthrottledRoutes)->toBe([]);
});

test('sensitive route families keep purpose specific limiter budgets', function (): void {
    $expectedAssignments = [
        'shares.show' => 'share-read',
        'shares.attachments.show' => 'share-read',
        'shares.unlock' => 'share-password',
        'publications.comments.store' => 'public-comments',
        'publications.reactions.store' => 'public-reactions',
        'publications.reports.store' => 'public-reports',
        'comments.reports.store' => 'public-reports',
        'comments.destroy' => 'public-comment-deletions',
        'entries.store' => 'entry-mutations',
        'entries.update' => 'entry-mutations',
        'entries.versions.restore' => 'entry-mutations',
        'attachments.store' => 'attachment-uploads',
        'attachments.download' => 'private-downloads',
        'publications.media.preview' => 'private-downloads',
        'entry-publications.store' => 'publication-actions',
        'app.publications.privacy-review' => 'publication-previews',
        'app.publications.privacy-review.store' => 'publication-actions',
        'app.publications.preview' => 'publication-previews',
        'app.publications.preview.store' => 'publication-actions',
        'app.publications.publish' => 'publication-actions',
        'app.publications.unpublish' => 'publication-actions',
        'app.publications.schedule' => 'publication-actions',
        'app.publications.schedule.destroy' => 'publication-actions',
        'share-links.store' => 'share-management',
        'share-links.destroy' => 'share-management',
        'entry-shares.store' => 'entry-sharing',
        'entry-shares.destroy' => 'entry-sharing',
        'exports.store' => 'exports',
        'exports.download' => 'export-downloads',
        'exports.destroy' => 'export-actions',
        'social.redirect' => 'social-oauth-starts',
        'social.callback' => 'social-oauth-callbacks',
        'social.disconnect' => 'social-account-actions',
        'account.destroy' => 'account-deletion',
    ];

    foreach ($expectedAssignments as $routeName => $limiter) {
        $route = Route::getRoutes()->getByName($routeName);

        expect($route)
            ->not->toBeNull()
            ->and($route?->gatherMiddleware())
            ->toContain('throttle:'.$limiter);
    }
});

test('every Livewire update request uses the global update budget', function (): void {
    installSingleAttemptLimiter('livewire-updates');

    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn (RouteDefinition $route): bool => str_ends_with(
            (string) $route->getName(),
            'livewire.update',
        ));

    expect($route)
        ->not->toBeNull()
        ->and($route?->gatherMiddleware())
        ->toContain('throttle:livewire-updates')
        ->toContain(PrivateResponse::class)
        ->toContain(SecurityHeaders::class)
        ->and(config('livewire.payload.max_calls'))
        ->toBe(20);

    $uri = '/'.ltrim((string) $route?->uri(), '/');
    $headers = ['X-Livewire' => 'true'];

    $this->withHeaders($headers)
        ->postJson($uri, ['components' => []])
        ->assertNotFound();
    $this->withHeaders($headers)
        ->postJson($uri, ['components' => []])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');
});

test('Livewire temporary uploads and previews use private bounded endpoints', function (): void {
    $uploadRoute = Route::getRoutes()->getByName('livewire.upload-file');
    $previewRoute = Route::getRoutes()->getByName('livewire.preview-file');

    foreach ([$uploadRoute, $previewRoute] as $route) {
        expect($route)
            ->not->toBeNull()
            ->and($route?->gatherMiddleware())
            ->toContain(PrivateResponse::class)
            ->toContain(SecurityHeaders::class);
    }

    expect($uploadRoute?->gatherMiddleware())
        ->toContain('throttle:attachment-uploads')
        ->and(config('livewire.temporary_file_upload.rules'))
        ->toBe(['required', 'file', 'max:20480'])
        ->and(config('livewire.temporary_file_upload.preview_mimes'))
        ->toBe(['png', 'jpg', 'jpeg', 'webp']);
});

test('filament export and attachment upload actions consume their purpose budgets', function (): void {
    Storage::fake('local');
    Queue::fake();
    Filament::setCurrentPanel(Filament::getPanel('app'));
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();
    $exportHourKey = interactiveRateLimitKey('exports', 'hour', $owner);
    $exportDayKey = interactiveRateLimitKey('exports', 'day', $owner);
    $uploadKey = interactiveRateLimitKey('attachment-uploads', 'minute', $owner);

    foreach ([$exportHourKey, $exportDayKey, $uploadKey] as $key) {
        RateLimiter::clear($key);
    }

    $this->actingAs($owner);

    Livewire::test(ListExports::class)
        ->callAction('requestExport', data: [
            'formats' => ['json'],
            'includeAttachments' => false,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    Livewire::test(AttachmentsRelationManager::class, [
        'ownerRecord' => $entry,
        'pageClass' => EditEntry::class,
    ])->callAction(
        TestAction::make('upload')->table(),
        data: ['file' => UploadedFile::fake()->image('private-memory.jpg', 320, 200)],
    )->assertHasNoActionErrors();

    expect(RateLimiter::remaining($exportHourKey, 5))->toBe(4)
        ->and(RateLimiter::remaining($exportDayKey, 20))->toBe(19)
        ->and(RateLimiter::remaining($uploadKey, 10))->toBe(9);
});

test('filament publication share and social actions consume independent purpose budgets', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();
    $socialAccount = SocialAccount::factory()->for($owner, 'owner')->create();
    $publicationKey = interactiveRateLimitKey('publication-actions', 'minute', $owner);
    $shareKey = interactiveRateLimitKey('share-management', 'minute', $owner);
    $socialKey = interactiveRateLimitKey('social-account-actions', 'minute', $owner);

    foreach ([$publicationKey, $shareKey, $socialKey] as $key) {
        RateLimiter::clear($key);
    }

    $this->actingAs($owner);

    Livewire::test(CreatePublication::class)
        ->fillForm([
            'title' => 'A bounded public draft',
            'slug' => 'a-bounded-public-draft',
            'body' => '<p>Public-safe text.</p>',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateShareLinkPage::class)
        ->fillForm([
            'entry_id' => $entry->getKey(),
            'label' => 'A bounded private link',
            'expires_at' => now()->addDay(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $shareLink = ShareLink::query()->whereBelongsTo($owner, 'owner')->sole();

    Livewire::test(EditShareLinkPage::class, ['record' => $shareLink->getKey()])
        ->fillForm(['label' => 'Updated bounded private link'])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(ListSocialAccounts::class)
        ->callAction(TestAction::make('disconnect')->table($socialAccount))
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(RateLimiter::remaining($publicationKey, 20))->toBe(19)
        ->and(RateLimiter::remaining($shareKey, 20))->toBe(18)
        ->and(RateLimiter::remaining($socialKey, 20))->toBe(19);
});

test('share viewing and password attempts are independently enforced', function (): void {
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create([
        'title' => 'A memory for a trusted reader',
    ]);
    $ordinaryShare = app(CreateShareLink::class)->handle(
        entry: $entry,
        owner: $owner,
        password: 'correct-horse-battery-staple',
    );

    $this->get(route('shares.show', ['token' => $ordinaryShare->token]))
        ->assertOk()
        ->assertSee('Enter the sharing password');
    $this->post(route('shares.unlock', ['token' => $ordinaryShare->token]), [
        'password' => 'correct-horse-battery-staple',
    ])->assertOk()->assertSee('A memory for a trusted reader');

    $protectedShare = app(CreateShareLink::class)->handle(
        entry: $entry,
        owner: $owner,
        password: 'another-correct-passphrase',
    );

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->from(route('shares.show', ['token' => $protectedShare->token]))
            ->post(route('shares.unlock', ['token' => $protectedShare->token]), [
                'password' => 'wrong-passphrase',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('password');
    }

    $this->post(route('shares.unlock', ['token' => $protectedShare->token]), [
        'password' => 'wrong-passphrase',
    ])->assertTooManyRequests()->assertHeader('Retry-After');

    $this->get(route('shares.show', ['token' => $protectedShare->token]))
        ->assertOk()
        ->assertSee('Enter the sharing password');

    $readLimitedShare = app(CreateShareLink::class)->handle($entry, $owner);

    for ($view = 1; $view <= 30; $view++) {
        $this->get(route('shares.show', ['token' => $readLimitedShare->token]))->assertOk();
    }

    $this->get(route('shares.show', ['token' => $readLimitedShare->token]))
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');
});

test('comment reply reaction and report limits reject bursts after normal requests', function (): void {
    installSingleAttemptLimiter('public-reactions');
    installSingleAttemptLimiter('public-reports');
    RateLimiter::for(
        'public-comments',
        fn (Request $request): Limit => Limit::perMinute(2)->by('rate-limit-test:comments'),
    );

    $owner = User::factory()->create();
    $owner->profile()->update(['username' => 'rate-limited-writer', 'is_public' => true]);
    $reader = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->published()->create([
        'comments_enabled' => true,
        'reactions_enabled' => true,
    ]);
    PublicationTarget::factory()->publishedWebsite($publication)->create();
    $approvedParent = Comment::factory()->for($publication)->create([
        'parent_id' => null,
        'status' => CommentStatus::Approved,
    ]);

    $this->actingAs($reader)->postJson(
        route('publications.comments.store', $publication),
        ['body' => 'A normal top-level response.'],
    )->assertCreated();

    $this->actingAs($reader)->postJson(
        route('publications.comments.store', $publication),
        ['body' => 'A normal threaded reply.', 'parent_id' => $approvedParent->getKey()],
    )->assertCreated();
    $this->actingAs($reader)->postJson(
        route('publications.comments.store', $publication),
        ['body' => 'A burst response that must be throttled.'],
    )->assertTooManyRequests();

    $this->actingAs($reader)->postJson(
        route('publications.reactions.store', $publication),
        ['type' => ReactionType::Support->value],
    )->assertCreated();
    $this->actingAs($reader)->postJson(
        route('publications.reactions.store', $publication),
        ['type' => ReactionType::Like->value],
    )->assertTooManyRequests();

    $this->actingAs($reader)->postJson(
        route('publications.reports.store', $publication),
        ['reason' => 'spam'],
    )->assertAccepted();
    $this->actingAs($reader)->postJson(
        route('publications.reports.store', $publication),
        ['reason' => 'privacy'],
    )->assertTooManyRequests();
});

test('entry attachment share export and publication operations enforce separate budgets', function (): void {
    Storage::fake('local');
    Queue::fake();
    installSingleAttemptLimiter('entry-mutations');
    installSingleAttemptLimiter('attachment-uploads');
    installSingleAttemptLimiter('share-management');
    installSingleAttemptLimiter('exports');
    installSingleAttemptLimiter('publication-actions');

    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();
    $publication = Publication::factory()->for($owner, 'owner')->create();

    $this->actingAs($owner)->postJson(route('entries.store'), [
        'title' => 'A normally saved memory',
        'timezone' => 'UTC',
    ])->assertCreated();
    $this->actingAs($owner)->postJson(route('entries.store'), [
        'title' => 'A burst save',
        'timezone' => 'UTC',
    ])->assertTooManyRequests();

    $this->actingAs($owner)->post(route('attachments.store', $entry), [
        'file' => UploadedFile::fake()->image('memory.jpg', 320, 200),
    ])->assertCreated();
    $this->actingAs($owner)->post(route('attachments.store', $entry), [
        'file' => UploadedFile::fake()->image('burst.jpg', 320, 200),
    ])->assertTooManyRequests();

    $this->actingAs($owner)->postJson(route('share-links.store', $entry), [])
        ->assertCreated();
    $this->actingAs($owner)->postJson(route('share-links.store', $entry), [])
        ->assertTooManyRequests();

    $this->actingAs($owner)->postJson(route('exports.store'), [
        'formats' => ['json'],
        'include_attachments' => false,
    ])->assertAccepted();
    $this->actingAs($owner)->postJson(route('exports.store'), [
        'formats' => ['json'],
        'include_attachments' => false,
    ])->assertTooManyRequests();

    $this->actingAs($owner)->postJson(
        route('app.publications.schedule', $publication),
        [],
    )->assertUnprocessable();
    $this->actingAs($owner)->postJson(
        route('app.publications.schedule', $publication),
        [],
    )->assertTooManyRequests();
});

test('private export downloads are throttled after a normal authorized download', function (): void {
    Storage::fake('local');
    installSingleAttemptLimiter('export-downloads');
    $owner = User::factory()->create();
    $export = Export::factory()->for($owner, 'owner')->ready()->create([
        'path' => 'exports/'.$owner->getKey().'/rate-limited.zip',
    ]);
    Storage::disk('local')->put($export->path, 'fictional zip bytes');

    $this->actingAs($owner)
        ->get(route('exports.download', $export))
        ->assertOk();
    $this->actingAs($owner)
        ->get(route('exports.download', $export))
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');
});

test('social oauth starts and callbacks have independent abuse budgets', function (): void {
    config(['memoria.social.driver' => 'real']);
    installSingleAttemptLimiter('social-oauth-starts');
    installSingleAttemptLimiter('social-oauth-callbacks');
    $owner = User::factory()->create();

    $this->actingAs($owner)->get(route('social.redirect', [
        'provider' => SocialProvider::Facebook->value,
    ]))->assertUnprocessable();
    $this->actingAs($owner)->get(route('social.redirect', [
        'provider' => SocialProvider::Facebook->value,
    ]))->assertTooManyRequests();

    $this->actingAs($owner)->get(route('social.callback', [
        'provider' => SocialProvider::Facebook->value,
    ]))->assertUnprocessable();
    $this->actingAs($owner)->get(route('social.callback', [
        'provider' => SocialProvider::Facebook->value,
    ]))->assertTooManyRequests();
});
