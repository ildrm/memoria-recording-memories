<?php

namespace App\Actions;

use App\Models\Comment;
use App\Models\Publication;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\PublicPublicationGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeletePublicComment
{
    public function __construct(
        private readonly PublicPublicationGuard $publicationGuard,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function handle(
        Comment $comment,
        User $actor,
        ?Request $request = null,
    ): void {
        DB::transaction(function () use ($comment, $actor, $request): void {
            $comment = Comment::query()->lockForUpdate()->findOrFail($comment->getKey());
            $publication = Publication::query()->findOrFail($comment->publication_id);
            $publication = $this->publicationGuard->resolve($publication, forUpdate: true);
            Gate::forUser($actor)->authorize('delete', $comment->loadMissing(['author', 'publication']));

            $relationship = match (true) {
                (int) $comment->user_id === (int) $actor->getKey() => 'author',
                (int) $publication->user_id === (int) $actor->getKey() => 'publication_owner',
                default => 'moderator',
            };

            $comment->delete();
            $this->auditRecorder->record(
                event: 'public_comment.deleted',
                actor: $actor,
                auditable: $comment,
                metadata: [
                    'publication_id' => $publication->getKey(),
                    'actor_relationship' => $relationship,
                ],
                request: $request,
            );
        });
    }
}
