<?php

use App\Enums\PublicationTargetStatus;
use App\Enums\SocialPostStatus;
use App\Enums\SocialProvider;
use App\Jobs\GenerateUserExport;
use App\Jobs\PublishSocialPost;
use App\Models\Export;
use App\Models\Publication;
use App\Models\PublicationTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Notifications\ExportReadyNotification;
use App\Notifications\SocialPostFailedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

test('opting out of export ready notifications does not suppress export generation', function (): void {
    Storage::fake('local');
    Notification::fake();
    $owner = User::factory()->create();
    $owner->preferences()->update([
        'notification_preferences' => ['export_ready' => false],
    ]);
    $export = Export::factory()->for($owner, 'owner')->create([
        'options' => ['formats' => ['json'], 'include_attachments' => false],
    ]);

    app()->call([new GenerateUserExport((int) $export->getKey()), 'handle']);

    expect($export->refresh()->path)->not->toBeNull();
    Notification::assertNotSentTo($owner, ExportReadyNotification::class);
});

test('opting out of social failure notifications still records the disconnected state', function (): void {
    Notification::fake();
    $owner = User::factory()->create();
    $owner->preferences()->update([
        'notification_preferences' => ['publication_activity' => false],
    ]);
    $publication = Publication::factory()->for($owner, 'owner')->create();
    $account = SocialAccount::factory()->revoked()->for($owner, 'owner')->create([
        'provider' => SocialProvider::X,
    ]);
    $target = PublicationTarget::factory()
        ->forSocialAccount($publication, $account)
        ->create(['status' => PublicationTargetStatus::Pending]);
    $socialPost = SocialPost::factory()->create([
        'publication_id' => $publication->getKey(),
        'user_id' => $owner->getKey(),
        'publication_target_id' => $target->getKey(),
        'social_account_id' => $account->getKey(),
        'provider' => SocialProvider::X,
    ]);

    app()->call([new PublishSocialPost((int) $socialPost->getKey()), 'handle']);

    expect($socialPost->refresh()->status)->toBe(SocialPostStatus::Disconnected);
    Notification::assertNotSentTo($owner, SocialPostFailedNotification::class);
});
