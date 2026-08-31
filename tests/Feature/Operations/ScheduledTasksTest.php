<?php

use App\Actions\ConfirmPublicationPrivacyReview;
use App\Actions\PublishPublication;
use App\Actions\RecordPublicationPreview;
use App\Actions\SchedulePublication;
use App\Enums\ExportStatus;
use App\Enums\PublicationStatus;
use App\Enums\PublicationTargetStatus;
use App\Enums\ReminderFrequency;
use App\Jobs\DeleteStoredFile;
use App\Jobs\DispatchDueReminders;
use App\Jobs\DispatchPendingStoredFileDeletions;
use App\Jobs\DispatchScheduledPublications;
use App\Jobs\ExpireUserExports;
use App\Jobs\PublishScheduledPublication;
use App\Models\AuditEvent;
use App\Models\Export;
use App\Models\Publication;
use App\Models\Reminder;
use App\Models\User;
use App\Notifications\WritingReminderNotification;
use App\Services\AuditRecorder;
use App\Services\StoredFileCleanup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('a local publication time is stored in UTC and dispatched once when due', function (): void {
    CarbonImmutable::setTestNow('2026-03-01 10:00:00 UTC');
    Queue::fake();
    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->create();
    app(ConfirmPublicationPrivacyReview::class)->handle($publication, $owner);
    app(RecordPublicationPreview::class)->handle($publication, $owner);

    $scheduled = app(SchedulePublication::class)->handle(
        publication: $publication,
        owner: $owner,
        scheduledAt: '2026-03-02 09:30:00',
        timezone: 'Asia/Tehran',
        privacyReviewConfirmed: true,
        previewConfirmed: true,
        publishToWebsite: true,
        socialProviders: [],
    );

    expect($scheduled->status)->toBe(PublicationStatus::Scheduled)
        ->and($scheduled->scheduled_at?->toIso8601String())->toBe('2026-03-02T06:00:00+00:00')
        ->and($scheduled->targets()->firstOrFail()->status)->toBe(PublicationTargetStatus::Scheduled);

    app(DispatchScheduledPublications::class)->handle();
    Queue::assertNotPushed(PublishScheduledPublication::class);

    CarbonImmutable::setTestNow('2026-03-02 06:01:00 UTC');
    app(DispatchScheduledPublications::class)->handle();
    Queue::assertPushed(PublishScheduledPublication::class, 1);

    (new PublishScheduledPublication((int) $scheduled->getKey()))
        ->handle(app(PublishPublication::class));

    expect($scheduled->refresh()->status)->toBe(PublicationStatus::Published)
        ->and($scheduled->scheduled_at)->toBeNull()
        ->and($scheduled->targets()->firstOrFail()->status)->toBe(PublicationTargetStatus::Published)
        ->and($scheduled->versions()->where('reason', 'scheduled_publish')->count())->toBe(1);

    (new PublishScheduledPublication((int) $scheduled->getKey()))
        ->handle(app(PublishPublication::class));

    expect($scheduled->versions()->where('reason', 'scheduled_publish')->count())->toBe(1);
});

test('publication scheduling rejects nonexistent and ambiguous daylight saving wall times', function (string $scheduledAt, string $expectedMessage): void {
    CarbonImmutable::setTestNow('2026-01-01 00:00:00 UTC');
    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->create();
    app(ConfirmPublicationPrivacyReview::class)->handle($publication, $owner);
    app(RecordPublicationPreview::class)->handle($publication, $owner);

    expect(fn () => app(SchedulePublication::class)->handle(
        publication: $publication,
        owner: $owner,
        scheduledAt: $scheduledAt,
        timezone: 'America/New_York',
        privacyReviewConfirmed: true,
        previewConfirmed: true,
        publishToWebsite: true,
        socialProviders: [],
    ))->toThrow(ValidationException::class, $expectedMessage);

    expect($publication->refresh()->status)->toBe(PublicationStatus::Draft)
        ->and($publication->targets()->count())->toBe(0);
})->with([
    'spring-forward gap' => ['2026-03-08 02:30:00', 'does not exist'],
    'fall-back overlap' => ['2026-11-01 01:30:00', 'occurs twice'],
]);

