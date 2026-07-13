<?php

namespace Tests\Feature\Shopify;

use App\Domain\Shopify\Media\ShopifyMediaItem;
use App\Models\Account;
use App\Models\Product;
use App\Models\ProductAsset;
use App\Models\ProductImageBatch;
use App\Models\ShopifyConnection;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Mockery;

/**
 * A FAKE Shopify store that actually behaves like one — the media rail's test double.
 *
 * It is stateful on purpose: productCreateMedia really appends to a gallery, productReorderMedia
 * really moves things, productDeleteMedia really removes them, and a created media starts
 * UPLOADED and only becomes READY after $readyAfterPolls status reads. Otherwise the tests could
 * not tell "we deleted the replaced image AFTER the replacement went READY" from "we deleted it
 * first and got lucky".
 *
 * ONE Http stub, a swappable responder (TS-BUILD-004: Http::fake() is install-once — a later
 * fake for the same pattern does NOT replace an earlier one).
 * Call counts come from Http::recorded(), never from a counter inside a stub closure
 * (TS-BUILD-008: every matching stub closure runs, so a counter there is nonsense).
 */
trait ShopifyMediaTestSupport
{
    // === CONSTANTS ===
    protected const MEDIA_SHOP = 'trayon-media.myshopify.com';

    protected const MEDIA_SHOP_B = 'trayon-media-b.myshopify.com';

    protected const MEDIA_GID = 'gid://shopify/Product/9001';

    protected const STAGED_URL = 'https://shopify-staged-uploads.storage.example/upload';

    protected const RESOURCE_URL = 'https://shopify-staged-uploads.storage.example/tmp/resource-1';

    protected const ORIGINAL_CDN = 'https://cdn.shopify.com/original-';

    protected const ORIGINAL_BYTES = "\x89PNG\r\n\x1a\nORIGINAL-";

    protected const ASSET_BYTES = "\x89PNG\r\n\x1a\nGENERATED-PACKSHOT";

    // A media Shopify holds that is NOT a downloadable image (a video / a 3D model). We can never
    // hold its bytes, so a REPLACE that targets it must be REFUSED — deleting it is irreversible.
    protected const VIDEO_ID = 'gid://shopify/Video/777';

    protected const MEDIA_TYPE_IMAGE = 'IMAGE';

    protected const MEDIA_TYPE_VIDEO = 'VIDEO';

    /** The fake store's gallery: ordered list of ['id','status','alt','url','type']. */
    protected array $storeGallery = [];

    /** Sequence for minting new media ids. */
    protected int $storeMediaSeq = 0;

    /** How many status reads a NEW media stays UPLOADED before it turns READY. */
    protected int $readyAfterPolls = 1;

    /** media id => how many times its status has been read. */
    protected array $storeStatusReads = [];

    /** The CDN download of an original fails (the snapshot must then FAIL CLOSED). */
    protected bool $originalDownloadBroken = false;

    /** productCreateMedia answers with a mediaUserErrors bag instead of a media. */
    protected ?string $createMediaUserError = null;

    /** The next GraphQL call is THROTTLED (a 200 + a THROTTLED extensions code). */
    protected bool $throttleNext = false;

    /** An ORDERED log of every media mutation the store received (the order-of-operations proof). */
    protected array $storeLog = [];

    /** The gallery read LIES: it claims a next page but hands back no cursor (the truncation trap). */
    protected bool $galleryCursorBroken = false;

    /** Shopify processes a NEW media to FAILED instead of READY (a corrupt/rejected image). */
    protected bool $processingFails = false;

    /** The snapshot objects VANISH from our disk mid-capture (a purge racing the capture). */
    protected bool $purgeSnapshotsMidCapture = false;

    protected function bootShopifyMediaEnv(): void
    {
        config()->set('trayon.media.disk', 's3');
        config()->set('shopify.media.ready_delay_seconds', 1);
        config()->set('shopify.media.ready_attempts', 5);

        Storage::fake('s3');
        Sleep::fake();
    }

    /**
     * Swap the media disk for one that REFUSES every write the way a real disk does with
     * `throw => false`: put() returns FALSE and the object never appears. Nothing may be stamped
     * "stored" on the strength of that.
     */
    protected function breakMediaDiskWrites(): void
    {
        $broken = Mockery::mock(Filesystem::class);
        $broken->shouldReceive('put')->andReturnFalse();
        $broken->shouldReceive('exists')->andReturnFalse();
        $broken->shouldReceive('size')->andReturnFalse();
        $broken->shouldReceive('get')->andReturnNull();
        $broken->shouldReceive('delete')->andReturnTrue();

        Storage::set('s3', $broken);
    }

