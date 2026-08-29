<?php

use App\Actions\ConfirmPublicationPrivacyReview;
use App\Contracts\AttachmentScanner;
use App\Enums\AttachmentMediaType;
use App\Enums\AttachmentScanStatus;
use App\Enums\ExportStatus;
use App\Enums\PublicationStatus;
use App\Enums\PublicationTargetStatus;
use App\Enums\PublicationTargetType;
use App\Models\Attachment;
use App\Models\Entry;
use App\Models\Export;
use App\Models\Journal;
use App\Models\Person;
use App\Models\Publication;
use App\Models\PublicationTarget;
use App\Models\Report;
use App\Models\ShareLink;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Playwright\Client;
use Symfony\Component\HttpFoundation\Response;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class ParsesPestBrowserMultipartUploads
{
    public function handle(Request $request, Closure $next): Response
    {
        $temporaryPaths = [];

        if ($this->shouldParse($request)) {
            $temporaryPaths = $this->populateUploadedFiles($request);
        }

        try {
            return $next($request);
        } finally {
            foreach ($temporaryPaths as $temporaryPath) {
                @unlink($temporaryPath);
            }
        }
    }

    private function shouldParse(Request $request): bool
    {
        return $request->isMethod('POST')
            && str_ends_with($request->path(), '/upload-file')
            && $request->files->count() === 0
            && str_starts_with(mb_strtolower((string) $request->header('content-type')), 'multipart/form-data');
    }

    /**
     * Pest Browser's in-process HTTP server currently forwards the multipart body but leaves
     * Symfony's file bag empty. Rehydrate that bag so the real Livewire upload endpoint runs.
     *
     * @return list<string>
     */
    private function populateUploadedFiles(Request $request): array
    {
        $contentType = (string) $request->header('content-type');
        preg_match('/boundary=(?:"([^"]+)"|([^;]+))/i', $contentType, $boundaryMatch);
        $boundary = trim((string) (($boundaryMatch[1] ?? '') ?: ($boundaryMatch[2] ?? '')));

        if ($boundary === '') {
            return [];
        }

        $uploadedFiles = [];
        $temporaryPaths = [];

        foreach (explode('--'.$boundary, $request->getContent()) as $part) {
            $part = ltrim($part, "\r\n");

            if ($part === '' || str_starts_with($part, '--')) {
                continue;
            }

            $sections = explode("\r\n\r\n", $part, 2);

            if (count($sections) !== 2) {
                continue;
            }

            [$headers, $contents] = $sections;
            preg_match('/content-disposition:\s*form-data;\s*name="([^"]+)"(?:;\s*filename="([^"]*)")?/i', $headers, $dispositionMatch);
            $fieldName = mb_rtrim((string) ($dispositionMatch[1] ?? ''), '[]');
            $fileName = (string) ($dispositionMatch[2] ?? '');

            if ($fieldName === '' || $fileName === '') {
                continue;
            }

            preg_match('/content-type:\s*([^\r\n]+)/i', $headers, $mimeTypeMatch);
            $mimeType = trim((string) ($mimeTypeMatch[1] ?? 'application/octet-stream'));
            $contents = str_ends_with($contents, "\r\n") ? substr($contents, 0, -2) : $contents;
            $temporaryPath = tempnam(sys_get_temp_dir(), 'memoria-browser-upload-');

            if ($temporaryPath === false) {
                continue;
            }

            file_put_contents($temporaryPath, $contents);
            $temporaryPaths[] = $temporaryPath;
            $uploadedFiles[$fieldName][] = new UploadedFile(
                $temporaryPath,
                basename($fileName),
                $mimeType,
                UPLOAD_ERR_OK,
                true,
            );
        }

        foreach ($uploadedFiles as $fieldName => $files) {
            $request->files->set($fieldName, $files);
            $request->request->set($fieldName, $files);
        }

        return $temporaryPaths;
    }
}

