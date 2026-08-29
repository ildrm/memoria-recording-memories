<?php

use App\Actions\CopyAttachmentToPublication;
use App\Actions\DeleteUserAccount;
use App\Actions\RemovePublicationMedia;
use App\Actions\StoreAttachment;
use App\Enums\PublicationStatus;
use App\Models\Entry;
use App\Models\Publication;
use App\Models\PublicationMedia;
use App\Models\PublicationTarget;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

test('publication images are dimension bounded and have optimized responsive derivatives', function (): void {
    Storage::fake('local');
    config()->set('memoria.public_images.publication_maximum_width', 1200);
    config()->set('memoria.public_images.publication_maximum_height', 1200);
    config()->set('memoria.public_images.publication_variant_widths', [
        'thumbnail' => 240,
        'medium' => 480,
        'large' => 800,
    ]);
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();
    $publication = Publication::factory()->for($owner, 'owner')->create();
    $attachment = app(StoreAttachment::class)->handle(
        UploadedFile::fake()->image('private-original.jpg', 1600, 1000),
        $entry,
        $owner,
    )->refresh();
    $privatePath = $attachment->path;

    $medium = app(CopyAttachmentToPublication::class)->handle(
        attachment: $attachment,
        publication: $publication,
        owner: $owner,
        altText: 'A location-free description of the memory',
        featured: true,
    );

    expect($medium->metadata_stripped)->toBeTrue()
        ->and($medium->metadata)->toMatchArray([
            'width' => 1200,
            'height' => 750,
            'encoder' => 'gd',
            'metadata_stripped' => true,
        ])
        ->and(array_keys($medium->metadata['variants']))
        ->toBe(['thumbnail', 'medium', 'large'])
        ->and(data_get($medium->metadata, 'variants.thumbnail.width'))->toBe(240)
        ->and(data_get($medium->metadata, 'variants.thumbnail.height'))->toBe(150)
        ->and(data_get($medium->metadata, 'variants.medium.width'))->toBe(480)
        ->and(data_get($medium->metadata, 'variants.medium.height'))->toBe(300)
        ->and(data_get($medium->metadata, 'variants.large.width'))->toBe(800)
        ->and(data_get($medium->metadata, 'variants.large.height'))->toBe(500)
        ->and($medium->storedImageFiles())->toHaveCount(4);

    foreach ($medium->responsiveImageVariants() as $variant) {
        Storage::disk($variant['disk'])->assertExists($variant['path']);
        $imageInfo = getimagesizefromstring(Storage::disk($variant['disk'])->get($variant['path']));

        expect($imageInfo)->toBeArray()
            ->and((int) $imageInfo[0])->toBe($variant['width'])
            ->and((int) $imageInfo[1])->toBe($variant['height'])
            ->and($variant['path'])->not->toBe($privatePath);
    }

    Storage::disk('local')->assertExists($privatePath);
});

test('public pages use responsive markup and guarded derivative delivery', function (): void {
    Storage::fake('local');
    config()->set('memoria.public_images.publication_maximum_width', 1200);
    config()->set('memoria.public_images.publication_maximum_height', 1200);
    config()->set('memoria.public_images.publication_variant_widths', [
        'thumbnail' => 240,
        'medium' => 480,
        'large' => 800,
    ]);
    $owner = User::factory()->create();
    $owner->profile()->update([
        'username' => 'responsive-writer',
        'is_public' => true,
    ]);
    $entry = Entry::factory()->for($owner, 'owner')->create();
    $publication = Publication::factory()->for($owner, 'owner')->create([
        'slug' => 'responsive-story',
        'title' => 'A responsive public story',
    ]);
    $attachment = app(StoreAttachment::class)->handle(
        UploadedFile::fake()->image('large-private-photo.jpg', 1600, 1000),
        $entry,
        $owner,
    )->refresh();
    $medium = app(CopyAttachmentToPublication::class)->handle(
        attachment: $attachment,
        publication: $publication,
        owner: $owner,
        altText: 'A calm responsive landscape',
        featured: true,
    );
    $publication->forceFill([
        'status' => PublicationStatus::Published,
        'published_at' => now()->subMinute(),
    ])->save();
    PublicationTarget::factory()->publishedWebsite($publication)->create();
    $thumbnail = $medium->imageVariant('thumbnail');
    $thumbnailUrl = route('publications.media.show', [
        'publicationMedia' => $medium,
        'variant' => 'thumbnail',
    ]);

    $this->get(route('publications.show', ['responsive-writer', 'responsive-story']))
        ->assertOk()
        ->assertSee('srcset="', false)
        ->assertSee($thumbnailUrl, false)
        ->assertSee(' 240w', false)
        ->assertSee('sizes="(min-width: 1280px) 68rem, calc(100vw - 2.5rem)"', false)
        ->assertSee('width="1200"', false)
        ->assertSee('height="750"', false)
        ->assertSee('loading="eager"', false)
        ->assertSee('decoding="async"', false);

    $this->get(route('profiles.show', 'responsive-writer'))
        ->assertOk()
        ->assertSee('loading="lazy"', false)
        ->assertSee('fetchpriority="low"', false);

    expect($thumbnail)->not->toBeNull();
    $thumbnailBytes = Storage::disk($thumbnail['disk'])->get($thumbnail['path']);
    $thumbnailResponse = $this->get($thumbnailUrl)
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertStreamedContent($thumbnailBytes);
    expect($thumbnailResponse->headers->get('Cache-Control'))
        ->toContain('public')
        ->toContain('no-cache')
        ->toContain('must-revalidate');
    $etag = $thumbnailResponse->headers->get('ETag');
    expect($etag)->not->toBeNull();
    $this->withHeader('If-None-Match', (string) $etag)
        ->get($thumbnailUrl)
        ->assertNotModified();

    $outsidePath = 'publication-media/another-owner/secret.jpg';
    Storage::disk('local')->put($outsidePath, $thumbnailBytes);
    $metadata = $medium->metadata;
    $metadata['variants']['thumbnail']['path'] = $outsidePath;
    $medium->forceFill(['metadata' => $metadata])->save();

    $this->get($thumbnailUrl)->assertNotFound();
    $this->get("/publication-media/{$medium->getKey()}/private")->assertNotFound();

    $publication->forceFill(['status' => PublicationStatus::Unpublished])->save();
    $this->withHeader('If-None-Match', (string) $etag)
        ->get(route('publications.media.show', [
            'publicationMedia' => $medium,
            'variant' => 'medium',
        ]))
        ->assertNotFound();
});

