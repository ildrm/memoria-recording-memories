<?php

use App\Actions\CreateShareLink;
use App\Actions\RevokeShareLink;
use App\Models\Entry;
use App\Models\User;
use App\Services\ShareLinkResolver;
use App\Services\ShareLinks\InvalidShareLink;
use App\Services\ShareLinks\InvalidSharePassword;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('unlisted links store only a token hash and resolve the intended private copy', function (): void {
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create([
        'title' => 'For one trusted reader',
        'body' => '<p>Shared intentionally, never discoverable.</p>',
    ]);

    $created = app(CreateShareLink::class)->handle(
        entry: $entry,
        owner: $owner,
        label: 'Family link',
        expiresAt: now()->addHour(),
        maxViews: 2,
        trackViews: true,
    );
    $raw = DB::table('share_links')->where('id', $created->shareLink->getKey())->first();

    expect($created->token)->toHaveLength(43)
        ->and($raw->token_hash)->toBe(hash('sha256', $created->token))
        ->and($raw->token_hash)->not->toContain($created->token)
        ->and($created->url)->toBe(route('shares.show', ['token' => $created->token]));

    $resolved = app(ShareLinkResolver::class)->resolve($created->token);

    expect($resolved->entry->is($entry))->toBeTrue()
        ->and($resolved->view_count)->toBe(1);

    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee($created->token);
});

test('password expiry view limits and revocation stop access', function (): void {
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();
    $created = app(CreateShareLink::class)->handle(
        entry: $entry,
        owner: $owner,
        password: 'long-fictional-passphrase',
        expiresAt: now()->addHour(),
        maxViews: 1,
    );

    expect(Hash::check('long-fictional-passphrase', $created->shareLink->password_hash))->toBeTrue();
    expect(fn () => app(ShareLinkResolver::class)->resolve($created->token, 'wrong-password'))
        ->toThrow(InvalidSharePassword::class);

    app(ShareLinkResolver::class)->resolve($created->token, 'long-fictional-passphrase');

    expect(fn () => app(ShareLinkResolver::class)->resolve(
        $created->token,
        'long-fictional-passphrase',
    ))->toThrow(InvalidShareLink::class);

    $expiring = app(CreateShareLink::class)->handle(
        entry: $entry,
        owner: $owner,
        expiresAt: now()->addMinute(),
    );
    $expiring->shareLink->forceFill(['expires_at' => now()->subSecond()])->save();

    expect(fn () => app(ShareLinkResolver::class)->resolve($expiring->token))
        ->toThrow(InvalidShareLink::class);

    $revoked = app(CreateShareLink::class)->handle($entry, $owner);
    app(RevokeShareLink::class)->handle($revoked->shareLink, $owner);

    expect(fn () => app(ShareLinkResolver::class)->resolve($revoked->token))
        ->toThrow(InvalidShareLink::class);
});

test('a shared response is noindex and never becomes a public publication', function (): void {
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create([
        'title' => 'Unlisted memory title',
    ]);
    $created = app(CreateShareLink::class)->handle($entry, $owner);

    $response = $this->get(route('shares.show', ['token' => $created->token]))
        ->assertOk()
        ->assertSee('Unlisted memory title')
        ->assertHeader('Pragma', 'no-cache')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    expect($response->headers->get('Cache-Control'))
        ->toContain('private')
        ->toContain('no-store')
        ->toContain('max-age=0');

    expect($entry->publications()->exists())->toBeFalse();
});

test('the HTTP workflow defaults only an omitted share expiration', function (): void {
    config()->set('memoria.shares.default_expiration_days', 14);
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();

    $defaulted = $this->actingAs($owner)
        ->postJson(route('share-links.store', $entry), [])
        ->assertCreated();
    $defaultedExpiration = $defaulted->json('data.expires_at');

    expect($defaultedExpiration)->not->toBeNull()
        ->and(now()->diffInDays($defaultedExpiration))->toBeBetween(13.9, 14.1);

    $deliberatelyPermanent = $this->actingAs($owner)
        ->postJson(route('share-links.store', $entry), ['expires_at' => null])
        ->assertCreated();

    expect($deliberatelyPermanent->json('data.expires_at'))->toBeNull();
});

test('private link creation rejects past and over maximum expirations', function (): void {
    CarbonImmutable::setTestNow('2026-08-29 12:00:00 UTC');
    config()->set('memoria.shares.maximum_expiration_days', 30);
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();

    expect(fn () => app(CreateShareLink::class)->handle(
        entry: $entry,
        owner: $owner,
        expiresAt: CarbonImmutable::now()->subSecond(),
    ))->toThrow(ValidationException::class)
        ->and(fn () => app(CreateShareLink::class)->handle(
            entry: $entry,
            owner: $owner,
            expiresAt: CarbonImmutable::now()->addDays(30)->addSecond(),
        ))->toThrow(ValidationException::class)
        ->and($entry->shareLinks()->count())->toBe(0);
});
