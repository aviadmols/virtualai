<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Fail-closed predeploy guard. Refuses to advance a deploy when any critical env
 * is missing or invalid. A deploy that BOOTS but is broken is worse than one that
 * refuses to boot — because it can quietly charge wrong or drain credits.
 */
class PredeployCheck extends Command
{
    // === CONSTANTS ===
    protected $signature = 'trayon:predeploy-check
        {--skip-disk : Skip the media-disk reachability probe (no network).}';

    protected $description = 'Fail-closed env/config guard run before a deploy takes traffic.';

    private const ENV_PRODUCTION = 'production';
    private const ENV_LOCAL = 'local';
    private const ENV_TESTING = 'testing';

    // Local-driver disks that live inside the container filesystem (wiped on every deploy).
    private const DRIVER_LOCAL = 'local';
    private const EPHEMERAL_DISKS = ['local', 'public'];

    // Secrets/URLs that must be present in EVERY environment.
    private const REQUIRED_ALWAYS = [
        'APP_KEY',
        'APP_URL',
        'TENANT_CREDENTIALS_KEY',
    ];

    // Secrets that must be present in production (relaxed locally for verification).
    private const REQUIRED_PRODUCTION = [
        'OPENROUTER_API_KEY',
    ];

    // Drivers that MUST be redis in production (Horizon, locks, heartbeat,
    // rate-limiters all require it).
    private const REDIS_REQUIRED_DRIVERS = [
        'queue.default' => 'redis',
        'cache.default' => 'redis',
    ];

    private const MEDIA_DISK_CONFIG = 'trayon.media.disk';
    private const PROBE_PATH = 'predeploy/.probe';

    public function handle(): int
    {
        $env = (string) config('app.env');
        $isProduction = $env === self::ENV_PRODUCTION;
        $failures = [];

        // 1. Always-required secrets.
        foreach (self::REQUIRED_ALWAYS as $key) {
            if (blank(env($key))) {
                $failures[] = "missing required env: {$key}";
            }
        }

        // 2. Production-only required secrets.
        if ($isProduction) {
            foreach (self::REQUIRED_PRODUCTION as $key) {
                if (blank(env($key))) {
                    $failures[] = "missing required env (production): {$key}";
                }
            }
        }

        // 3. Database connection / store discipline in production.
        if ($isProduction) {
            if (config('database.default') === 'sqlite') {
                $failures[] = 'DB_CONNECTION=sqlite is refused in production (ephemeral fs loses every ledger row).';
            }

            if (blank(env('DATABASE_URL')) && blank(env('DB_HOST')) && blank(env('PGHOST'))) {
                $failures[] = 'no database target: set DATABASE_URL (or DB_HOST / PGHOST).';
            }

            if (blank(env('REDIS_URL')) && blank(env('REDIS_HOST'))) {
                $failures[] = 'no redis target: set REDIS_URL (or REDIS_HOST).';
            }

            foreach (self::REDIS_REQUIRED_DRIVERS as $configKey => $expected) {
                $actual = config($configKey);
                if ($actual !== $expected) {
                    $failures[] = "{$configKey} must be '{$expected}' in production, got '{$actual}'.";
                }
            }
        }

        // 4. Media disk must be configured (and reachable unless skipped).
        $diskName = (string) config(self::MEDIA_DISK_CONFIG);
        if (blank($diskName)) {
            $failures[] = 'media disk is not configured (config trayon.media.disk).';
        } elseif ($isProduction && in_array($diskName, ['local', 'public'], true)) {
            $failures[] = "media disk '{$diskName}' is refused in production (use the s3/R2 disk).";
        } elseif ($isProduction && blank(config("filesystems.disks.{$diskName}.bucket"))) {
            $failures[] = "media disk '{$diskName}' has no bucket configured (set S3_BUCKET / R2_BUCKET).";
        } elseif (! $isProduction && ! in_array($env, [self::ENV_LOCAL, self::ENV_TESTING], true)) {
            // Hosted non-production (e.g. staging): an ephemeral media disk BOOTS fine but is
            // WIPED on every deploy — the recurring "all my uploads vanished" data loss. Warn
            // loudly (not fatal, so staging still deploys) until persistent storage is wired.
            $reason = $this->ephemeralMediaReason($diskName);
            if ($reason !== null) {
                $this->warn('predeploy-check WARNING: '.$reason);
            }
        }

        if (! $this->option('skip-disk') && filled($diskName) && empty($failures)) {
            $this->probeMediaDisk($diskName, $failures);
        }

        return $this->report($failures);
    }

    /**
     * Why the given media disk would LOSE data across a deploy, or null if it is persistent.
     * A local-driver disk lives inside the container image; only a mounted Railway Volume
     * (MEDIA_DISK=volume + MEDIA_VOLUME_PATH pointing at the mount) survives a redeploy.
     * Object storage (s3/R2) is persistent by nature.
     */
    private function ephemeralMediaReason(string $diskName): ?string
    {
        $driver = (string) config("filesystems.disks.{$diskName}.driver");
        if ($driver !== self::DRIVER_LOCAL) {
            return null;
        }

        if (in_array($diskName, self::EPHEMERAL_DISKS, true)) {
            return "media disk '{$diskName}' is EPHEMERAL (container filesystem) — every deploy wipes all uploads + generated media. "
                .'Mount a Railway Volume and set MEDIA_DISK=volume + MEDIA_VOLUME_PATH=/upload, or set MEDIA_DISK=s3 with R2 credentials.';
        }

        // The 'volume' disk is persistent ONLY when a real mount path is given; unset defaults
        // inside the app dir (storage/app/volume-media) and is wiped on deploy like any other.
        if (blank(env('MEDIA_VOLUME_PATH'))) {
            return "media disk 'volume' has no MEDIA_VOLUME_PATH — it defaults inside the app and is wiped on every deploy. "
                .'Mount a Railway Volume (e.g. /upload) and set MEDIA_VOLUME_PATH to that mount path.';
        }

        return null;
    }

    /**
     * Write-then-delete a tiny probe object to confirm the disk is reachable.
     * An unreachable media disk is a refusal, not a warning.
     */
    private function probeMediaDisk(string $diskName, array &$failures): void
    {
        try {
            $disk = Storage::disk($diskName);
            $disk->put(self::PROBE_PATH, (string) now()->timestamp);
            $disk->delete(self::PROBE_PATH);
        } catch (\Throwable $e) {
            $failures[] = "media disk '{$diskName}' is unreachable: ".$e->getMessage();
        }
    }

    private function report(array $failures): int
    {
        if (! empty($failures)) {
            $this->error('PREDEPLOY CHECK FAILED — refusing to deploy:');
            foreach ($failures as $failure) {
                $this->line('  - '.$failure);
            }

            return self::FAILURE;
        }

        $this->info('predeploy-check: ok');

        return self::SUCCESS;
    }
}
