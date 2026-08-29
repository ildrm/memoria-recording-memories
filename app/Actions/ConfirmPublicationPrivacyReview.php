<?php

namespace App\Actions;

use App\Models\Publication;
use App\Models\User;
use App\Services\PublicationWorkflowConfirmation;

class ConfirmPublicationPrivacyReview
{
    public function __construct(
        private readonly PublicationWorkflowConfirmation $workflowConfirmation,
    ) {}

    public function handle(Publication $publication, User $owner): string
    {
        return $this->workflowConfirmation->confirmPrivacyReview($publication, $owner);
    }
}