    /**
     * The disk REFUSES the write (put() -> FALSE) while SOMETHING is readable at that path — a
     * half-written object from an aborted upload, a retried key. The readback alone would be
     * fooled; the put() boolean is the only honest signal that our bytes did not land.
     */
    protected function breakMediaDiskWritesOverStaleBytes(): void
    {
        $broken = Mockery::mock(Filesystem::class);
        $broken->shouldReceive('put')->andReturnFalse();
        $broken->shouldReceive('exists')->andReturnTrue();
        $broken->shouldReceive('size')->andReturn(strlen(self::ORIGINAL_BYTES));
        $broken->shouldReceive('get')->andReturn(self::ORIGINAL_BYTES);
        $broken->shouldReceive('delete')->andReturnTrue();

        Storage::set('s3', $broken);
    }

    /**
     * The nastier disk: put() says YES and the object is NOT there (a lying / eventually-consistent
     * backend). A write ATTEMPTED is not a write VERIFIED — the readback is what catches this.
     */
    protected function breakMediaDiskReadback(): void
    {
        $broken = Mockery::mock(Filesystem::class);
        $broken->shouldReceive('put')->andReturnTrue();
        $broken->shouldReceive('exists')->andReturnFalse();
        $broken->shouldReceive('size')->andReturnFalse();
        $broken->shouldReceive('get')->andReturnNull();
        $broken->shouldReceive('delete')->andReturnTrue();

        Storage::set('s3', $broken);
    }

    /**
     * A connected shop + a CONFIRMED Shopify product with $originals images already in the store.
     *
     * `shop_domain` is GLOBALLY unique (it is the pre-bind webhook routing key), so a second
     * tenant must carry a second domain — the isolation tests build two.
     */
    protected function mediaShop(int $originals = 2, string $shopDomain = self::MEDIA_SHOP): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create(['platform' => Site::PLATFORM_SHOPIFY]);

        Tenant::run($account, fn (): ShopifyConnection => ShopifyConnection::factory()
            ->forSite($site)
            ->create(['shop_domain' => $shopDomain]));

        $product = Tenant::run($account, fn (): Product => Product::factory()->forSite($site)->confirmed()->create([
            'name' => 'Merino Crew',
            'product_type' => 'Sweaters',
            'source' => Product::SOURCE_SHOPIFY,
            'external_id' => self::MEDIA_GID,
        ]));

        $this->seedGallery($originals);

