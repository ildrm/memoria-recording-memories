<?php

namespace App\Policies;

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Publication;
use App\Models\User;

class CommentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(?User $user, Comment $comment): bool
    {
        return $comment->status === CommentStatus::Approved
            && $comment->publication->isPubliclyVisible();
    }

    public function create(User $user, Publication $publication): bool
    {
        return $publication->isPubliclyVisible() && $publication->comments_enabled;
    }

    public function update(User $user, Comment $comment): bool
    {
        return $comment->user_id !== null && $user->is($comment->author);
    }

    public function delete(User $user, Comment $comment): bool
    {
        return ($comment->user_id !== null && $user->is($comment->author))
            || $comment->publication->isOwnedBy($user)
            || ($comment->publication->isPubliclyVisible() && $user->hasPermissionTo('comments.moderate'));
    }

    public function moderate(User $user, Comment $comment): bool
    {
        return $comment->publication->isPubliclyVisible()
            && $user->hasPermissionTo('comments.moderate');
    }
}