test('an explicit UTC offset disambiguates a repeated daylight saving wall time', function (): void {
    CarbonImmutable::setTestNow('2026-01-01 00:00:00 UTC');
    $owner = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->create();
    app(ConfirmPublicationPrivacyReview::class)->handle($publication, $owner);
    app(RecordPublicationPreview::class)->handle($publication, $owner);

    $scheduled = app(SchedulePublication::class)->handle(
        publication: $publication,
        owner: $owner,
        scheduledAt: '2026-11-01T01:30:00-04:00',
        timezone: 'America/New_York',
        privacyReviewConfirmed: true,
        previewConfirmed: true,
        publishToWebsite: true,
        socialProviders: [],
    );

    expect($scheduled->scheduled_at?->toIso8601String())->toBe('2026-11-01T05:30:00+00:00');
});

test('only due enabled reminders notify their owner and advance in the configured timezone', function (): void {
    CarbonImmutable::setTestNow('2026-03-01 10:00:00 UTC');
    Notification::fake();
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $due = Reminder::factory()->for($owner, 'owner')->create([
        'frequency' => ReminderFrequency::Daily,
        'timezone' => 'Asia/Tehran',
        'local_time' => '20:15:00',
        'next_run_at' => now()->subMinute(),
    ]);
    Reminder::factory()->for($otherUser, 'owner')->create([
        'next_run_at' => now()->addHour(),
    ]);

    app(DispatchDueReminders::class)->handle(app(AuditRecorder::class));

    Notification::assertSentTo($owner, WritingReminderNotification::class);
    Notification::assertNotSentTo($otherUser, WritingReminderNotification::class);
    expect($due->refresh()->last_sent_at)->not->toBeNull()
        ->and($due->next_run_at?->toIso8601String())->toBe('2026-03-01T16:45:00+00:00')
        ->and(AuditEvent::query()
            ->where('event', 'reminder.dispatched')
            ->where('auditable_type', $due->getMorphClass())
            ->where('auditable_id', $due->getKey())
            ->exists())->toBeTrue();
});

test('disabled users never receive reminders and their reminder is disabled', function (): void {
    CarbonImmutable::setTestNow('2026-03-01 10:00:00 UTC');
    Notification::fake();
    $owner = User::factory()->disabled()->create();
    $reminder = Reminder::factory()->for($owner, 'owner')->create([
        'next_run_at' => now()->subMinute(),
    ]);

    app(DispatchDueReminders::class)->handle(app(AuditRecorder::class));

    Notification::assertNothingSent();
    expect($reminder->refresh()->is_enabled)->toBeFalse()
        ->and($reminder->next_run_at)->toBeNull()
        ->and($reminder->last_sent_at)->toBeNull()
        ->and(AuditEvent::query()
            ->where('event', 'reminder.disabled')
            ->where('auditable_id', $reminder->getKey())
            ->exists())->toBeTrue();
});

test('writing reminder opt outs advance silently without pretending a notification was sent', function (): void {
    CarbonImmutable::setTestNow('2026-03-01 10:00:00 UTC');
    Notification::fake();
    $owner = User::factory()->create();
    $owner->preferences()->update([
        'notification_preferences' => [
            'security' => true,
            'writing_reminders' => false,
        ],
    ]);
    $reminder = Reminder::factory()->for($owner, 'owner')->create([
        'frequency' => ReminderFrequency::Daily,
        'timezone' => 'UTC',
        'local_time' => '18:00:00',
        'next_run_at' => now()->subMinute(),
    ]);

    app(DispatchDueReminders::class)->handle(app(AuditRecorder::class));

    Notification::assertNothingSent();
    expect($reminder->refresh()->last_sent_at)->toBeNull()
        ->and($reminder->next_run_at?->isFuture())->toBeTrue()
        ->and(AuditEvent::query()
            ->where('event', 'reminder.skipped')
            ->where('auditable_id', $reminder->getKey())
            ->exists())->toBeTrue();
});

test('expired export files are removed while future exports remain available', function (): void {
    Storage::fake('local');
    $owner = User::factory()->create();
    $expired = Export::factory()->for($owner, 'owner')->ready()->create([
        'path' => 'exports/expired.zip',
        'expires_at' => now()->subMinute(),
    ]);
    $future = Export::factory()->for($owner, 'owner')->ready()->create([
        'path' => 'exports/future.zip',
        'expires_at' => now()->addMinute(),
    ]);
    Storage::disk('local')->put('exports/expired.zip', 'expired');
    Storage::disk('local')->put('exports/future.zip', 'future');

    app(ExpireUserExports::class)->handle();

    expect($expired->refresh()->status)->toBe(ExportStatus::Expired)
        ->and($expired->path)->toBeNull()
        ->and($future->refresh()->status)->toBe(ExportStatus::Ready);
    Storage::disk('local')->assertMissing('exports/expired.zip');
    Storage::disk('local')->assertExists('exports/future.zip');
});