        return compact('account', 'site', 'product');
    }

    /** An APPROVED, succeeded asset whose bytes are really on the (faked) media disk. */
    protected function approvedAsset(array $shop, array $state = []): ProductAsset
    {
        return Tenant::run($shop['account'], function () use ($shop, $state): ProductAsset {
            $batch = ProductImageBatch::factory()->forSite($shop['site'])->create();

            $asset = ProductAsset::factory()
                ->forProduct($shop['product'], $batch)
                ->approved()
                ->create($state);

            Storage::disk('s3')->put((string) $asset->image_path, self::ASSET_BYTES);

            return $asset;
        });
    }

    /** Fill the fake store's gallery with $count READY originals. */
    protected function seedGallery(int $count): void
    {
        $this->storeGallery = [];

        for ($i = 1; $i <= $count; $i++) {
            $this->storeGallery[] = [
                'id' => 'gid://shopify/MediaImage/'.(100 + $i),
                'status' => ShopifyMediaItem::STATUS_READY,
                'alt' => 'original '.$i,
                'url' => self::ORIGINAL_CDN.$i.'.png',
                'type' => self::MEDIA_TYPE_IMAGE,
            ];
        }
    }

    /**
     * Append a media we can NEVER hold the bytes of (a video / a 3D model): Shopify exposes no
     * downloadable image url for it, so the snapshot records it with a null path.
     */
    protected function seedVideo(): string
    {
        $this->storeGallery[] = [
            'id' => self::VIDEO_ID,
            'status' => ShopifyMediaItem::STATUS_READY,
            'alt' => 'the product film',
            'url' => null,
            'type' => self::MEDIA_TYPE_VIDEO,
        ];

        return self::VIDEO_ID;
    }

    /** The gallery's media ids, in order (position 1 first). @return array<int,string> */
    protected function galleryIds(): array
    {
        return array_map(static fn (array $m): string => (string) $m['id'], $this->storeGallery);
    }

    /** The current featured image (slot 1). */
    protected function featuredId(): ?string
    {
        return $this->storeGallery[0]['id'] ?? null;
    }

    /** How many media were CREATED on the store (the "exactly one media" proof). */
    protected function createdMediaCount(): int
    {
        return count(array_filter($this->storeLog, static fn (array $e): bool => $e['op'] === 'create'));
    }

    /** The ordered mutation log (create / reorder / delete) — the order-of-operations proof. */
    protected function storeOps(): array
    {
        return array_map(static fn (array $e): string => $e['op'], $this->storeLog);
    }

    /**
     * Install the ONE Http stub. It answers:
     *  - the Admin GraphQL endpoint (staged upload, create, read, reorder, delete),
     *  - the staged upload target (the byte transfer),
     *  - the Shopify CDN (downloading an original for the snapshot).
     */
    protected function fakeShopifyStore(): void
    {
        Http::fake([
            '*/admin/api/*/graphql.json' => fn (Request $request) => $this->answerGraphQL($request),

            self::STAGED_URL.'*' => fn () => Http::response('', 201),

            self::ORIGINAL_CDN.'*' => fn (Request $request) => $this->answerOriginalDownload($request),
        ]);
    }

    /**
     * The Shopify CDN handing us an original's bytes — and the seam where the disk can be pulled
     * out from under a capture that is already half-written (a purge / lifecycle rule racing us).
     */
    private function answerOriginalDownload(Request $request)
    {
        if ($this->originalDownloadBroken) {
            return Http::response('nope', 500);
        }

        if ($this->purgeSnapshotsMidCapture) {
            // Everything this capture has written so far silently disappears. The entries still
            // carry their paths — and those paths now point at NOTHING.
            Storage::disk('s3')->deleteDirectory('accounts');
        }

        return Http::response(self::ORIGINAL_BYTES.basename($request->url()), 200, ['Content-Type' => 'image/png']);
    }

    /** The fake store's GraphQL brain. */
    private function answerGraphQL(Request $request)
    {
        $body = json_decode($request->body(), true) ?? [];
        $query = (string) ($body['query'] ?? '');
        $vars = (array) ($body['variables'] ?? []);

        if ($this->throttleNext) {
            $this->throttleNext = false;

            return Http::response([
                'errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]],
            ], 200, ['Retry-After' => '1']);
        }

        return match (true) {
            str_contains($query, 'TrayOnStagedUpload') => $this->answerStagedUpload(),
            str_contains($query, 'TrayOnCreateMedia') => $this->answerCreateMedia($vars),
            str_contains($query, 'TrayOnProductMedia') => $this->answerProductMedia($vars),
            str_contains($query, 'TrayOnReorderMedia') => $this->answerReorder($vars),
            str_contains($query, 'TrayOnDeleteMedia') => $this->answerDelete($vars),
            default => Http::response(['data' => []], 200),
        };
    }

    private function answerStagedUpload()
    {
        return Http::response(['data' => ['stagedUploadsCreate' => [
            'stagedTargets' => [[
                'url' => self::STAGED_URL,
                'resourceUrl' => self::RESOURCE_URL,
                'parameters' => [['name' => 'key', 'value' => 'tmp/resource-1']],
            ]],
            'userErrors' => [],
        ]]], 200);
    }

    private function answerCreateMedia(array $vars)
    {
        if ($this->createMediaUserError !== null) {
            return Http::response(['data' => ['productCreateMedia' => [
                'media' => [],
                'mediaUserErrors' => [['field' => ['media'], 'message' => $this->createMediaUserError, 'code' => 'INVALID']],
            ]]], 200);
        }

        $id = 'gid://shopify/MediaImage/'.(900 + (++$this->storeMediaSeq));
        $alt = (string) (((array) (((array) ($vars['media'] ?? []))[0] ?? []))['alt'] ?? '');

        // Appended at the END, and NOT ready yet — Shopify processes media asynchronously.
        $this->storeGallery[] = [
            'id' => $id,
            'status' => ShopifyMediaItem::STATUS_UPLOADED,
            'alt' => $alt,
            'url' => 'https://cdn.shopify.com/new-'.$this->storeMediaSeq.'.png',
            'type' => self::MEDIA_TYPE_IMAGE,
        ];

        $this->storeStatusReads[$id] = 0;
        $this->storeLog[] = ['op' => 'create', 'id' => $id, 'gallery' => $this->galleryIds()];

        return Http::response(['data' => ['productCreateMedia' => [
            'media' => [['id' => $id, 'status' => ShopifyMediaItem::STATUS_UPLOADED, 'alt' => $alt, 'mediaContentType' => 'IMAGE', 'image' => null]],
            'mediaUserErrors' => [],
        ]]], 200);
    }

    /**
     * Reading the gallery is what advances a new media from UPLOADED to READY.
     *
     * It is a REAL relay-style connection: it honours `first` + `after` and answers `pageInfo`, so
     * a gallery bigger than one page really does need the caller to walk the cursor. A caller that
     * reads only the first page really does see a TRUNCATED gallery — which is exactly the bug the
     * pagination tests exist to catch.
     */
    private function answerProductMedia(array $vars)
    {
        $first = max(1, (int) ($vars['first'] ?? 50));
        $after = (string) ($vars['after'] ?? '');
        $offset = $after === '' ? 0 : (int) $after;

        $page = array_slice($this->storeGallery, $offset, $first, true);
        $nodes = [];

        foreach ($page as $index => $media) {
            if ($media['status'] === ShopifyMediaItem::STATUS_UPLOADED) {
                $this->storeStatusReads[$media['id']] = ($this->storeStatusReads[$media['id']] ?? 0) + 1;

                if ($this->processingFails) {
                    $this->storeGallery[$index]['status'] = ShopifyMediaItem::STATUS_FAILED;
                } elseif ($this->storeStatusReads[$media['id']] >= $this->readyAfterPolls) {
                    $this->storeGallery[$index]['status'] = ShopifyMediaItem::STATUS_READY;
                }
            }

            $current = $this->storeGallery[$index];
            $isImage = ($current['type'] ?? self::MEDIA_TYPE_IMAGE) === self::MEDIA_TYPE_IMAGE && $current['url'] !== null;

            $nodes[] = [
                'id' => $current['id'],
                'status' => $current['status'],
                'alt' => $current['alt'],
                'mediaContentType' => $current['type'] ?? self::MEDIA_TYPE_IMAGE,
                'image' => $isImage ? ['url' => $current['url'], 'width' => 800, 'height' => 800] : null,
            ];
        }

        $end = $offset + count($nodes);
        $hasNext = $end < count($this->storeGallery);

        return Http::response(['data' => ['product' => [
            'id' => self::MEDIA_GID,
            'media' => [
                'nodes' => $nodes,
                'pageInfo' => [
                    'hasNextPage' => $hasNext,
                    // The truncation trap: "there is more" with NO cursor to get it. A reader that
                    // trusts the nodes it already has would snapshot a PARTIAL gallery as complete.
                    'endCursor' => $this->galleryCursorBroken ? null : ($hasNext ? (string) $end : null),
                ],
            ],
        ]]], 200);
    }

    private function answerReorder(array $vars)
    {
        foreach ((array) ($vars['moves'] ?? []) as $move) {
            $id = (string) (((array) $move)['id'] ?? '');
            $to = (int) (((array) $move)['newPosition'] ?? 0);

            $from = null;

            foreach ($this->storeGallery as $index => $media) {
                if ($media['id'] === $id) {
                    $from = $index;
                    break;
                }
            }

            if ($from === null) {
                continue;
            }

            $moved = array_splice($this->storeGallery, $from, 1);
            array_splice($this->storeGallery, min($to, count($this->storeGallery)), 0, $moved);
        }

        $this->storeLog[] = ['op' => 'reorder', 'gallery' => $this->galleryIds()];

        return Http::response(['data' => ['productReorderMedia' => [
            'job' => ['id' => 'gid://shopify/Job/1', 'done' => true],
            'mediaUserErrors' => [],
        ]]], 200);
    }

    private function answerDelete(array $vars)
    {
        $ids = array_map('strval', (array) ($vars['mediaIds'] ?? []));

        // The proof the "never delete before READY" law needs: record the state of every deleted
        // media AT THE MOMENT OF DELETION, plus the whole gallery. A test can then assert the
        // replacement was READY (and in the gallery) when its predecessor was removed.
        $this->storeLog[] = [
            'op' => 'delete',
            'ids' => $ids,
            'gallery' => $this->galleryIds(),
            'statuses' => array_combine(
                $this->galleryIds(),
                array_map(static fn (array $m): string => (string) $m['status'], $this->storeGallery),
            ),
        ];

        $this->storeGallery = array_values(array_filter(
            $this->storeGallery,
            static fn (array $m): bool => ! in_array($m['id'], $ids, true),
        ));

        return Http::response(['data' => ['productDeleteMedia' => [
            'deletedMediaIds' => $ids,
            'mediaUserErrors' => [],
        ]]], 200);
    }

    /** The mutation-log entry of the delete (null when nothing was ever deleted). */
    protected function deleteEntry(): ?array
    {
        foreach ($this->storeLog as $entry) {
            if ($entry['op'] === 'delete' && $entry['ids'] !== []) {
                return $entry;
            }
        }

        return null;
    }
}
