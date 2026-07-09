<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Contracts\ImageGenerationProvider;
use App\Domain\Ai\Contracts\VideoGenerationProvider;

/**
 * VideoProviderRouter — resolves the async VIDEO client for a provider id.
 *
 * The single seam that maps a resolved provider (from the AiOperation / catalog) to its
 * VideoGenerationProvider implementation, so the storyboard/playground callers never hardcode a
 * client. AtlasCloud routes to AtlasCloudVideoClient; every other id (byteplus is the historical
 * default) routes to BytePlusVideoClient.
 */
final class VideoProviderRouter
{
    /** Resolve the video client for a provider id. Unknown/empty falls back to BytePlus. */
    public function for(string $provider): VideoGenerationProvider
    {
        return $provider === ImageGenerationProvider::PROVIDER_ATLASCLOUD
            ? app(AtlasCloudVideoClient::class)
            : app(BytePlusVideoClient::class);
    }
}
