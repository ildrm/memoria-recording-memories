<?php

namespace App\Services;

use App\Models\Publication;
use Illuminate\Database\Eloquent\Builder;

class PublicWebsitePublicationQuery
{
    /** @return Builder<Publication> */
    public function query(): Builder
    {
        return Publication::query()
            ->websitePublished();
    }
}