function assertAcceptanceSurfaceIsHealthy(AwaitableWebpage $page): AwaitableWebpage
{
    return $page
        ->wait(0.4)
        ->assertNoSmoke()
        ->assertNoAccessibilityIssues()
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth');
}

function attachBrowserFile(
    AwaitableWebpage $page,
    string $selector,
    string $name,
    string $mimeType,
    string $base64Contents,
): void {
    $locator = $page->page()->locator($selector);

    $response = Client::instance()->execute($locator->page(), 'setInputFiles', [
        'selector' => $locator->selector(),
        'strict' => true,
        'payloads' => [[
            'name' => $name,
            'mimeType' => $mimeType,
            'buffer' => $base64Contents,
        ]],
    ]);

    foreach ($response as $_response) {
        // Consuming the protocol response completes the native Playwright action.
    }
}

test('the public experience has no smoke console or accessibility failures', function (): void {
    $this->withVite();

    $owner = User::factory()->create();
    $owner->profile()->update([
        'username' => 'browser-writer',
        'display_name' => 'Browser Writer',
        'is_public' => true,
    ]);
    $publication = Publication::factory()->for($owner, 'owner')->published()->create([
        'slug' => 'browser-story',
        'title' => 'A deliberately public story',
        'body' => '<p>This fictional story is safe for a browser release check.</p>',
    ]);
    PublicationTarget::factory()->publishedWebsite($publication)->create();

    $pages = visit([
        '/',
        '/@browser-writer/browser-story',
        '/privacy',
        '/terms',
    ]);

    $pages
        ->assertNoSmoke()
        ->assertNoConsoleLogs()
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth');

    [$home, $story, $privacy, $terms] = $pages;
    $home->assertSee('Your life, kept')->assertSeeLink('Start your private journal');
    $story->assertSee('A deliberately public story')->assertSee('Browser Writer');
    $privacy->assertSee('Privacy notice template');
    $terms->assertSee('Terms of service template');
});

test('the landing page remains healthy on a dark mobile viewport', function (): void {
    $this->withVite();

    visit('/')
        ->on()
        ->mobile()
        ->inDarkMode()
        ->assertSee('Your life, kept')
        ->assertNoSmoke()
        ->assertNoConsoleLogs()
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth');
});

