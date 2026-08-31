<?php

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Publication;
use App\Models\PublicationTarget;
use App\Models\User;

it('links to every approved reply and paginates the complete thread', function (): void {
    $owner = User::factory()->create();
    $owner->profile()->update(['username' => 'thread-writer', 'is_public' => true]);
    $publication = Publication::factory()->for($owner, 'owner')->published()->create([
        'slug' => 'long-conversation',
        'comments_enabled' => true,
    ]);
    PublicationTarget::factory()->publishedWebsite($publication)->create();
    $parent = Comment::factory()->for($publication)->create([
        'parent_id' => null,
        'body' => 'The top-level response',
        'status' => CommentStatus::Approved,
    ]);

    foreach (range(1, 25) as $number) {
        Comment::factory()->for($publication)->for($parent, 'parent')->create([
            'body' => sprintf('Thread reply %02d', $number),
            'status' => CommentStatus::Approved,
            'created_at' => now()->subMinutes(30)->addMinutes($number),
        ]);
    }
    Comment::factory()->for($publication)->for($parent, 'parent')->pending()->create([
        'body' => 'Pending reply must stay hidden',
    ]);

    $storyUrl = route('publications.show', ['thread-writer', $publication->slug]);
    $threadUrl = route('publications.comments.replies.index', [
        'username' => 'thread-writer',
        'publicationSlug' => $publication->slug,
        'comment' => $parent,
    ]);

    $this->get($storyUrl)
        ->assertOk()
        ->assertSee('View all 25 replies')
        ->assertSee($threadUrl, false)
        ->assertSee('Thread reply 10')
        ->assertDontSee('Thread reply 11')
        ->assertDontSee('Pending reply must stay hidden');

    $threadResponse = $this->get($threadUrl)
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertSee('Thread reply 01')
        ->assertSee('Thread reply 20')
        ->assertDontSee('Thread reply 21')
        ->assertDontSee('Pending reply must stay hidden');

    expect(substr_count((string) $threadResponse->getContent(), '<main'))->toBe(1);

    $this->get($threadUrl.'?replies_page=2')
        ->assertOk()
        ->assertSee('Thread reply 21')
        ->assertSee('Thread reply 25')
        ->assertDontSee('Pending reply must stay hidden');
});

it('returns 404 when a reply thread is requested through another publication', function (): void {
    $owner = User::factory()->create();
    $owner->profile()->update(['username' => 'scoped-thread-writer', 'is_public' => true]);
    $firstPublication = Publication::factory()->for($owner, 'owner')->published()->create([
        'slug' => 'first-story',
        'comments_enabled' => true,
    ]);
    $secondPublication = Publication::factory()->for($owner, 'owner')->published()->create([
        'slug' => 'second-story',
        'comments_enabled' => true,
    ]);
    PublicationTarget::factory()->publishedWebsite($firstPublication)->create();
    PublicationTarget::factory()->publishedWebsite($secondPublication)->create();
    $comment = Comment::factory()->for($firstPublication)->create(['parent_id' => null]);

    $this->get(route('publications.comments.replies.index', [
        'username' => 'scoped-thread-writer',
        'publicationSlug' => $secondPublication->slug,
        'comment' => $comment,
    ]))->assertNotFound();

});
