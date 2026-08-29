<?php

namespace Database\Seeders;

use App\Enums\AttachmentMediaType;
use App\Enums\AttachmentScanStatus;
use App\Enums\CommentStatus;
use App\Enums\ExportStatus;
use App\Enums\Mood;
use App\Enums\PublicationStatus;
use App\Enums\PublicationTargetStatus;
use App\Enums\ReactionType;
use App\Enums\RoleName;
use App\Enums\SocialPostStatus;
use App\Enums\SocialProvider;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Comment;
use App\Models\Entry;
use App\Models\EntryShare;
use App\Models\EntryVersion;
use App\Models\Export;
use App\Models\Journal;
use App\Models\Person;
use App\Models\Publication;
use App\Models\PublicationTarget;
use App\Models\PublicationVersion;
use App\Models\Reaction;
use App\Models\Reminder;
use App\Models\Report;
use App\Models\ShareLink;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostFailure;
use App\Models\Tag;
use App\Models\User;
use App\Services\UserExportArchiveBuilder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;

class DemoContentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo content may only be seeded in local or testing environments.');
        }

        $passwords = [
            'super-admin@example.test' => Str::password(24),
            'admin@example.test' => Str::password(24),
            'moderator@example.test' => Str::password(24),
            'maya@example.test' => Str::password(24),
            'jonah@example.test' => Str::password(24),
        ];
        $shareToken = Str::random(64);
        $sharePassword = Str::password(24);

        $superAdministrator = $this->createUser('Rowan Hale', 'super-admin@example.test', RoleName::SuperAdministrator, $passwords['super-admin@example.test']);
        $administrator = $this->createUser('Leila Morgan', 'admin@example.test', RoleName::Administrator, $passwords['admin@example.test']);
        $moderator = $this->createUser('Noor Bennett', 'moderator@example.test', RoleName::Moderator, $passwords['moderator@example.test']);
        $writer = $this->createUser('Maya Ellison', 'maya@example.test', RoleName::User, $passwords['maya@example.test']);
        $trustedReader = $this->createUser('Jonah Reed', 'jonah@example.test', RoleName::User, $passwords['jonah@example.test']);

        $writer->profile()->update([
            'username' => 'maya-ellison',
            'display_name' => 'Maya Ellison',
            'biography' => 'Writer, gardener, and collector of ordinary moments.',
            'website_url' => 'https://example.test/maya',
            'is_public' => true,
        ]);
        $writer->preferences()->update([
            'timezone' => 'Europe/London',
            'on_this_day_enabled' => true,
        ]);

        $personal = Journal::factory()->create([
            'user_id' => $writer->getKey(),
            'name' => 'Small Joys',
            'slug' => 'small-joys',
            'description' => 'Quiet moments worth remembering.',
            'sort_order' => 1,
        ]);
        $travel = Journal::factory()->create([
            'user_id' => $writer->getKey(),
            'name' => 'Journeys',
            'slug' => 'journeys',
            'description' => 'Notes from places near and far.',
            'sort_order' => 2,
        ]);
        Journal::factory()->archived()->create([
            'user_id' => $writer->getKey(),
            'name' => 'University Years',
            'slug' => 'university-years',
            'sort_order' => 3,
        ]);

        $gardenEntry = Entry::factory()->private()->favorite()->forJournal($personal)->create([
            'title' => 'The first tomatoes of summer',
            'body' => '<p>This morning the first tomatoes were finally red enough to pick. The greenhouse smelled of warm leaves and rain.</p>',
            'occurred_at' => now()->subYears(2)->setMonth(7)->setDay(18)->setTime(8, 15),
            'timezone' => 'Europe/London',
            'mood' => Mood::Grateful,
            'location_name' => 'Home garden',
            'revision' => 2,
        ]);
        $coastEntry = Entry::factory()->private()->forJournal($travel)->create([
            'title' => 'A windy afternoon by the coast',
            'body' => '<p>We followed the cliff path until the lighthouse appeared through the mist. No location coordinates were recorded.</p>',
            'occurred_at' => now()->subMonths(5),
            'timezone' => 'Europe/London',
            'mood' => Mood::Reflective,
            'location_name' => 'North coast',
        ]);
        Entry::factory()->draft()->forJournal($personal)->create([
            'body' => '<p>A thought to return to later.</p>',
        ]);
        Entry::factory()->private()->archived()->forJournal($personal)->create([
            'title' => 'A completed chapter',
        ]);

        foreach ([
            [1, 'Tomatoes in the greenhouse', '<p>The first tomatoes were ready today.</p>', Mood::Happy],
            [2, $gardenEntry->title, $gardenEntry->body, Mood::Grateful],
        ] as [$version, $title, $body, $mood]) {
            EntryVersion::factory()->create([
                'entry_id' => $gardenEntry->getKey(),
                'user_id' => $writer->getKey(),
                'version' => $version,
                'title' => $title,
                'body' => $body,
                'occurred_at' => $gardenEntry->occurred_at,
                'timezone' => $gardenEntry->timezone,
                'mood' => $mood,
                'reason' => 'manual-save',
            ]);
        }

        $gratitudeTag = Tag::factory()->create(['user_id' => $writer->getKey(), 'name' => 'gratitude']);
        $summerTag = Tag::factory()->create(['user_id' => $writer->getKey(), 'name' => 'summer']);
        $gardenEntry->tags()->attach([$gratitudeTag->getKey(), $summerTag->getKey()], ['attached_at' => now()]);

        $jonahMemory = Person::factory()->create([
            'user_id' => $writer->getKey(),
            'display_name' => 'Jonah',
            'nickname' => 'Jo',
            'relationship' => 'friend',
            'notes' => 'A fictional person record, separate from application accounts.',
        ]);
        $coastEntry->people()->attach($jonahMemory, [
            'relationship_context' => 'travel companion',
            'attached_at' => now(),
        ]);

        $privateAttachmentPath = 'private/attachments/demo-garden-note.txt';
        $privateAttachmentContents = 'Fictional private attachment used by the local demonstration seed.';
        Storage::disk('local')->put($privateAttachmentPath, $privateAttachmentContents);
        Attachment::factory()->create([
            'user_id' => $writer->getKey(),
            'entry_id' => $gardenEntry->getKey(),
            'disk' => 'local',
            'path' => $privateAttachmentPath,
            'original_name' => 'garden-note.txt',
            'download_name' => 'garden-note.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size_bytes' => strlen($privateAttachmentContents),
            'media_type' => AttachmentMediaType::Document,
            'sha256' => hash('sha256', $privateAttachmentContents),
            'scan_status' => AttachmentScanStatus::Clean,
            'metadata' => null,
        ]);

        EntryShare::factory()->create([
            'entry_id' => $coastEntry->getKey(),
            'shared_by_user_id' => $writer->getKey(),
            'shared_with_user_id' => $trustedReader->getKey(),
            'include_attachments' => false,
            'expires_at' => now()->addMonth(),
        ]);
        ShareLink::factory()->passwordProtected($sharePassword)->create([
            'entry_id' => $gardenEntry->getKey(),
            'publication_id' => null,
            'user_id' => $writer->getKey(),
            'token_hash' => hash('sha256', $shareToken),
            'label' => 'Family reading link',
            'expires_at' => now()->addWeek(),
        ]);

        $published = Publication::factory()->fromEntry($gardenEntry)->published()->create([
            'title' => 'The first tomatoes of summer',
            'slug' => 'first-tomatoes-of-summer',
            'excerpt' => 'A small summer milestone in the greenhouse.',
            'body' => '<p>The first tomatoes were finally red enough to pick. The greenhouse smelled of warm leaves and rain.</p>',
        ]);
        $scheduled = Publication::factory()->fromEntry($coastEntry)->scheduled()->create([
            'title' => 'Notes from a windy coast',
            'slug' => 'notes-from-a-windy-coast',
            'body' => '<p>A public-safe reflection on a misty walk, with exact location details omitted.</p>',
        ]);

        PublicationVersion::factory()->create([
            'publication_id' => $published->getKey(),
            'user_id' => $writer->getKey(),
            'version' => 1,
            'title' => $published->title,
            'excerpt' => $published->excerpt,
            'body' => $published->body,
            'status' => PublicationStatus::Published,
            'settings' => [
                'comments_enabled' => true,
                'reactions_enabled' => true,
                'search_engine_indexing' => true,
            ],
            'reason' => 'published',
        ]);

        PublicationTarget::factory()->create([
            'publication_id' => $published->getKey(),
            'user_id' => $writer->getKey(),
            'target_key' => 'website',
            'status' => PublicationTargetStatus::Published,
            'completed_at' => $published->published_at,
        ]);

        $socialAccount = SocialAccount::factory()->create([
            'user_id' => $writer->getKey(),
            'provider' => SocialProvider::Mastodon,
            'provider_user_id' => 'fictional-maya-on-example-social',
            'username' => 'maya',
            'display_name' => 'Maya Ellison',
            'server_url' => 'https://social.example',
        ]);
        $socialTarget = PublicationTarget::factory()->forSocialAccount($published, $socialAccount)->create([
            'status' => PublicationTargetStatus::Published,
            'completed_at' => now()->subHour(),
        ]);
        SocialPost::factory()->published()->create([
            'user_id' => $writer->getKey(),
            'publication_id' => $published->getKey(),
            'publication_target_id' => $socialTarget->getKey(),
            'social_account_id' => $socialAccount->getKey(),
            'provider' => SocialProvider::Mastodon,
            'content' => 'A small summer milestone from the greenhouse.',
        ]);

        $scheduledSocialTarget = PublicationTarget::factory()->forSocialAccount($scheduled, $socialAccount)->create([
            'status' => PublicationTargetStatus::Failed,
            'failed_at' => now(),
        ]);
        $failedPost = SocialPost::factory()->failed()->create([
            'user_id' => $writer->getKey(),
            'publication_id' => $scheduled->getKey(),
            'publication_target_id' => $scheduledSocialTarget->getKey(),
            'social_account_id' => $socialAccount->getKey(),
            'provider' => SocialProvider::Mastodon,
            'status' => SocialPostStatus::Failed,
            'content' => 'A fictional scheduled reflection from the coast.',
        ]);
        SocialPostFailure::factory()->create([
            'social_post_id' => $failedPost->getKey(),
            'attempt' => 3,
            'occurred_at' => now(),
        ]);

        $demoExport = Export::factory()->create([
            'user_id' => $writer->getKey(),
            'options' => [
                'formats' => ['json', 'markdown'],
                'include_attachments' => false,
            ],
        ]);
        $demoArchive = app(UserExportArchiveBuilder::class)->build($demoExport, $writer);
        $demoExport->forceFill([
            'status' => ExportStatus::Ready,
            'disk' => $demoArchive['disk'],
            'path' => $demoArchive['path'],
            'filename' => $demoArchive['filename'],
            'size_bytes' => $demoArchive['size'],
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'expires_at' => now()->addHours((int) config('memoria.exports.expiration_hours', 72)),
        ])->save();
        Reminder::factory()->create([
            'user_id' => $writer->getKey(),
            'timezone' => 'Europe/London',
        ]);

        $comment = Comment::factory()->create([
            'publication_id' => $published->getKey(),
            'user_id' => $trustedReader->getKey(),
            'body' => 'This made me notice the small changes in my own garden.',
            'status' => CommentStatus::Approved,
        ]);
        Reaction::factory()->create([
            'publication_id' => $published->getKey(),
            'user_id' => $trustedReader->getKey(),
            'type' => ReactionType::Love,
        ]);
        Report::factory()->create([
            'publication_id' => null,
            'comment_id' => $comment->getKey(),
            'reporter_user_id' => $administrator->getKey(),
            'assigned_to_user_id' => $moderator->getKey(),
            'reason' => 'review-request',
            'details' => 'A fictional open report for demonstrating public comment moderation.',
        ]);

        foreach ([$superAdministrator, $administrator, $moderator, $writer, $trustedReader] as $user) {
            AuditEvent::factory()->create([
                'actor_user_id' => $user->getKey(),
                'event' => 'authentication.login',
                'metadata' => ['outcome' => 'success', 'authentication' => 'password'],
            ]);
        }
        AuditEvent::factory()->create([
            'actor_user_id' => $writer->getKey(),
            'auditable_type' => Publication::class,
            'auditable_id' => $published->getKey(),
            'event' => 'publication.published',
            'metadata' => ['publication_id' => $published->getKey()],
        ]);

        $this->command?->newLine();
        $this->command?->warn('Local-only Memoria demo credentials (generated for this seed run):');
        $this->command?->table(
            ['Email', 'One-time password'],
            collect($passwords)->map(fn (string $password, string $email): array => [$email, $password])->values()->all(),
        );
    }

    private function createUser(
        string $name,
        string $email,
        RoleName $roleName,
        string $password,
    ): User {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);
        $user->assignRole($roleName);

        return $user;
    }
}
