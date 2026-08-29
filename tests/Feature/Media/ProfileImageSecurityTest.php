<?php

use App\Actions\RemovePublicProfileImage;
use App\Actions\UpdatePublicProfileImage;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('public profile images are owner-authorized sanitized copies with randomized paths', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $profile = $owner->profile()->firstOrFail();
    $profile->update(['username' => 'safe-profile-image', 'is_public' => true]);
    $upload = UploadedFile::fake()->image('home-with-gps-metadata.jpg', 800, 600);

    expect(fn () => app(UpdatePublicProfileImage::class)->handle(
        $upload,
        $profile,
        $attacker,
        'avatar',
    ))->toThrow(AuthorizationException::class);

    $profile = app(UpdatePublicProfileImage::class)->handle(
        UploadedFile::fake()->image('home-with-gps-metadata.jpg', 800, 600),
        $profile,
        $owner,
        'avatar',
    );

    expect($profile->avatar_path)->toStartWith("profile-images/{$owner->getKey()}/avatar/")
        ->and($profile->avatar_disk)->toBe('local')
        ->and($profile->avatar_path)->not->toContain('home-with-gps-metadata')
        ->and(AuditEvent::query()
            ->where('event', 'profile.avatar_image.updated')
            ->where('actor_user_id', $owner->getKey())
            ->where('auditable_id', $profile->getKey())
            ->firstOrFail()
            ->metadata['metadata_stripped'])->toBeTrue();
    $avatarPath = $profile->avatar_path;
    Storage::disk('local')->assertExists($avatarPath);
    Storage::disk('public')->assertMissing($avatarPath);
    $this->get('/storage/'.$avatarPath)->assertForbidden();
    $imageResponse = $this->get(route('profiles.images.show', [
        'username' => $profile->username,
        'kind' => 'avatar',
    ]))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    expect($imageResponse->headers->get('Cache-Control'))
        ->toContain('no-store')
        ->toContain('max-age=0');

    $profile = app(RemovePublicProfileImage::class)->handle($profile, $owner, 'avatar');

    expect($profile->avatar_path)->toBeNull()
        ->and($profile->avatar_disk)->toBeNull();
    Storage::disk('local')->assertMissing($avatarPath);
    $this->get(route('profiles.images.show', [
        'username' => $profile->username,
        'kind' => 'avatar',
    ]))->assertNotFound();
});
