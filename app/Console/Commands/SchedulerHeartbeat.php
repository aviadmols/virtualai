<?php

namespace App\Console\Commands;

use App\Support\Health\Heartbeat;
use Illuminate\Console\Command;

/**
 * Writes the scheduler heartbeat. Scheduled every minute on the scheduler service.
 * A dead scheduler means the media-retention purge never fires (storage + egress
 * creep) — so the heartbeat going red is a cost incident, not just availability.
 */
class SchedulerHeartbeat extends Command
{
    // === CONSTANTS ===
    protected $signature = 'trayon:heartbeat';

    protected $description = 'Write the per-minute scheduler heartbeat to the cache.';

    public function handle(Heartbeat $heartbeat): int
    {
        $heartbeat->beat();
        $this->info('heartbeat: '.now()->toIso8601String());

        return self::SUCCESS;
    }
}