test('images beyond the configured pixel ceiling are rejected without public artifacts', function (): void {
    Storage::fake('local');
    config()->set('memoria.public_images.publication_maximum_source_pixels', 1_000_000);
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();
    $publication = Publication::factory()->for($owner, 'owner')->create();
    $attachment = app(StoreAttachment::class)->handle(
        UploadedFile::fake()->image('too-many-pixels.jpg', 1200, 1000),
        $entry,
        $owner,
    )->refresh();

    expect(fn () => app(CopyAttachmentToPublication::class)->handle(
        attachment: $attachment,
        publication: $publication,
        owner: $owner,
    ))->toThrow(ValidationException::class, 'The selected image dimensions are not supported.');

    expect(PublicationMedia::query()->whereBelongsTo($publication)->exists())->toBeFalse()
        ->and(Storage::disk('local')->allFiles("publication-media/{$owner->getKey()}/{$publication->getKey()}"))
        ->toBe([]);
    Storage::disk('local')->assertExists($attachment->path);
});

test('images that exceed the public byte budget are rejected without partial derivatives', function (): void {
    Storage::fake('local');
    config()->set('memoria.public_images.publication_maximum_kilobytes', 1);
    $owner = User::factory()->create();
    $entry = Entry::factory()->for($owner, 'owner')->create();
    $publication = Publication::factory()->for($owner, 'owner')->create();
    $attachment = app(StoreAttachment::class)->handle(
        UploadedFile::fake()->image('cannot-be-small-enough.jpg', 1200, 1000),
        $entry,
        $owner,
    )->refresh();

    expect(fn () => app(CopyAttachmentToPublication::class)->handle(
        attachment: $attachment,
        publication: $publication,
        owner: $owner,
    ))->toThrow(ValidationException::class, 'The selected image could not be optimized to a safe public size.');

    expect(PublicationMedia::query()->whereBelongsTo($publication)->exists())->toBeFalse()
        ->and(Storage::disk('local')->allFiles("publication-media/{$owner->getKey()}/{$publication->getKey()}"))
        ->toBe([]);
    Storage::disk('local')->assertExists($attachment->path);
});

test('removal and account deletion durably clean every responsive image file', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->create();
    $medium = publicationMediumWithStoredVariants($publication, $owner, 'removal');
    $removalFiles = $medium->storedImageFiles();

    app(RemovePublicationMedia::class)->handle($medium, $owner);

    foreach ($removalFiles as $file) {
        Storage::disk($file['disk'])->assertMissing($file['path']);
    }
    expect(DB::table('stored_file_deletions')
        ->where('reason', 'publication_media_removed')
        ->whereNotNull('completed_at')
        ->count())->toBe(4);

    $deletingOwner = User::factory()->create();
    $deletingPublication = Publication::factory()->for($deletingOwner, 'owner')->create();
    $accountMedium = publicationMediumWithStoredVariants($deletingPublication, $deletingOwner, 'account');
    $accountFiles = $accountMedium->storedImageFiles();

    app(DeleteUserAccount::class)->handle($deletingOwner);

    foreach ($accountFiles as $file) {
        Storage::disk($file['disk'])->assertMissing($file['path']);
    }
    expect(DB::table('stored_file_deletions')
        ->where('reason', 'account_deleted')
        ->whereNotNull('completed_at')
        ->count())->toBeGreaterThanOrEqual(4)
        ->and(User::query()->whereKey($deletingOwner->getKey())->exists())->toBeFalse();
});

function publicationMediumWithStoredVariants(
    Publication $publication,
    User $owner,
    string $suffix,
): PublicationMedia {
    $directory = "publication-media/{$owner->getKey()}/{$publication->getKey()}";
    $files = [
        'original' => ["{$directory}/{$suffix}-original.jpg", 1200, 750],
        'thumbnail' => ["{$directory}/{$suffix}-thumbnail.jpg", 240, 150],
        'medium' => ["{$directory}/{$suffix}-medium.jpg", 480, 300],
        'large' => ["{$directory}/{$suffix}-large.jpg", 800, 500],
    ];

    foreach ($files as [$path]) {
        Storage::disk('local')->put($path, 'sanitized fictional image bytes');
    }

    return PublicationMedia::factory()
        ->for($publication)
        ->for($owner, 'owner')
        ->create([
            'disk' => 'local',
            'path' => $files['original'][0],
            'mime_type' => 'image/jpeg',
            'size_bytes' => 34,
            'metadata_stripped' => true,
            'metadata' => [
                'width' => 1200,
                'height' => 750,
                'metadata_stripped' => true,
                'variants' => collect($files)
                    ->except('original')
                    ->map(fn (array $file): array => [
                        'path' => $file[0],
                        'mime_type' => 'image/jpeg',
                        'size_bytes' => 34,
                        'width' => $file[1],
                        'height' => $file[2],
                        'encoder' => 'gd',
                        'metadata_stripped' => true,
                    ])
                    ->all(),
            ],
        ]);
}
