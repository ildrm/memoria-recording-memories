<?php

use App\Filament\App\Pages\Calendar;
use App\Filament\App\Pages\Search;
use App\Jobs\GenerateUserExport;
use App\Jobs\PublishSocialPost;
use App\Models\AuditEvent;
use App\Models\Entry;
use App\Models\Export;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoContentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('request correlation is server generated and excludes caller supplied context', function (): void {
    $callerValue = 'private-diary-value-'.Str::random(20);

    $firstResponse = $this
        ->withHeader('X-Request-ID', $callerValue)
        ->get(route('health', ['private' => $callerValue]))
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);

    $firstRequestId = $firstResponse->headers->get('X-Request-ID');

    expect($firstRequestId)->toBeString()
        ->and(Str::isUuid($firstRequestId))->toBeTrue()
        ->and($firstRequestId)->not->toBe($callerValue)
        ->and(Context::all())->toBe(['request_id' => $firstRequestId]);

    $secondRequestId = $this->get(route('health'))->headers->get('X-Request-ID');

    expect($secondRequestId)->toBeString()
        ->and(Str::isUuid($secondRequestId))->toBeTrue()
        ->and($secondRequestId)->not->toBe($firstRequestId);

    $errorRequestId = $this
        ->withHeader('X-Request-ID', $callerValue)
        ->get('/missing-request-correlation-check')
        ->assertNotFound()
        ->headers->get('X-Request-ID');

    expect($errorRequestId)->toBeString()
        ->and(Str::isUuid($errorRequestId))->toBeTrue()
        ->and($errorRequestId)->not->toBe($callerValue);
});

test('hot owner and public discovery predicates have matching composite indexes', function (): void {
    expect(Schema::hasIndex('exports', [
        'status',
        'expires_at',
    ]))->toBeTrue()
        ->and(Schema::hasIndex('reminders', [
            'user_id',
            'is_enabled',
            'next_run_at',
        ]))->toBeTrue()
        ->and(Schema::hasIndex('publication_targets', [
            'publication_id',
            'type',
            'status',
            'user_id',
        ]))->toBeTrue()
        ->and(Schema::hasIndex('comments', [
            'publication_id',
            'parent_id',
            'status',
            'created_at',
        ]))->toBeTrue()
        ->and(Schema::hasIndex('stored_file_deletions', [
            'completed_at',
            'failed_at',
            'last_attempted_at',
            'id',
        ]))->toBeTrue();
});

test('queue retry windows exceed the longest application job timeout', function (): void {
    $longestJobTimeout = (new GenerateUserExport(1))->timeout;

    expect(config('queue.connections.database.retry_after'))
        ->toBeGreaterThan($longestJobTimeout)
        ->and(config('queue.connections.redis.retry_after'))
        ->toBeGreaterThan($longestJobTimeout)
        ->and(config('queue.connections.beanstalkd.retry_after'))
        ->toBeGreaterThan($longestJobTimeout);
});

test('social publishing has enough time for credential refresh and provider delivery', function (): void {
    $job = new PublishSocialPost(1);
    $providerRequestTimeout = (int) config('memoria.social.timeout_seconds');
    $overlapLock = $job->middleware()[0];

    expect($job->timeout)
        ->toBeGreaterThanOrEqual($providerRequestTimeout * 2)
        ->toBeLessThan(120)
        ->and($overlapLock)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($overlapLock->expiresAfter)->toBeGreaterThan($job->timeout);
});

test('a busy calendar remains bounded without hiding the complete monthly count', function (): void {
    CarbonImmutable::setTestNow('2026-01-15 12:00:00 UTC');
    Filament::setCurrentPanel(Filament::getPanel('app'));
    $owner = User::factory()->create();

    Entry::factory()
        ->count(251)
        ->for($owner, 'owner')
        ->create([
            'occurred_at' => CarbonImmutable::parse('2026-01-10 09:00:00 UTC'),
            'timezone' => 'UTC',
        ]);

    $this->actingAs($owner)
        ->get(Calendar::getUrl(['month' => '2026-01']))
        ->assertOk()
        ->assertSee('1 memory is not expanded here')
        ->assertSee('Every day total below is complete')
        ->assertSee('Open 1 more memory')
        ->assertSee('Open every memory from this month in search');
});

test('private search is case insensitive and remains owner scoped', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    Entry::factory()->for($owner, 'owner')->create([
        'title' => 'A BRIGHT Winter Morning',
    ]);
    Entry::factory()->for($otherUser, 'owner')->create([
        'title' => 'Another WINTER Memory',
    ]);

    $this->actingAs($owner)
        ->get(Search::getUrl(['q' => 'winter']))
        ->assertOk()
        ->assertSee('A BRIGHT Winter Morning')
        ->assertDontSee('Another WINTER Memory');
});

test('demo security activity uses the owner-visible authentication event name', function (): void {
    Storage::fake('local');

    $this->seed([
        RolesAndPermissionsSeeder::class,
        DemoContentSeeder::class,
    ]);

    $demoExport = Export::query()
        ->whereHas('owner', fn ($query) => $query->where('email', 'maya@example.test'))
        ->sole();
    $archive = Storage::disk((string) $demoExport->disk)->get((string) $demoExport->path);

    expect(AuditEvent::query()->where('event', 'authentication.login')->count())->toBe(5)
        ->and(AuditEvent::query()->where('event', 'security.login')->exists())->toBeFalse()
        ->and($demoExport->isDownloadable())->toBeTrue()
        ->and(Storage::disk((string) $demoExport->disk)->exists((string) $demoExport->path))->toBeTrue()
        ->and($demoExport->size_bytes)->toBe(strlen($archive))
        ->and(substr($archive, 0, 2))->toBe('PK');
});