test('a verified account can edit and find private memories before previewing a publication', function (): void {
    $this->withVite();

    $password = 'correct-horse-battery-staple';
    $user = User::factory()->create([
        'name' => 'Avery Rowan',
        'email' => 'browser-member@example.test',
        'password' => $password,
        'email_verified_at' => now(),
    ]);
    $user->preferences()->update(['timezone' => 'UTC']);

    $memory = Entry::factory()->for($user, 'owner')->favorite()->create([
        'title' => 'Lantern walk by the lake',
        'body' => '<p>A fictional evening walk beneath paper lanterns.</p>',
        'occurred_at' => now()->subDay()->setTime(18, 30),
        'timezone' => 'UTC',
        'location_name' => 'Cedar Lake',
        'importance' => 1,
    ]);
    Entry::factory()->for($user, 'owner')->create([
        'title' => 'Comet observatory notes',
        'body' => '<p>A separate fictional memory used to prove search filtering.</p>',
        'occurred_at' => now()->subDays(2)->setTime(21, 15),
        'timezone' => 'UTC',
    ]);
    $publication = Publication::factory()->fromEntry($memory)->create([
        'title' => 'Lanterns over a fictional lake',
        'slug' => 'lanterns-over-a-fictional-lake',
        'excerpt' => 'A deliberately fictional public-copy preview.',
        'body' => '<p>This separate public version contains no private source details.</p>',
        'topics' => ['fiction', 'reflection'],
    ]);
    app(ConfirmPublicationPrivacyReview::class)->handle($publication, $user);

    $updatedTitle = 'Lantern walk, revisited';
    $month = now()->format('Y-m');

    $page = visit('/app/login')
        ->assertSee('Sign in')
        ->assertPresent('input[type="email"]')
        ->assertPresent('input[type="password"]')
        ->fill('input[type="email"]', $user->email)
        ->fill('input[type="password"]', $password)
        ->assertValue('input[type="email"]', $user->email)
        ->click('button[type="submit"]')
        ->assertPathIs('/app')
        ->assertSee('Your memories');

    assertAcceptanceSurfaceIsHealthy($page);

    $page
        ->navigate("/app/entries/{$memory->getKey()}/edit")
        ->assertPathIs("/app/entries/{$memory->getKey()}/edit")
        ->assertSee('Write your memory')
        ->assertSee('Saved privately · Only me')
        ->assertPresent('[placeholder="Give this memory a title"]')
        ->fill('[placeholder="Give this memory a title"]', $updatedTitle)
        ->keys('[placeholder="Give this memory a title"]', 'Tab')
        ->wait(2)
        ->assertValue('[placeholder="Give this memory a title"]', $updatedTitle)
        ->assertSee('Saved privately · Only me');

    assertAcceptanceSurfaceIsHealthy($page);

    expect($memory->refresh()->title)->toBe($updatedTitle);

    $page
        ->navigate('/app/timeline')
        ->assertPathIs('/app/timeline')
        ->assertSee($updatedTitle)
        ->assertSee('Only me');

    assertAcceptanceSurfaceIsHealthy($page);

    $page
        ->navigate("/app/calendar?month={$month}")
        ->assertPathIs('/app/calendar')
        ->assertSee('Private calendar')
        ->assertSee($updatedTitle);

    assertAcceptanceSurfaceIsHealthy($page);

    $page
        ->navigate('/app/search')
        ->assertPathIs('/app/search')
        ->assertSee('Words to find')
        ->fill('#memory-search', 'Lantern walk')
        ->wait(1)
        ->assertSee($updatedTitle)
        ->assertDontSee('Comet observatory notes')
        ->assertSee('Active filters:');

    assertAcceptanceSurfaceIsHealthy($page);

    $page
        ->navigate("/app/publications/{$publication->getKey()}/edit")
        ->assertPathIs("/app/publications/{$publication->getKey()}/edit")
        ->assertSee('Public version')
        ->assertSee('Privacy review')
        ->assertSee('Preview public page')
        ->assertValue('input[disabled]', 'Completed');

    assertAcceptanceSurfaceIsHealthy($page);

    $page
        ->navigate("/app/publications/{$publication->getKey()}/preview")
        ->assertPathIs("/app/publications/{$publication->getKey()}/preview")
        ->assertSee('Private preview — not published')
        ->assertSee('Confirm I inspected this exact preview')
        ->assertSee('Lanterns over a fictional lake')
        ->assertSee('This separate public version contains no private source details.')
        ->press('Confirm I inspected this exact preview')
        ->assertPathIs("/app/publications/{$publication->getKey()}/preview")
        ->assertSee('Exact preview confirmed — not published');

    assertAcceptanceSurfaceIsHealthy($page)->assertNoMissingImages();

    $page
        ->navigate('/app/settings')
        ->assertPathIs('/app/settings')
        ->assertSee('Privacy defaults')
        ->assertSee('Active browser sessions')
        ->assertSee('Save settings');

    assertAcceptanceSurfaceIsHealthy($page);
});

