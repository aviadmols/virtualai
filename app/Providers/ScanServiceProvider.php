<?php

namespace App\Providers;

use App\Domain\Scan\Fetch\GuardedHttpClient;
use App\Domain\Scan\Fetch\GuzzleSingleHopTransport;
use App\Domain\Scan\Fetch\HostResolver;
use App\Domain\Scan\Fetch\PageFetcherManager;
use App\Domain\Scan\Fetch\PageSource;
use App\Domain\Scan\Fetch\SingleHopTransport;
use App\Domain\Scan\Fetch\SystemHostResolver;
use Illuminate\Support\ServiceProvider;

/**
 * ScanServiceProvider — wires the PDP-scan domain.
 *
 * Binds the PageSource seam to the production PageFetcherManager (HTTP-first,
 * headless fallback). Also binds the egress seams that make the SSRF defence
 * testable network-free:
 *   - HostResolver → SystemHostResolver (real A/AAAA DNS; a test fake returns a
 *     chosen IP for a fake host so the guard is provable without a lookup).
 *   - SingleHopTransport → GuzzleSingleHopTransport (curl pin + mid-stream cap; a
 *     test fake drives redirect chains + oversize streams with no network).
 *   - GuardedHttpClient is the single guarded egress entry point all three fetchers
 *     (page / robots / sidecar) ride.
 */
class ScanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PageSource::class, PageFetcherManager::class);
        $this->app->bind(HostResolver::class, SystemHostResolver::class);
        $this->app->bind(SingleHopTransport::class, GuzzleSingleHopTransport::class);
        $this->app->bind(GuardedHttpClient::class, fn ($app): GuardedHttpClient => new GuardedHttpClient(
            $app->make(SingleHopTransport::class),
            $app->make(HostResolver::class),
        ));
    }
}
