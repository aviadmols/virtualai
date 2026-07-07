<?php

namespace App\Domain\Media;

use App\Jobs\TenantAwareJob;

/**
 * PurgeSiteMediaJob — deletes EVERY media object a site owns once the site is deleted.
 *
 * The DB rows cascade on delete (FK cascadeOnDelete on account_id/site_id), but the bucket
 * objects do not — this job removes the whole accounts/{account}/sites/{site}/ prefix so no
 * orphaned media (or PII) is left behind. Carries account_id EXPLICITLY (TenantAwareJob), and
 * the prefix leads with that account_id, so it can only ever purge the ONE site's objects.
 *
 * Dispatched after-commit from Site::deleted, on the media queue so a large bucket delete never
 * blocks the request. Idempotent: purging an already-empty prefix is a no-op.
 */
final class PurgeSiteMediaJob extends TenantAwareJob
{
    public function __construct(
        int $accountId,
        public readonly int $siteId,
    ) {
        parent::__construct($accountId);
        $this->onQueue((string) config('trayon.queues.media'));
    }

    protected function process(): void
    {
        app(MediaStorage::class)->purgeSite($this->accountId, $this->siteId);
    }
}
