<?php

namespace App\Services;

use App\Models\Publication;

class PublicPublicationGuard
{
    public function __construct(
        private readonly PublicWebsitePublicationQuery $websitePublications,
    ) {}

    public function resolve(Publication $publication, bool $forUpdate = false): Publication
    {
        $query = $this->websitePublications
            ->query()
            ->whereKey($publication->getKey())
            ->whereHas('owner.profile', fn ($profile) => $profile
                ->where('is_public', true)
                ->whereNotNull('username'))
            ->whereHas('owner', fn ($owner) => $owner->whereNull('disabled_at'));

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }
}
