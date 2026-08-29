<?php

use App\Actions\ConfirmPublicationPrivacyReview;
use App\Models\Entry;
use App\Models\Journal;
use App\Models\Person;
use App\Models\Publication;
use App\Models\Reminder;
use App\Models\Report;
use App\Models\ShareLink;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

test('every owner-facing panel collection and resource page renders under strict authorization', function (): void {
    $owner = User::factory()->create();
    $journal = Journal::factory()->for($owner, 'owner')->create();
    $entry = Entry::factory()->forJournal($journal)->create();
    $person = Person::factory()->for($owner, 'owner')->create();
    $publication = Publication::factory()->fromEntry($entry)->create();
    app(ConfirmPublicationPrivacyReview::class)->handle($publication, $owner);
    $reminder = Reminder::factory()->for($owner, 'owner')->create();
    $shareLink = ShareLink::factory()->for($entry)->create();
    $tag = Tag::factory()->for($owner, 'owner')->create();

    $paths = [
        '/app',
        '/app/archive',
        '/app/calendar',
        '/app/favorites',
        '/app/on-this-day',
        '/app/search',
        '/app/settings',
        '/app/shared-with-me',
        '/app/timeline',
        '/app/trash',
        '/app/entries',
        '/app/entries/create',
        "/app/entries/{$entry->getRouteKey()}/edit",
        '/app/journals',
        '/app/journals/create',
        "/app/journals/{$journal->getRouteKey()}/edit",
        '/app/people',
        '/app/people/create',
        "/app/people/{$person->getRouteKey()}/edit",
        '/app/publications',
        '/app/publications/create',
        "/app/publications/{$publication->getRouteKey()}/edit",
        "/app/publications/{$publication->getRouteKey()}/preview",
        '/app/reminders',
        '/app/reminders/create',
        "/app/reminders/{$reminder->getRouteKey()}/edit",
        '/app/share-links',
        '/app/share-links/create',
        "/app/share-links/{$shareLink->getRouteKey()}/edit",
        '/app/tags',
        '/app/tags/create',
        "/app/tags/{$tag->getRouteKey()}/edit",
        '/app/exports',
        '/app/security-activity',
        '/app/social-accounts',
        '/app/social-posts',
        '/app/shared-memories',
    ];

    foreach ($paths as $path) {
        $response = $this->actingAs($owner->refresh())->get($path);

        expect(
            $response->getStatusCode(),
            "{$path} returned {$response->getStatusCode()} and redirected to {$response->headers->get('Location')}",
        )->toBe(200);
    }
});

test('every administrative page renders for a super administrator without exposing private entry text', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $superAdministrator = User::factory()->superAdministrator()->create();
    $privateEntry = Entry::factory()->create([
        'body' => '<p>private-panel-smoke-secret</p>',
    ]);
    $report = Report::factory()->create();

    $paths = [
        '/admin',
        '/admin/users',
        '/admin/audit-events',
        '/admin/comments',
        '/admin/publications',
        '/admin/reports',
        "/admin/reports/{$report->getRouteKey()}/edit",
        '/admin/social-post-failures',
        '/admin/system-health',
    ];

    foreach ($paths as $path) {
        $response = $this->actingAs($superAdministrator->refresh())->get($path);

        expect(
            $response->getStatusCode(),
            "{$path} returned {$response->getStatusCode()} and redirected to {$response->headers->get('Location')}",
        )->toBe(200);
        $response
            ->assertDontSee($privateEntry->body, false)
            ->assertDontSee('private-panel-smoke-secret');
    }
});
