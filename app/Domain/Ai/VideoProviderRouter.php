<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Contracts\ImageGenerationProvider;
use App\Domain\Ai\Contracts\VideoGenerationProvider;

/**
 * VideoProviderRouter — resolves the async VIDEO client for a provider id.
 *
 * The single seam that maps a resolved provider (from the AiOperation / catalog) to its
 * VideoGenerationProvider implementation, so the storyboard/playground callers never hardcode a
 * client. AtlasCloud and fal route to their own clients; every other id (byteplus is the
 * historical default) routes to BytePlusVideoClient.
 */
final class VideoProviderRouter
{
    /** Resolve the video client for a provider id. Unknown/empty falls back to BytePlus. */
    public function for(string $provider): VideoGenerationProvider
    {
        return match ($provider) {
            ImageGenerationProvider::PROVIDER_ATLASCLOUD => app(AtlasCloudVideoClient::class),
            ImageGenerationProvider::PROVIDER_FAL => app(FalVideoClient::class),
            default => app(BytePlusVideoClient::class),
        };
    }
}
