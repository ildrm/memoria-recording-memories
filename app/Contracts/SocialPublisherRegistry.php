<?php

namespace App\Contracts;

use App\Enums\SocialProvider;

interface SocialPublisherRegistry
{
    public function for(SocialProvider $provider): SocialPublisherContract;
}
