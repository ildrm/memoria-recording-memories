<?php

namespace App\Services\Social;

use App\Contracts\SocialPublisherContract;
use App\Contracts\SocialPublisherRegistry;
use App\Enums\SocialProvider;
use App\Services\Social\Exceptions\PermanentSocialPublishException;

class SocialPublisherManager implements SocialPublisherRegistry
{
    /**
     * @param  iterable<SocialPublisherContract>  $publishers
     */
    public function __construct(private readonly iterable $publishers) {}

    public function for(SocialProvider $provider): SocialPublisherContract
    {
        foreach ($this->publishers as $publisher) {
            if ($publisher->supports($provider)) {
                return $publisher;
            }
        }

        throw new PermanentSocialPublishException(
            "The {$provider->value} publishing provider is not configured.",
        );
    }
}