test('the private diary remains usable without horizontal overflow on a dark mobile viewport', function (): void {
    $this->withVite();

    $password = 'mobile-browser-password';
    $user = User::factory()->create([
        'name' => 'Morgan Vale',
        'email' => 'mobile-browser-member@example.test',
        'password' => $password,
        'email_verified_at' => now(),
    ]);
    $user->preferences()->update(['timezone' => 'UTC']);
    Entry::factory()->for($user, 'owner')->create([
        'title' => 'Pocket-sized stargazing note',
        'body' => '<p>A fictional note for the mobile calendar.</p>',
        'occurred_at' => now()->subDay()->setTime(20, 0),
        'timezone' => 'UTC',
    ]);

    $month = now()->format('Y-m');

    $page = visit('/app/login')
        ->on()
        ->mobile()
        ->inDarkMode()
        ->fill('input[type="email"]', $user->email)
        ->fill('input[type="password"]', $password)
        ->click('button[type="submit"]')
        ->assertPathIs('/app')
        ->assertSee('Your memories')
        ->assertScript("window.matchMedia('(prefers-color-scheme: dark)').matches");

    assertAcceptanceSurfaceIsHealthy($page);

    $page
        ->navigate('/app/timeline')
        ->assertSee('Pocket-sized stargazing note')
        ->assertSee('Only me');

    assertAcceptanceSurfaceIsHealthy($page);

    $page
        ->navigate("/app/calendar?month={$month}")
        ->assertSee('Private calendar')
        ->assertSee('Pocket-sized stargazing note');

    assertAcceptanceSurfaceIsHealthy($page);

    $page
        ->navigate('/app/settings')
        ->assertSee('Language, time & appearance')
        ->assertSee('Account & security');

    assertAcceptanceSurfaceIsHealthy($page);
});

test('private collection indexes and editor controls remain complete on mobile', function (): void {
    $this->withVite();

    $password = 'mobile-collection-browser-password';
    $user = User::factory()->create([
        'name' => 'Taylor Moss',
        'email' => 'mobile-collections@example.test',
        'password' => $password,
        'email_verified_at' => now(),
    ]);
    $journal = Journal::factory()->for($user, 'owner')->create(['name' => 'Window notes']);
    $entry = Entry::factory()->forJournal($journal)->create(['title' => 'Rain against the glass']);
    $publication = Publication::factory()->fromEntry($entry)->create(['title' => 'A public-safe rain story']);

    $page = visit('/app/login')
        ->on()
        ->mobile()
        ->fill('input[type="email"]', $user->email)
        ->fill('input[type="password"]', $password)
        ->click('button[type="submit"]')
        ->assertPathIs('/app')
        ->navigate('/app/entries')
        ->assertSee($entry->title)
        ->assertSee($journal->name);

    assertAcceptanceSurfaceIsHealthy($page);

    $page
        ->navigate('/app/publications')
        ->assertSee($publication->title)
        ->assertSee('Separate version of a private memory');

    assertAcceptanceSurfaceIsHealthy($page);

    $page
        ->navigate("/app/entries/{$entry->getKey()}/edit")
        ->assertSee('Saved privately · Only me')
        ->assertSee('Private files')
        ->assertSee('Registered access')
        ->assertSee('Version history')
        ->assertSee('No private files')
        ->assertScript("document.querySelector('.memoria-private-banner').getBoundingClientRect().width > 260")
        ->assertScript("Array.from(document.querySelectorAll('.fi-sc-tabs .fi-tabs-item')).every((item) => { const box = item.getBoundingClientRect(); return box.left >= 0 && box.right <= document.documentElement.clientWidth })");

    assertAcceptanceSurfaceIsHealthy($page);
});

