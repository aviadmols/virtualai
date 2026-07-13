<?php

namespace App\Domain\Shopify\Products;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Platform\PlatformSettings;
use App\Domain\Shopify\Api\ShopifyApiException;
use App\Models\ActivityEvent;
use App\Models\ShopifySyncRun;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * StartShopifySync — the ONE entry point that opens a sync run and fans the work out.
 *
 * It owns three decisions so no UI/job re-implements them:
 *  1. THE SOFT CAP — ENFORCED HERE, not merely described. "Import all" is capped (default
 *     1,000 products) so one click cannot queue a 40k-product store into the bulk queue.
 *     catalog() MEASURES the store and, over the cap, opens NO run and dispatches NOTHING:
 *     it returns a typed StartSyncResult refusal the page renders (never a 500, and never
 *     a half-imported catalog). The cap is config-driven with a PLATFORM-ADMIN override
 *     (PlatformSettings) — never a literal at a call site, never merchant-settable.
 *     A modal warning is not a guard: the merchant can always press submit.
 *  2. ONE ACTIVE RUN PER SITE. A second "Import all" while a walk is in flight returns
 *     the RUNNING run instead of starting a competing walk over the same catalog.
 *  3. THE CORRELATION ID. Minted here, at the inbound edge, and carried by every job,
 *     log line and activity event of the run.
 */
final readonly class StartShopifySync
{
    // === CONSTANTS ===
    // The default "import all" ceiling; a platform admin may raise it per platform.
    private const CFG_SOFT_CAP = 'shopify.import.soft_cap';

    private const DEFAULT_SOFT_CAP = 1000;

    // How many GIDs one selection import may carry (a picker page, not a catalog).
    private const CFG_SELECTION_MAX = 'shopify.import.selection_max';

    private const DEFAULT_SELECTION_MAX = 250;

    private const CORRELATION_PREFIX = 'sync_';

    private const CORRELATION_BYTES = 12;

    public function __construct(
        private ShopifyProductSource $source,
        private PlatformSettings $settings,
        private ActivityRecorder $activity,
    ) {}

    /**
     * Walk the whole catalog — IF the store is inside the soft cap.
     *
     * THE CAP IS A GUARD, NOT A WARNING. Over the cap, this opens no run, dispatches no
     * job, and returns a typed refusal: the merchant picks products instead. An unmeasurable
     * catalog (Shopify would not answer the count) is refused for the same reason — walking
     * blind is exactly what the cap exists to prevent.
     */
    public function catalog(Site $site): StartSyncResult
    {
        $active = $this->activeRun($site);

        if ($active !== null) {
            return StartSyncResult::joined($active); // one walk at a time; a second click joins the first
        }

        $cap = $this->softCap();

        try {
            $size = $this->catalogSize($site);
        } catch (ShopifyApiException) {
            return StartSyncResult::refusedSizeUnavailable($cap);
        }

        if ($this->exceedsCap($size)) {
            return StartSyncResult::refusedOverCap($size, $cap);
        }

        $run = $this->open($site, ShopifySyncRun::MODE_CATALOG, null);

        SyncShopifyCatalogJob::dispatch((int) $site->account_id, (int) $site->getKey(), (int) $run->getKey(), null);

        return StartSyncResult::started($run);
    }

    /**
     * Import an explicit set of products the merchant picked. Never archives anything —
     * a subset import says nothing about the products it did not include. Bounded by
     * selection_max (a picker page, not a catalog), so it needs no cap probe.
     *
     * THE BOUND IS REPORTED, NOT SWALLOWED. Picks past selection_max are not imported, so the
     * run is marked TRUNCATED and the count of dropped picks rides back on the typed result —
     * a silent slice would tell the merchant "imported" about products nothing ever touched.
     *
     * @param  array<int,string>  $gids
     */
    public function selection(Site $site, array $gids): StartSyncResult
    {
        $requested = $this->normaliseGids($gids);
        $accepted = array_slice($requested, 0, $this->selectionMax());
        $dropped = count($requested) - count($accepted);

        $run = $this->open($site, ShopifySyncRun::MODE_SELECTION, $accepted, $dropped);

        if ($dropped > 0) {
            $run->markTruncated(ShopifySyncRun::TRUNCATION_SELECTION_MAX);
        }

        foreach ($accepted as $gid) {
            SyncShopifyProductJob::dispatch(
                (int) $site->account_id,
                (int) $site->getKey(),
                $gid,
                (int) $run->getKey(),
            );
        }

        return StartSyncResult::started($run, $dropped, $this->selectionMax());
    }

    /** The site's in-flight run, if any (pending or running). */
    public function activeRun(Site $site): ?ShopifySyncRun
    {
        return ShopifySyncRun::query()
            ->where('site_id', $site->getKey())
            ->whereIn('status', [ShopifySyncRun::STATUS_PENDING, ShopifySyncRun::STATUS_RUNNING])
            ->latest('id')
            ->first();
    }

    /** How many products an "import all" would walk right now (for the cap warning). */
    public function catalogSize(Site $site): int
    {
        return $this->source->count($site);
    }

    /**
     * The effective import ceiling: the platform-admin override when set, else the
     * config default. NEVER a literal at a call site.
     */
    public function softCap(): int
    {
        $override = $this->settings->get(PlatformSettings::SHOPIFY_IMPORT_CAP);

        if (is_string($override) && ctype_digit($override) && (int) $override > 0) {
            return (int) $override;
        }

        return (int) (config(self::CFG_SOFT_CAP) ?? self::DEFAULT_SOFT_CAP);
    }

    /** True when the store holds more products than one "import all" may take. */
    public function exceedsCap(int $catalogSize): bool
    {
        return $catalogSize > $this->softCap();
    }

    /** @param array<int,string>|null $gids */
    private function open(Site $site, string $mode, ?array $gids, int $dropped = 0): ShopifySyncRun
    {
        $run = ShopifySyncRun::query()->create([
            'site_id' => $site->getKey(),
            'mode' => $mode,
            'status' => ShopifySyncRun::STATUS_PENDING,
            'requested_gids' => $gids,
            'correlation_id' => self::CORRELATION_PREFIX.Str::random(self::CORRELATION_BYTES),
        ]);

        $this->activity->record(
            kind: ActivityEvent::KIND_SHOPIFY_SYNC_STARTED,
            subject: $run,
            details: [
                'mode' => $mode,
                'requested' => $gids === null ? null : count($gids),
                // The picks the bound dropped — on the timeline, not swallowed.
                'dropped' => $dropped,
                'correlation_id' => $run->correlation_id,
            ],
            siteId: (int) $site->getKey(),
            actor: ActivityEvent::ACTOR_MERCHANT,
        );

        return $run;
    }

    /**
     * De-duplicate, drop blanks and normalise bare ids to GIDs. It does NOT bound the list:
     * bounding is selection()'s job, because the caller must be TOLD what was left out.
     *
     * @param  array<int,string>  $gids
     * @return array<int,string>
     */
    private function normaliseGids(array $gids): array
    {
        $normalised = [];

        foreach ($gids as $gid) {
            $gid = trim((string) $gid);

            if ($gid === '') {
                continue;
            }

            $normalised[] = ShopifyGid::for(ShopifyGid::TYPE_PRODUCT, $gid);
        }

        return array_values(array_unique($normalised));
    }

    /** How many products ONE selection import may carry (a picker page, not a catalog). */
    public function selectionMax(): int
    {
        return (int) (config(self::CFG_SELECTION_MAX) ?? self::DEFAULT_SELECTION_MAX);
    }
}
