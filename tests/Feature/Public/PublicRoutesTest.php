<?php

use App\Enums\PublicationTargetStatus;
use App\Enums\PublicationTargetType;
use App\Enums\SocialProvider;
use App\Models\Publication;
use App\Models\PublicationMedia;
use App\Models\PublicationTarget;
use App\Models\User;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

afterEach(function (): void {
    Request::setTrustedHosts([]);
});

test('public profiles and feeds contain published snapshots but never drafts', function (): void {
    $owner = User::factory()->create();
    $owner->profile()->update([
        'username' => 'public-writer',
        'display_name' => 'Public Writer',
        'biography' => 'Fictional public biography.',
        'is_public' => true,
    ]);
    $published = Publication::factory()->for($owner, 'owner')->published()->create([
        'slug' => 'visible-story',
        'title' => 'Visible public story',
    ]);
    PublicationTarget::factory()->publishedWebsite($published)->create();
    Publication::factory()->for($owner, 'owner')->create([
        'slug' => 'private-draft',
        'title' => 'Secret publication draft',
    ]);

    $this->get(route('profiles.show', 'public-writer'))
        ->assertOk()
        ->assertSee('Visible public story')
        ->assertDontSee('Secret publication draft');

    $this->get(route('profiles.feed', 'public-writer'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8')
        ->assertSee('Visible public story')
        ->assertSee(route('publications.show', ['public-writer', $published->slug]), false)
        ->assertDontSee('Secret publication draft');
});

test('sitemap and robot directives honor per-publication indexing choices', function (): void {
    Cache::forget('memoria:sitemap:v4:index');
    Cache::forget('memoria:sitemap:v4:publications:5000:1');
    $owner = User::factory()->create();
    $owner->profile()->update(['username' => 'indexed-writer', 'is_public' => true]);
    $indexed = Publication::factory()->for($owner, 'owner')->published()->create([
        'slug' => 'indexed-story',
        'search_engine_indexing' => true,
    ]);
    $noIndex = Publication::factory()->for($owner, 'owner')->published()->create([
        'slug' => 'quiet-story',
        'title' => 'A public story kept out of search indexes',
        'excerpt' => 'Visible to deliberate profile and feed readers only.',
        'search_engine_indexing' => false,
    ]);
    PublicationTarget::factory()->publishedWebsite($indexed)->create();
    PublicationTarget::factory()->publishedWebsite($noIndex)->create();
    $draft = Publication::factory()->for($owner, 'owner')->create([
        'slug' => 'draft-story',
        'search_engine_indexing' => true,
    ]);

    $this->get(route('sitemap'))
        ->assertOk()
        ->assertSee(route('sitemaps.publications', ['page' => 1]), false);

    $this->get(route('sitemaps.publications', ['page' => 1]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee($indexed->slug)
        ->assertDontSee($noIndex->slug)
        ->assertDontSee($draft->slug);

    $this->get(route('publications.show', ['indexed-writer', $noIndex->slug]))
        ->assertOk()
        ->assertSee('content="noindex,nofollow,noarchive"', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

    $this->get(route('profiles.show', 'indexed-writer'))
        ->assertOk()
        ->assertSee('A public story kept out of search indexes')
        ->assertSee('content="noindex,nofollow,noarchive"', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

    $this->get(route('profiles.feed', 'indexed-writer'))
        ->assertOk()
        ->assertSee('A public story kept out of search indexes')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

    $this->get(route('robots'))
        ->assertOk()
        ->assertSee('Disallow: /');
});

test('sitemap indexes paginate every indexable publication and exclude noindex profiles', function (): void {
    config(['memoria.sitemap.maximum_urls' => 2]);
    Cache::forget('memoria:sitemap:v4:index');
    Cache::forget('memoria:sitemap:v4:static');
    Cache::forget('memoria:sitemap:v4:publications:2:1');
    Cache::forget('memoria:sitemap:v4:publications:2:2');

    $owner = User::factory()->create();
    $owner->profile()->update(['username' => 'paginated-writer', 'is_public' => true]);

    foreach (['first-indexed-story', 'second-indexed-story', 'third-indexed-story'] as $slug) {
        $publication = Publication::factory()->for($owner, 'owner')->published()->create([
            'slug' => $slug,
            'search_engine_indexing' => true,
        ]);
        PublicationTarget::factory()->publishedWebsite($publication)->create();
    }

    $profileUrl = route('profiles.show', 'paginated-writer');
    $this->get(route('sitemap'))
        ->assertOk()
        ->assertSee(route('sitemaps.static'), false)
        ->assertSee(route('sitemaps.publications', ['page' => 1]), false)
        ->assertSee(route('sitemaps.publications', ['page' => 2]), false)
        ->assertDontSee($profileUrl.'</loc>', false);

    $this->get(route('sitemaps.static'))
        ->assertOk()
        ->assertSee(route('home'), false)
        ->assertDontSee($profileUrl.'</loc>', false);

    $this->get(route('sitemaps.publications', ['page' => 1]))
        ->assertOk()
        ->assertSee('first-indexed-story')
        ->assertSee('second-indexed-story')
        ->assertDontSee('third-indexed-story');

    $this->get(route('sitemaps.publications', ['page' => 2]))
        ->assertOk()
        ->assertSee('third-indexed-story')
        ->assertDontSee('first-indexed-story');

    $this->get(route('sitemaps.publications', ['page' => 3]))->assertNotFound();
});

test('public media serves only approved media attached to a published snapshot', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $owner->profile()->update(['username' => 'media-writer', 'is_public' => true]);
    $published = Publication::factory()->for($owner, 'owner')->published()->create();
    PublicationTarget::factory()->publishedWebsite($published)->create();
    $draft = Publication::factory()->for($owner, 'owner')->create();
    $publicMedia = PublicationMedia::factory()->for($published)->create([
        'disk' => 'local',
        'path' => 'publications/published-image.jpg',
        'mime_type' => 'image/jpeg',
    ]);
    $draftMedia = PublicationMedia::factory()->for($draft)->create([
        'disk' => 'local',
        'path' => 'publications/draft-image.jpg',
        'mime_type' => 'image/jpeg',
    ]);
    Storage::disk('local')->put($publicMedia->path, 'fictional-image');
    Storage::disk('local')->put($draftMedia->path, 'fictional-image');

    $mediaResponse = $this->get(route('publications.media.show', $publicMedia))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    expect($mediaResponse->headers->get('Cache-Control'))
        ->toContain('public')
        ->toContain('no-cache')
        ->toContain('must-revalidate');
    expect($mediaResponse->headers->get('ETag'))->not->toBeNull();

    $this->get(route('publications.media.show', $draftMedia))->assertNotFound();
});

test('public health and baseline headers reveal no operational details', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    $this->get(route('health'))
        ->assertOk()
        ->assertExactJson(['status' => 'ok'])
        ->assertDontSee('Laravel')
        ->assertDontSee('database')
        ->assertDontSee('queue');
});

test('production rejects non-canonical hosts before they can poison the sitemap cache', function (): void {
    $canonicalHost = 'memoria.example.test';
    $cacheKey = 'memoria:sitemap:v4:static';

    config(['app.url' => "https://{$canonicalHost}"]);
    app()->detectEnvironment(fn (): string => 'production');
    Request::setTrustedHosts(array_filter(app(TrustHosts::class)->hosts()));

    try {
        Cache::forget($cacheKey);

        foreach (['attacker.example.test', "notes.{$canonicalHost}"] as $untrustedHost) {
            try {
                $this->withHeader('Host', $untrustedHost)
                    ->get('/sitemaps/static.xml')
                    ->assertBadRequest();
            } catch (SuspiciousOperationException $exception) {
                expect($exception->getMessage())->toContain('Untrusted Host');
            }

            expect(Cache::has($cacheKey))->toBeFalse();
        }

        $this->withHeader('Host', $canonicalHost)
            ->get('/sitemaps/static.xml')
            ->assertOk()
            ->assertSee($canonicalHost, false)
            ->assertDontSee('attacker.example.test', false);

        expect(Cache::get($cacheKey))
            ->toBeString()
            ->toContain($canonicalHost)
            ->not->toContain('attacker.example.test');
    } finally {
        Request::setTrustedHosts([]);
    }
});

test('forwarded https is honored only from an explicitly trusted reverse proxy', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeaders([
            'X-Forwarded-For' => '203.0.113.17',
            'X-Forwarded-Proto' => 'https',
        ])
        ->get(route('home'))
        ->assertOk()
        ->assertHeaderMissing('Strict-Transport-Security');

    config(['trustedproxy.proxies' => ['127.0.0.1']]);
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeaders([
            'X-Forwarded-For' => '203.0.113.17',
            'X-Forwarded-Proto' => 'https',
        ])
        ->get(route('home'))
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('a social-only publication is never exposed as a website publication', function (): void {
    Cache::forget('memoria:sitemap:v4:index');
    Storage::fake('local');
    $owner = User::factory()->create();
    $owner->profile()->update(['username' => 'social-only-writer', 'is_public' => true]);
    $publication = Publication::factory()->for($owner, 'owner')->published()->create([
        'slug' => 'social-network-only',
        'title' => 'Never a website article',
    ]);
    PublicationTarget::factory()
        ->for($publication)
        ->for($owner, 'owner')
        ->create([
            'target_key' => 'mastodon:fixture',
            'type' => PublicationTargetType::Social,
            'provider' => SocialProvider::Mastodon,
            'status' => PublicationTargetStatus::Published,
        ]);
    $medium = PublicationMedia::factory()->for($publication)->for($owner, 'owner')->create([
        'path' => 'publication-media/social-only.jpg',
    ]);
    Storage::disk('local')->put($medium->path, 'sanitized social-only bytes');

    expect($publication->isPubliclyVisible())->toBeFalse()
        ->and(Publication::query()->websitePublished()->whereKey($publication->getKey())->exists())->toBeFalse();

    $this->get(route('publications.show', ['social-only-writer', $publication->slug]))
        ->assertNotFound();
    $this->get(route('publications.media.show', $medium))->assertNotFound();
    $this->get(route('profiles.show', 'social-only-writer'))
        ->assertOk()
        ->assertDontSee('Never a website article');
    $this->get(route('profiles.feed', 'social-only-writer'))
        ->assertOk()
        ->assertDontSee('Never a website article');
    $this->get(route('sitemap'))
        ->assertOk()
        ->assertDontSee($publication->slug);
});

test('the sitemap is cached and independently throttled', function (): void {
    Cache::forget('memoria:sitemap:v4:publications:5000:1');
    RateLimiter::clear('sitemap:127.0.0.1');
    $owner = User::factory()->create();
    $owner->profile()->update(['username' => 'cached-sitemap-writer', 'is_public' => true]);
    $publication = Publication::factory()->for($owner, 'owner')->published()->create([
        'slug' => 'cached-sitemap-story',
        'search_engine_indexing' => true,
    ]);
    $target = PublicationTarget::factory()->publishedWebsite($publication)->create();

    $this->get(route('sitemaps.publications', ['page' => 1]))->assertOk()->assertSee($publication->slug);
    $target->delete();
    $this->get(route('sitemaps.publications', ['page' => 1]))->assertOk()->assertSee($publication->slug);

    for ($request = 3; $request <= 10; $request++) {
        $this->get(route('sitemap'))->assertOk();
    }

    $this->get(route('sitemap'))->assertTooManyRequests();
});