test('privacy review is an accessible exact-version gate before preview confirmation', function (): void {
    $this->withVite();

    $password = 'review-gate-browser-password';
    $user = User::factory()->create([
        'name' => 'Jordan Reed',
        'email' => 'review-gate@example.test',
        'password' => $password,
        'email_verified_at' => now(),
    ]);
    $publication = Publication::factory()->for($user, 'owner')->create([
        'title' => 'A carefully edited public version',
        'body' => '<p>Contact editor@example.test before visiting Orchard Street.</p>',
        'revision' => 5,
        'topics' => ['reflection'],
    ]);

    $page = visit('/app/login')
        ->on()
        ->mobile()
        ->fill('input[type="email"]', $user->email)
        ->fill('input[type="password"]', $password)
        ->click('button[type="submit"]')
        ->assertPathIs('/app')
        ->navigate("/app/publications/{$publication->getKey()}/privacy-review")
        ->assertSee('Privacy gate · Step 1 of 2')
        ->assertSee('Exact public revision 5')
        ->assertSee('Review proof')
        ->assertSee('The public draft may contain an email address.')
        ->assertSee('Public images in this proof')
        ->assertSee('No public images are selected')
        ->assertSee('Back to edit')
        ->assertSee('Confirm review and open exact preview')
        ->assertScript("document.querySelector('.fi-modal-window').contains(document.activeElement)")
        ->assertScript("Array.from(document.querySelectorAll('.memoria-review-warning-list .fi-icon')).every((icon) => icon.getBoundingClientRect().width <= 24)")
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth');

    assertAcceptanceSurfaceIsHealthy($page);

    $page
        ->click('Confirm review and open exact preview')
        ->assertPathIs("/app/publications/{$publication->getKey()}/preview")
        ->assertSee('Private preview — not published')
        ->assertSee($publication->title)
        ->assertSee('Confirm I inspected this exact preview');

    assertAcceptanceSurfaceIsHealthy($page);

    $page
        ->click('Confirm I inspected this exact preview')
        ->assertPathIs("/app/publications/{$publication->getKey()}/preview")
        ->assertSee('Exact preview confirmed — not published');

    assertAcceptanceSurfaceIsHealthy($page);
});

test('a super administrator can inspect operational screens without seeing private diary content', function (): void {
    $this->withVite();

    $password = 'admin-browser-password';
    $administrator = User::factory()->superAdministrator()->create([
        'name' => 'Rowan Operator',
        'email' => 'browser-admin@example.test',
        'password' => $password,
        'email_verified_at' => now(),
    ]);
    $member = User::factory()->create([
        'name' => 'Fictional Member',
        'email' => 'fictional-member@example.test',
    ]);
    Entry::factory()->for($member, 'owner')->create([
        'title' => 'PRIVATE SENTINEL — never render in administration',
        'body' => '<p>Private test-only diary text.</p>',
        'timezone' => 'UTC',
    ]);

    $page = visit('/admin/login')
        ->fill('input[type="email"]', $administrator->email)
        ->fill('input[type="password"]', $password)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin')
        ->assertSee('Operations overview')
        ->assertSee('Public operations')
        ->assertDontSee('PRIVATE SENTINEL — never render in administration');

    assertAcceptanceSurfaceIsHealthy($page);

    $page
        ->navigate('/admin/users')
        ->assertPathIs('/admin/users')
        ->assertSee('Fictional Member')
        ->assertSee('fictional-member@example.test')
        ->assertDontSee('PRIVATE SENTINEL — never render in administration');

    assertAcceptanceSurfaceIsHealthy($page);

    $page
        ->navigate('/admin/system-health')
        ->assertPathIs('/admin/system-health')
        ->assertSee('A privacy-safe operational snapshot')
        ->assertSee('Run checks again')
        ->assertSee('What this page does not prove')
        ->assertDontSee('PRIVATE SENTINEL — never render in administration')
        ->click('Run checks again')
        ->wait(1)
        ->assertSee('A privacy-safe operational snapshot');

    assertAcceptanceSurfaceIsHealthy($page);
});

test('moderation reports remain complete and actionable on a mobile viewport', function (): void {
    $this->withVite();

    $password = 'mobile-admin-browser-password';
    $administrator = User::factory()->superAdministrator()->create([
        'name' => 'Mobile Operator',
        'email' => 'mobile-browser-admin@example.test',
        'password' => $password,
        'email_verified_at' => now(),
    ]);
    Report::factory()->create([
        'reason' => 'privacy',
        'details' => 'A fictional public-content report for responsive verification.',
    ]);

    $page = visit('/admin/login')
        ->on()
        ->mobile()
        ->fill('input[type="email"]', $administrator->email)
        ->fill('input[type="password"]', $password)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin')
        ->navigate('/admin/reports')
        ->assertPathIs('/admin/reports')
        ->assertSee('Reason')
        ->assertSee('Status')
        ->assertSee('Assigned to')
        ->assertSee('Reported')
        ->assertSee('Review')
        ->assertScript("document.querySelector('.fi-ta-table-stacked-on-mobile') !== null")
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth');

    assertAcceptanceSurfaceIsHealthy($page);
});

