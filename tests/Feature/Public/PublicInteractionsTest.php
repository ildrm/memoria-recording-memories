<?php

use App\Enums\CommentStatus;
use App\Enums\ReactionType;
use App\Models\AuditEvent;
use App\Models\Comment;
use App\Models\Publication;
use App\Models\PublicationTarget;
use App\Models\Reaction;
use App\Models\Report;
use App\Models\User;

test('public interactions are gated by website visibility and never expose pending comment text', function (): void {
    $owner = User::factory()->create();
    $owner->profile()->update(['username' => 'community-writer', 'is_public' => true]);
    $reader = User::factory()->create();
    $publication = Publication::factory()->for($owner, 'owner')->published()->create([
        'slug' => 'community-story',
        'comments_enabled' => true,
        'reactions_enabled' => true,
    ]);
    $websiteTarget = PublicationTarget::factory()->publishedWebsite($publication)->create();
    $pendingText = 'Pending private moderation text <script>alert("pending")</script>';

    $commentResponse = $this->actingAs($reader)->postJson(
        route('publications.comments.store', $publication),
        ['body' => $pendingText],
    )->assertCreated()->assertJsonPath('data.status', CommentStatus::Pending->value);
    $comment = Comment::query()->findOrFail($commentResponse->json('data.id'));

    $this->get(route('publications.show', ['community-writer', $publication->slug]))
        ->assertOk()
        ->assertViewHas('comments', fn ($comments): bool => ! $comments
            ->getCollection()
            ->contains('id', $comment->getKey()));
    $commentAudit = AuditEvent::query()
        ->where('event', 'public_comment.created')
        ->where('auditable_id', $comment->getKey())
        ->firstOrFail();
    expect(json_encode($commentAudit->metadata, JSON_THROW_ON_ERROR))
        ->not->toContain('Pending private moderation text')
        ->not->toContain('<script>');

    $comment->forceFill(['status' => CommentStatus::Approved, 'moderated_at' => now()])->save();
    $this->get(route('publications.show', ['community-writer', $publication->slug]))
        ->assertOk()
        ->assertViewHas('comments', fn ($comments): bool => $comments
            ->getCollection()
            ->contains('id', $comment->getKey()));

    $this->actingAs($reader)->postJson(
        route('publications.reactions.store', $publication),
        ['type' => ReactionType::Support->value],
    )->assertCreated();
    $this->actingAs($reader)->postJson(
        route('publications.reactions.store', $publication),
        ['type' => ReactionType::Support->value],
    )->assertOk();
    expect(Reaction::query()
        ->where('publication_id', $publication->getKey())
        ->where('user_id', $reader->getKey())
        ->where('type', ReactionType::Support)
        ->count())->toBe(1);

    $this->actingAs($reader)->postJson(
        route('publications.reports.store', $publication),
        ['reason' => 'privacy', 'details' => 'A fictional report detail that stays out of audit metadata.'],
    )->assertAccepted();
    expect(Report::query()->where('reporter_user_id', $reader->getKey())->count())->toBe(1)
        ->and(json_encode(
            AuditEvent::query()->where('event', 'public_report.created')->firstOrFail()->metadata,
            JSON_THROW_ON_ERROR,
        ))->not->toContain('fictional report detail');

    $this->actingAs(User::factory()->create())
        ->deleteJson(route('comments.destroy', $comment))
        ->assertForbidden();
    $this->actingAs($reader)
        ->deleteJson(route('comments.destroy', $comment))
        ->assertNoContent();
    expect($comment->refresh()->trashed())->toBeTrue()
        ->and(AuditEvent::query()
            ->where('event', 'public_comment.deleted')
            ->where('auditable_id', $comment->getKey())
            ->exists())->toBeTrue();

    $websiteTarget->delete();
    $this->actingAs($reader)->postJson(
        route('publications.comments.store', $publication),
        ['body' => 'Must not be accepted after website withdrawal.'],
    )->assertNotFound();
    $this->actingAs($reader)->postJson(
        route('publications.reactions.store', $publication),
        ['type' => ReactionType::Like->value],
    )->assertNotFound();
    $this->actingAs($reader)->postJson(
        route('publications.reports.store', $publication),
        ['reason' => 'spam'],
    )->assertNotFound();
});

test('only approved top-level comments are exposed through a separate paginator', function (): void {
    $owner = User::factory()->create();
    $owner->profile()->update(['username' => 'paginated-writer', 'is_public' => true]);
    $publication = Publication::factory()->for($owner, 'owner')->published()->create([
        'slug' => 'paginated-comments',
        'comments_enabled' => true,
    ]);
    PublicationTarget::factory()->publishedWebsite($publication)->create();
    Comment::factory()->count(25)->for($publication)->create([
        'parent_id' => null,
        'status' => CommentStatus::Approved,
    ]);
    $pending = Comment::factory()->for($publication)->pending()->create();

    $this->get(route('publications.show', ['paginated-writer', $publication->slug]).'?comments_page=2')
        ->assertOk()
        ->assertViewHas('comments', fn ($comments): bool => $comments->currentPage() === 2
            && $comments->total() === 25
            && $comments->count() === 5
            && ! $comments->getCollection()->contains('id', $pending->getKey()));
});