test('personal file deletion is durably queued with an encrypted path and verified cleanup', function (): void {
    Storage::fake('local');
    Queue::fake([DeleteStoredFile::class]);
    $path = 'attachments/private-personal-location.jpg';
    Storage::disk('local')->put($path, 'private bytes');

    $deletionId = app(StoredFileCleanup::class)->schedule(
        'local',
        $path,
        'test_personal_file_cleanup',
    );
    $stored = DB::table('stored_file_deletions')->find($deletionId);

    expect($stored)->not->toBeNull()
        ->and($stored->encrypted_path)->not->toBe($path)
        ->and($stored->encrypted_path)->not->toContain($path)
        ->and($stored->completed_at)->toBeNull();
    Queue::assertPushed(DeleteStoredFile::class, fn (DeleteStoredFile $job): bool => $job->storedFileDeletionId === $deletionId);

    (new DeleteStoredFile($deletionId))->handle(app(AuditRecorder::class));

    Storage::disk('local')->assertMissing($path);
    $completed = DB::table('stored_file_deletions')->find($deletionId);
    expect($completed->encrypted_path)->toBeNull()
        ->and($completed->completed_at)->not->toBeNull()
        ->and(AuditEvent::query()
            ->where('event', 'storage.file_cleanup_completed')
            ->where('metadata->stored_file_deletion_id', $deletionId)
            ->exists())->toBeTrue();
});

test('a storage refusal remains in the durable cleanup ledger for retry', function (): void {
    Queue::fake([DeleteStoredFile::class]);
    $deletionId = app(StoredFileCleanup::class)->schedule(
        'refusing-disk',
        'private/personal-file.jpg',
        'test_storage_refusal',
    );
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->once()->andReturn(true);
    $disk->shouldReceive('delete')->once()->andReturn(false);
    Storage::shouldReceive('disk')->once()->with('refusing-disk')->andReturn($disk);

    expect(fn () => (new DeleteStoredFile($deletionId))->handle(app(AuditRecorder::class)))
        ->toThrow(RuntimeException::class);

    $pending = DB::table('stored_file_deletions')->find($deletionId);
    expect($pending->attempts)->toBe(1)
        ->and($pending->completed_at)->toBeNull()
        ->and($pending->encrypted_path)->not->toBeNull()
        ->and($pending->last_error_code)->toBe('storage_delete_failed');
});

test('an exhausted personal file cleanup is requeued after the recovery interval', function (): void {
    Queue::fake([DeleteStoredFile::class]);
    config()->set('memoria.file_cleanup.retry_failed_after_hours', 24);

    $dueDeletionId = app(StoredFileCleanup::class)->schedule(
        'local',
        'private/due-personal-file.jpg',
        'test_exhausted_cleanup_recovery',
        dispatchImmediately: false,
    );
    $coolingDownDeletionId = app(StoredFileCleanup::class)->schedule(
        'local',
        'private/cooling-down-personal-file.jpg',
        'test_exhausted_cleanup_recovery',
        dispatchImmediately: false,
    );
    DB::table('stored_file_deletions')->where('id', $dueDeletionId)->update([
        'failed_at' => now()->subHours(25),
        'last_attempted_at' => now()->subHours(25),
    ]);
    DB::table('stored_file_deletions')->where('id', $coolingDownDeletionId)->update([
        'failed_at' => now()->subHours(23),
        'last_attempted_at' => now()->subHours(23),
    ]);

    (new DispatchPendingStoredFileDeletions)->handle();

    Queue::assertPushed(
        DeleteStoredFile::class,
        fn (DeleteStoredFile $job): bool => $job->storedFileDeletionId === $dueDeletionId,
    );
    Queue::assertNotPushed(
        DeleteStoredFile::class,
        fn (DeleteStoredFile $job): bool => $job->storedFileDeletionId === $coolingDownDeletionId,
    );
});