test('a member can create a private memory and deliberately publish a separate website story', function (): void {
    $this->withVite();

    app(HttpKernel::class)->prependMiddleware(ParsesPestBrowserMultipartUploads::class);
    Storage::fake('tmp-for-tests');

    config()->set('memoria.attachments.scanner.driver', 'fake');
    app()->forgetInstance(AttachmentScanner::class);

    $password = 'browser-journey-password';
    $user = User::factory()->create([
        'name' => 'Casey Journey',
        'email' => 'browser-journey@example.test',
        'password' => $password,
        'email_verified_at' => now(),
    ]);
    $user->preferences()->update(['timezone' => 'UTC']);
    $user->profile()->update([
        'username' => 'browser-journey-writer',
        'display_name' => 'Casey Journey',
        'is_public' => true,
    ]);

    $tag = Tag::factory()->for($user, 'owner')->create(['name' => 'private-journey-tag']);
    $person = Person::factory()->for($user, 'owner')->create(['display_name' => 'Private Companion']);

    $journalName = 'Browser journey journal';
    $privateTitle = 'Private cliffside memory';
    $privateBody = 'The private source mentions a blue gate and a personal promise.';
    $publicTitle = 'A fictional sunrise above the sea';
    $publicSlug = 'fictional-sunrise-above-the-sea';
    $publicExcerpt = 'A deliberately separate, public-safe version of a fictional morning.';
    $publicBody = 'The fictional horizon brightened while the quiet sea reflected the sunrise.';
    $imageName = 'memoria-browser-journey.png';

    $page = visit('/app/login')
        ->fill('input[type="email"]', $user->email)
        ->fill('input[type="password"]', $password)
        ->click('button[type="submit"]')
        ->assertPathIs('/app')
        ->navigate('/app/journals/create')
        ->assertSee('Journal details')
        ->fill('[placeholder="Travel, family, everyday life…"]', $journalName)
        ->fill('[wire\\:model="data.slug"]', 'browser-journey-journal')
        ->fill('[placeholder="What belongs in this journal?"]', 'A private journal created through the browser journey.')
        ->click('button[wire\\:target="create"]')
        ->wait(1)
        ->assertPathBeginsWith('/app/journals/')
        ->assertPathEndsWith('/edit');

    $journal = Journal::query()
        ->whereBelongsTo($user, 'owner')
        ->where('name', $journalName)
        ->sole();

    $page
        ->navigate('/app/entries/create')
        ->assertSee('Write your memory');

    $page
        ->fill('[placeholder="Give this memory a title"]', $privateTitle)
        ->click('button[wire\\:target="create"]')
        ->wait(1);

    $entry = Entry::query()
        ->whereBelongsTo($user, 'owner')
        ->where('title', $privateTitle)
        ->sole();

    $page
        ->assertPathBeginsWith('/app/entries/')
        ->assertPathEndsWith('/edit');

    $page
        ->fill('.fi-fo-rich-editor [contenteditable="true"]', $privateBody)
        ->click('[id="form.journal_id"]')
        ->click($journalName)
        ->click('[id="form.tags"]')
        ->click($tag->name)
        ->click('[id="form.tags"]')
        ->click('[id="form.people"]')
        ->click($person->display_name)
        ->click('[id="form.people"]')
        ->press('Save changes')
        ->wait(2)
        ->assertSee('Saved privately · Only me')
        ->assertSee($journalName)
        ->assertSee($tag->name)
        ->assertSee($person->display_name);

    $entry->refresh();
    expect($entry->journal_id)->toBe($journal->getKey())
        ->and(strip_tags((string) $entry->body))->toContain($privateBody)
        ->and($entry->tags()->pluck('tags.id')->all())->toBe([$tag->getKey()])
        ->and($entry->people()->pluck('people.id')->all())->toBe([$person->getKey()]);

    $page
        ->click('Add private file')
        ->assertSee('Stored privately');

    attachBrowserFile(
        $page,
        '.fi-modal-window input[type="file"]',
        $imageName,
        'image/png',
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
    );

    $page
        ->wait(2)
        ->assertSee($imageName)
        ->assertEnabled('.fi-modal-window button[wire\\:target="callMountedAction"]');

    $temporaryUploadPath = collect(Storage::disk('tmp-for-tests')->files('livewire-tmp'))
        ->first(fn (string $path): bool => ! str_ends_with($path, '.json'));
    expect($temporaryUploadPath)->toBeString();

    $temporaryUpload = TemporaryUploadedFile::createFromLivewire(basename($temporaryUploadPath));
    $temporaryUploadContents = Storage::disk('tmp-for-tests')->get($temporaryUploadPath);
    expect($temporaryUpload->getClientOriginalName())->toBe($imageName)
        ->and(strlen($temporaryUploadContents))->toBe(68)
        ->and(bin2hex(substr($temporaryUploadContents, 0, 8)))->toBe('89504e470d0a1a0a')
        ->and((new finfo(FILEINFO_MIME_TYPE))->buffer($temporaryUploadContents))->toBe('image/png')
        ->and($temporaryUpload->getMimeType())->toBe('image/png')
        ->and($temporaryUpload->guessExtension())->toBe('png')
        ->and($temporaryUpload->exists())->toBeTrue();

    $page
        ->click('.fi-modal-window button[wire\\:target="callMountedAction"]')
        ->wait(2)
        ->assertSee($imageName)
        ->assertSee('Clean');

    $attachment = Attachment::query()
        ->whereBelongsTo($entry)
        ->where('original_name', $imageName)
        ->sole();
    expect($attachment->media_type)->toBe(AttachmentMediaType::Image)
        ->and($attachment->scan_status)->toBe(AttachmentScanStatus::Clean);

    $page
        ->click('Create public version')
        ->assertSee('Create a separate public version?')
        ->press('Create publication draft')
        ->wait(1)
        ->assertPathBeginsWith('/app/publications/')
        ->assertPathEndsWith('/edit');

    $publication = Publication::query()
        ->whereBelongsTo($user, 'owner')
        ->whereBelongsTo($entry, 'sourceEntry')
        ->sole();

    $page
        ->fill('[id="form.title"]', $publicTitle)
        ->fill('[id="form.slug"]', $publicSlug)
        ->fill('[id="form.excerpt"]', $publicExcerpt)
        ->fill('.fi-fo-rich-editor [contenteditable="true"]', $publicBody)
        ->wait(2)
        ->press('Save changes')
        ->wait(1)
        ->assertSee('Saved')
        ->click('Privacy review')
        ->assertSee('Privacy gate · Step 1 of 2')
        ->assertSee($publicTitle)
        ->assertSee('only you can decide')
        ->press('Confirm review and open exact preview')
        ->wait(1)
        ->assertPathIs("/app/publications/{$publication->getKey()}/preview")
        ->assertSee('Private preview — not published')
        ->assertSee($publicTitle)
        ->assertSee($publicExcerpt)
        ->assertSee($publicBody)
        ->press('Confirm I inspected this exact preview')
        ->assertPathIs("/app/publications/{$publication->getKey()}/preview")
        ->assertSee('Exact preview confirmed — not published')
        ->press('Publish to my public profile')
        ->assertPathIs("/app/publications/{$publication->getKey()}/edit");

    $publication->refresh();
    expect($publication->status)->toBe(PublicationStatus::Published)
        ->and($publication->targets()
            ->where('type', PublicationTargetType::Website)
            ->where('status', PublicationTargetStatus::Published)
            ->exists())->toBeTrue();

    $page
        ->navigate('/@browser-journey-writer')
        ->assertSee('Casey Journey')
        ->assertSee($publicTitle)
        ->assertDontSee($privateTitle)
        ->assertDontSee($privateBody)
        ->navigate("/@browser-journey-writer/{$publicSlug}")
        ->assertSee($publicTitle)
        ->assertSee($publicExcerpt)
        ->assertSee($publicBody)
        ->assertDontSee($privateTitle)
        ->assertDontSee($privateBody)
        ->assertDontSee($journalName)
        ->assertDontSee($tag->name)
        ->assertDontSee($person->display_name);

    assertAcceptanceSurfaceIsHealthy($page)->assertNoMissingImages();
});

