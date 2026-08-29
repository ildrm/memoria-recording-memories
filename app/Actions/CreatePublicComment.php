<?php

namespace App\Actions;

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Publication;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\PublicPublicationGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreatePublicComment
{
    public function __construct(
        private readonly PublicPublicationGuard $publicationGuard,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function handle(
        Publication $publication,
        User $author,
        string $body,
        ?Comment $parent = null,
        ?Request $request = null,
    ): Comment {
        $body = trim($body);
        if ($body === '' || Str::length($body) > 2000) {
            throw ValidationException::withMessages([
                'body' => [__('Comments must contain between 1 and 2,000 characters.')],
            ]);
        }

        return DB::transaction(function () use (
            $publication,
            $author,
            $body,
            $parent,
            $request,
        ): Comment {
            $publication = $this->publicationGuard->resolve($publication, forUpdate: true);
            Gate::forUser($author)->authorize('create', [Comment::class, $publication]);

            $parent = $parent === null
                ? null
                : Comment::query()
                    ->approved()
                    ->whereBelongsTo($publication)
                    ->whereKey($parent->getKey())
                    ->firstOrFail();

            $comment = new Comment;
            $comment->forceFill([
                'publication_id' => $publication->getKey(),
                'user_id' => $author->getKey(),
                'parent_id' => $parent?->getKey(),
                'body' => $body,
                'status' => CommentStatus::Pending,
                'ip_hash' => $this->fingerprint($request?->ip()),
            ]);
            $comment->save();

            $this->auditRecorder->record(
                event: 'public_comment.created',
                actor: $author,
                auditable: $comment,
                metadata: [
                    'publication_id' => $publication->getKey(),
                    'parent_id' => $parent?->getKey(),
                    'text_length' => Str::length($body),
                    'initial_status' => CommentStatus::Pending->value,
                ],
                request: $request,
            );

            return $comment;
        });
    }

    private function fingerprint(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }
}
