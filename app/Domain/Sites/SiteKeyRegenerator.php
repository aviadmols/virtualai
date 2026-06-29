<?php

namespace App\Domain\Sites;

use App\Domain\Activity\ActivityRecorder;
use App\Models\ActivityEvent;
use App\Models\Site;

/**
 * SiteKeyRegenerator — rotates a site's PUBLIC site_key (the merchant "regenerate key"
 * action). Mints a fresh URL-safe key and persists it, so the OLD key — and any widget
 * embed still using it — is immediately invalidated (the site is resolved by site_key,
 * and only one is stored).
 *
 * The server-side widget_secret is NEVER touched and NEVER returned: it is a separate
 * HMAC secret, encrypted at rest and hidden from serialization. Rotating the public key
 * is independent of the secret, so an exposed/abused embed key can be cycled without
 * disturbing the signing secret.
 *
 * Returns the NEW key (the only thing the merchant UI needs to copy into the embed).
 */
final class SiteKeyRegenerator
{
    public function __construct(
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Rotate $site->site_key. Saves only the site_key column (forceFill avoids touching
     * widget_secret or any other field), records a site_key_regenerated event, and
     * returns the new public key. Must run inside the site's bound tenant.
     */
    public function regenerate(Site $site): string
    {
        $newKey = Site::generateSiteKey();

        // Persist ONLY the public key — widget_secret is left exactly as stored.
        $site->forceFill(['site_key' => $newKey])->save();

        $this->activity->record(
            kind: ActivityEvent::KIND_SITE_KEY_REGENERATED,
            subject: $site,
            details: [], // never log the key value itself
            siteId: $site->getKey(),
            actor: ActivityEvent::ACTOR_MERCHANT,
        );

        return $newKey;
    }
}
