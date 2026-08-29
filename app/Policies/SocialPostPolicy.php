<?php

namespace App\Policies;

use App\Models\SocialPost;
use App\Models\User;

class SocialPostPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SocialPost $post): bool
    {
        return $post->isOwnedBy($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SocialPost $post): bool
    {
        return $post->isOwnedBy($user);
    }

    public function delete(User $user, SocialPost $post): bool
    {
        return $post->isOwnedBy($user);
    }

    public function retry(User $user, SocialPost $post): bool
    {
        return $post->isOwnedBy($user);
    }

    public function viewOperationalMetadata(User $user, SocialPost $post): bool
    {
        return $user->hasPermissionTo('social-failures.view');
    }
}
