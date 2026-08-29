<?php

use App\Models\Entry;
use App\Models\Publication;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

test('the private panel requires authentication and verified email', function (): void {
    $this->get('/app')->assertRedirect('/app/login');

    $unverified = User::factory()->unverified()->create();
    $this->actingAs($unverified->refresh())
        ->get('/app')
        ->assertRedirect('/app/email-verification/prompt');

    $verified = User::factory()->create();
    $response = $this->actingAs($verified->refresh())
        ->get('/app')
        ->assertOk()
        ->assertSee('Your memories')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Cache-Control'))
        ->toContain('private')
        ->toContain('no-store')
        ->toContain('max-age=0');
});

test('disabling an account revokes panel and custom private-route access immediately', function (): void {
    $user = User::factory()->create();
    $entry = Entry::factory()->for($user, 'owner')->create([
        'title' => 'Must remain unchanged',
    ]);
    $rememberToken = $user->getRememberToken();

    $this->actingAs($user);
    $user->disable();

    expect($user->getRememberToken())->not->toBe($rememberToken);
    $this->putJson(route('entries.update', $entry), [
        'title' => 'A disabled session tried to change this',
        'revision' => $entry->revision,
    ])->assertForbidden();
    $this->assertGuest();
    expect($entry->refresh()->title)->toBe('Must remain unchanged');

    $this->actingAs($user->refresh())->get('/app')->assertForbidden();

    $publication = Publication::factory()->for($user, 'owner')->create();
    $privateRouteRequests = [
        fn () => $this->postJson(route('attachments.store', $entry), []),
        fn () => $this->postJson(route('exports.store'), []),
        fn () => $this->postJson(route('app.publications.publish', $publication), []),
        fn () => $this->postJson(route('share-links.store', $entry), []),
        fn () => $this->getJson(route('social.redirect', 'mastodon')),
        fn () => $this->deleteJson(route('account.destroy'), []),
    ];

    foreach ($privateRouteRequests as $privateRouteRequest) {
        $this->actingAs($user->refresh());
        $privateRouteRequest()->assertForbidden();
    }
});

test('both panels expose email verification strict policies and totp', function (): void {
    $appPanel = Filament::getPanel('app');
    $adminPanel = Filament::getPanel('admin');

    expect($appPanel->isAuthorizationStrict())->toBeTrue()
        ->and($adminPanel->isAuthorizationStrict())->toBeTrue()
        ->and($appPanel->getMultiFactorAuthenticationProviders())->toHaveKey('app')
        ->and($adminPanel->getMultiFactorAuthenticationProviders())->toHaveKey('app')
        ->and(route('filament.app.auth.email-verification.prompt'))->toEndWith('/app/email-verification/prompt')
        ->and(route('filament.admin.auth.email-verification.prompt'))->toEndWith('/admin/email-verification/prompt');

    $this->get('/app/register')->assertOk();
    $this->get('/admin/register')->assertNotFound();
});

test('password and totp recovery material are encrypted or hashed at rest', function (): void {
    $user = User::factory()->create(['password' => 'fictional-long-password']);
    $user->forceFill([
        'app_authentication_secret' => 'fictional-totp-secret',
        'app_authentication_recovery_codes' => ['fictional-recovery-code'],
    ])->save();
    $raw = DB::table('users')->where('id', $user->getKey())->first();

    expect(Hash::check('fictional-long-password', $raw->password))->toBeTrue()
        ->and($raw->app_authentication_secret)->not->toBe('fictional-totp-secret')
        ->and($raw->app_authentication_recovery_codes)->not->toContain('fictional-recovery-code')
        ->and($user->toArray())->not->toHaveKeys([
            'password',
            'app_authentication_secret',
            'app_authentication_recovery_codes',
        ]);
});