test('a member can create a password protected private link and request a portable export through the UI', function (): void {
    $this->withVite();

    $password = 'browser-sharing-password';
    $sharePassword = 'correct-private-link-password';
    $user = User::factory()->create([
        'name' => 'River Sharer',
        'email' => 'browser-sharing@example.test',
        'password' => $password,
        'email_verified_at' => now(),
    ]);
    $entry = Entry::factory()->for($user, 'owner')->create([
        'title' => 'Private export and sharing memory',
        'body' => '<p>This private memory is exposed only after the link password is entered.</p>',
        'timezone' => 'UTC',
    ]);

    $page = visit('/app/login')
        ->fill('input[type="email"]', $user->email)
        ->fill('input[type="password"]', $password)
        ->click('button[type="submit"]')
        ->assertPathIs('/app')
        ->navigate('/app/share-links/create')
        ->assertSee('Private link')
        ->click('[id="form.entry_id"]')
        ->fill('[placeholder="Search all active memories by title or place"]', $entry->title)
        ->wait(1)
        ->click('[role="option"]')
        ->fill('[id="form.label"]', 'Browser-protected handoff')
        ->fill('[id="form.password"]', $sharePassword)
        ->fill('[id="form.max_views"]', '3')
        ->press('Create')
        ->wait(1)
        ->assertPathBeginsWith('/app/share-links/')
        ->assertPathEndsWith('/edit')
        ->assertSee('Private link created')
        ->assertPresent('[aria-label="Created private link"]');

    $shareUrl = $page->value('[aria-label="Created private link"]');
    $shareLink = ShareLink::query()
        ->whereBelongsTo($user, 'owner')
        ->whereBelongsTo($entry)
        ->sole();
    expect(Hash::check($sharePassword, (string) $shareLink->password_hash))->toBeTrue()
        ->and($shareLink->max_views)->toBe(3)
        ->and($shareLink->expires_at)->not->toBeNull();

    $page
        ->navigate($shareUrl)
        ->assertSee('Password protected')
        ->assertSee('Enter the sharing password')
        ->assertDontSee('This private memory is exposed only after')
        ->fill('[id="share-password"]', $sharePassword)
        ->press('Open shared memory')
        ->assertSee('Shared privately')
        ->assertSee($entry->title)
        ->assertSee('This private memory is exposed only after the link password is entered.');

    $page
        ->navigate('/app/exports')
        ->assertSee('No exports requested')
        ->click('Request export')
        ->assertSee('Readable formats')
        ->assertSee('Include private attachments')
        ->press('Submit')
        ->wait(3)
        ->assertSee('Export requested')
        ->assertSee('Ready')
        ->assertSee('Secure download');

    $export = Export::query()->whereBelongsTo($user, 'owner')->sole();
    expect($export->status)->toBe(ExportStatus::Ready)
        ->and($export->isDownloadable())->toBeTrue();

    assertAcceptanceSurfaceIsHealthy($page);
});
