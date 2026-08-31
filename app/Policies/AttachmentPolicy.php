<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;

class AttachmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Attachment $attachment): bool
    {
        if ($attachment->isOwnedBy($user)) {
            return true;
        }

        return $attachment->entry?->shares()
            ->active()
            ->whereBelongsTo($user, 'recipient')
            ->where('include_attachments', true)
            ->exists() === true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Attachment $attachment): bool
    {
        return $attachment->isOwnedBy($user);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return $attachment->isOwnedBy($user);
    }

    public function restore(User $user, Attachment $attachment): bool
    {
        return $attachment->isOwnedBy($user);
    }

    public function forceDelete(User $user, Attachment $attachment): bool
    {
        return $attachment->isOwnedBy($user);
    }

    public function download(User $user, Attachment $attachment): bool
    {
        return $this->view($user, $attachment);
    }
}
