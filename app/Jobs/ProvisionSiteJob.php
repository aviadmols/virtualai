<?php

namespace App\Jobs;

use App\Models\Site;

/**
 * Example tenant-scoped job that proves the TenantAwareJob pattern.
 *
 * It creates a Site WITHOUT passing account_id — relying on the bound tenant's
 * auto-fill via BelongsToAccount. Because handle() binds $this->accountId
 * through Tenant::run(), the new site lands on the correct account even when
 * this job runs back-to-back with another account's job on the same worker.
 *
 * Queued on the canonical `default` queue (config('trayon.queues')).
 */
class ProvisionSiteJob extends TenantAwareJob
{
    // === CONSTANTS ===
    private const QUEUE_KEY = 'default';

    public function __construct(
        int $accountId,
        public readonly string $siteName,
    ) {
        parent::__construct($accountId);
        $this->onQueue(config('trayon.queues.'.self::QUEUE_KEY));
    }

    protected function process(): void
    {
        // No account_id is passed: BelongsToAccount stamps it from the bound
        // tenant (Tenant::id()), which Tenant::run() set to $this->accountId.
        Site::create(['name' => $this->siteName]);
    }
}
