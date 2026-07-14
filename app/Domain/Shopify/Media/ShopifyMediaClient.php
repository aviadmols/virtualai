<?php

namespace App\Domain\Shopify\Media;

use App\Domain\Shopify\Api\ShopifyGraphQLClient;
use App\Models\ShopifyConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * ShopifyMediaClient — the typed media surface of the Admin API. It owns the GraphQL round
 * trips and the byte transfer; it owns NO policy (the pusher decides what to do and in which
 * order, and it is the only thing allowed to delete).
 *
 * Three properties are LOCKED here:
 *
 *  1. OUR BUCKET STAYS PRIVATE. The image is never made public and never handed to Shopify as
 *     a URL of ours. We fetch a one-time staged target and POST the raw BYTES to it, so nothing
 *     of ours is ever fetchable without a signature.
 *
 *  2. SHOPIFY'S SIDE IS ASYNCHRONOUS. productCreateMedia answers with an UPLOADED media, not a
 *     READY one. awaitReady() polls until READY (bounded), and a FAILED media is a typed failure
 *     — never a silent success. Nothing destructive may run before this returns.
 *
 *  3. mediaUserErrors ARE THE MERCHANT'S EXPLANATION. Every mutation surfaces them VERBATIM as a
 *     typed ShopifyMediaException; they are never swallowed or paraphrased.
 *
 * Throttling is inherited from ShopifyGraphQLClient (Retry-After honoured, then a typed
 * CODE_THROTTLED the push job PARKS on — a park is not a failure).
 */
final class ShopifyMediaClient
{
    // === CONSTANTS ===
    private const CFG_READY_ATTEMPTS = 'shopify.media.ready_attempts';

    private const CFG_READY_DELAY = 'shopify.media.ready_delay_seconds';

    private const CFG_PER_PRODUCT = 'shopify.media.per_product';

    // The gallery read is PAGINATED; this bounds the walk (per_product x max_pages must cover
    // Shopify's 250-media-per-product ceiling, or a big gallery FAILS CLOSED instead of truncating).
    private const CFG_MAX_PAGES = 'shopify.media.max_pages';

    private const DEFAULT_READY_ATTEMPTS = 20;

    private const DEFAULT_READY_DELAY = 3;

    private const DEFAULT_PER_PRODUCT = 50;

    private const DEFAULT_MAX_PAGES = 10;

    // stagedUploadsCreate input.
    private const RESOURCE_PRODUCT_IMAGE = 'PRODUCT_IMAGE';

    private const HTTP_METHOD_POST = 'POST';

    private const MEDIA_CONTENT_TYPE_IMAGE = 'IMAGE';

    // The multipart field the staged target expects the bytes under (Shopify/GCS contract).
    private const UPLOAD_FILE_FIELD = 'file';

    private const UPLOAD_TIMEOUT_SECONDS = 60;

    // Response keys.
    private const KEY_STAGED = 'stagedUploadsCreate';

    private const KEY_STAGED_TARGETS = 'stagedTargets';

    private const KEY_CREATE = 'productCreateMedia';

    private const KEY_REORDER = 'productReorderMedia';

    private const KEY_DELETE = 'productDeleteMedia';

    private const KEY_MEDIA = 'media';

    private const KEY_MEDIA_USER_ERRORS = 'mediaUserErrors';

    private const KEY_USER_ERRORS = 'userErrors';

    private const KEY_DELETED_IDS = 'deletedMediaIds';

    private const KEY_PRODUCT = 'product';

    // The targeted single-media read (the READY poll) — cost 1, instead of a whole gallery walk.
    private const KEY_NODE = 'node';

    private const KEY_NODES = 'nodes';

    private const KEY_PAGE_INFO = 'pageInfo';

    private const KEY_HAS_NEXT = 'hasNextPage';

    private const KEY_END_CURSOR = 'endCursor';

    public function __construct(
        private readonly ShopifyGraphQLClient $client,
    ) {}

    /**
     * Hand Shopify the raw BYTES through a one-time staged target and return the `resourceUrl`
     * productCreateMedia consumes. Our bucket is never touched by Shopify and never made public.
     *
     * @throws ShopifyMediaException
     */
    public function upload(ShopifyConnection $connection, string $bytes, string $filename, string $mime): string
    {
        $data = $this->client->query($connection, ShopifyMediaQueries::stagedUploadsCreate(), [
            'input' => [[
                'filename' => $filename,
                'mimeType' => $mime,
                'resource' => self::RESOURCE_PRODUCT_IMAGE,
                'httpMethod' => self::HTTP_METHOD_POST,
                'fileSize' => (string) strlen($bytes),
            ]],
        ]);

        $payload = (array) ($data[self::KEY_STAGED] ?? []);
        $this->assertNoUserErrors((array) ($payload[self::KEY_USER_ERRORS] ?? []));

        $target = (array) (((array) ($payload[self::KEY_STAGED_TARGETS] ?? []))[0] ?? []);
        $url = (string) ($target['url'] ?? '');
        $resourceUrl = (string) ($target['resourceUrl'] ?? '');

        if ($url === '' || $resourceUrl === '') {
            throw ShopifyMediaException::stagedUpload('Shopify returned no staged upload target for the image.');
        }

        $this->postBytes($url, (array) ($target['parameters'] ?? []), $bytes, $filename, $mime);

        return $resourceUrl;
    }

    /**
     * Attach an uploaded resource to the product. The media comes back UPLOADED — Shopify has
     * NOT processed it yet, so nothing may be deleted or reordered on the strength of this call.
     *
     * @throws ShopifyMediaException
     */
    public function createMedia(ShopifyConnection $connection, string $productGid, string $resourceUrl, string $alt): ShopifyMediaItem
    {
        $data = $this->client->query($connection, ShopifyMediaQueries::productCreateMedia(), [
            'productId' => $productGid,
            'media' => [[
                'originalSource' => $resourceUrl,
                'alt' => $alt,
                'mediaContentType' => self::MEDIA_CONTENT_TYPE_IMAGE,
            ]],
        ]);

        $payload = (array) ($data[self::KEY_CREATE] ?? []);
        $this->assertNoMediaUserErrors($payload);

        $node = (array) (((array) ($payload[self::KEY_MEDIA] ?? []))[0] ?? []);

        if (($node['id'] ?? '') === '') {
            throw ShopifyMediaException::noMedia();
        }

        return ShopifyMediaItem::fromNode($node, MediaPlacement::FIRST_POSITION);
    }

    /**
     * The product's WHOLE gallery, in Shopify's order, positions rebased to 1 (1 = the featured
     * image).
     *
     * IT IS PAGINATED, AND IT FAILS CLOSED. Shopify allows up to 250 media on a product while one
     * page returns far fewer, so a single-page read of a big gallery returns a TRUNCATED list that
     * looks complete. That list was snapshotted as "the originals", stamped CAPTURED, and licensed
     * a destructive push whose undo could not restore the media it never saw. So every page is
     * walked to `hasNextPage: false`; a gallery we cannot read to its end is a typed exception, not
     * a shorter gallery.
     *
     * @return array<int,ShopifyMediaItem>
     *
     * @throws ShopifyMediaException
     */
    public function gallery(ShopifyConnection $connection, string $productGid): array
    {
        $items = [];
        $position = MediaPlacement::FIRST_POSITION;
        $after = null;

        for ($page = 1; $page <= $this->maxPages(); $page++) {
            $data = $this->client->query($connection, ShopifyMediaQueries::productMedia(), [
                'id' => $productGid,
                'first' => $this->perProduct(),
                'after' => $after,
            ]);

            $media = (array) (((array) ($data[self::KEY_PRODUCT] ?? []))[self::KEY_MEDIA] ?? []);

            foreach ((array) ($media[self::KEY_NODES] ?? []) as $node) {
                $items[] = ShopifyMediaItem::fromNode((array) $node, $position++);
            }

            $pageInfo = (array) ($media[self::KEY_PAGE_INFO] ?? []);

            if (($pageInfo[self::KEY_HAS_NEXT] ?? false) !== true) {
                return $items;
            }

            $after = (string) ($pageInfo[self::KEY_END_CURSOR] ?? '');

            if ($after === '') {
                throw ShopifyMediaException::galleryUnread($productGid, count($items)); // more pages, no cursor
            }
        }

        throw ShopifyMediaException::galleryUnread($productGid, count($items)); // the page budget ran out
    }

    /**
     * Poll until this media is READY.
     *
     * THIS IS THE GATE EVERY DESTRUCTIVE STEP WAITS BEHIND. A replaced image is deleted only
     * after its replacement returns from here; a FAILED media, or an exhausted budget, is a typed
     * exception — so the delete NEVER runs on an image that is not actually in the store.
     *
     * IT POLLS ONE NODE, NOT THE WHOLE GALLERY. Re-reading the paginated gallery on each of 20
     * attempts cost up to 200 cost-weighted GraphQL calls per media on a large product — on the one
     * rail that must not throttle (a throttle here parks the push and makes the merchant wait
     * another 30 seconds). node(id:) asks for exactly the thing we are waiting on.
     *
     * @throws ShopifyMediaException
     */
    public function awaitReady(ShopifyConnection $connection, string $productGid, string $mediaId): ShopifyMediaItem
    {
        $attempts = $this->readyAttempts();

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $item = $this->node($connection, $mediaId);

            if ($item !== null && $item->isFailed()) {
                throw ShopifyMediaException::processingFailed($mediaId);
            }

            if ($item !== null && $item->isReady()) {
                return $item;
            }

            if ($attempt < $attempts) {
                Sleep::for($this->readyDelay())->seconds();
            }
        }

        throw ShopifyMediaException::notReady($mediaId, $attempts);
    }

    /**
     * ONE media by id — a single cheap lookup, with no position (the caller that needs a position
     * reads the gallery). Null when Shopify no longer holds it.
     */
    public function node(ShopifyConnection $connection, string $mediaId): ?ShopifyMediaItem
    {
        $data = $this->client->query($connection, ShopifyMediaQueries::mediaNode(), ['id' => $mediaId]);

        $node = (array) ($data[self::KEY_NODE] ?? []);

        if ((string) ($node['id'] ?? '') === '') {
            return null;
        }

        return ShopifyMediaItem::fromNode($node, MediaPlacement::FIRST_POSITION);
    }

    /**
     * One media of a product WITH ITS POSITION — the only thing the gallery walk is still needed
     * for (place() has to know which slot the replaced image occupies). Null when it is gone.
     */
    public function find(ShopifyConnection $connection, string $productGid, string $mediaId): ?ShopifyMediaItem
    {
        foreach ($this->gallery($connection, $productGid) as $item) {
            if ($item->id === $mediaId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Move media into slots. Positions in are the merchant-facing 1-BASED slots; Shopify's
     * MoveInput is ZERO-based — the conversion lives HERE and nowhere else.
     *
     * @param  array<string,int>  $moves  media id => 1-based target slot (evaluated in order)
     *
     * @throws ShopifyMediaException
     */
    public function reorder(ShopifyConnection $connection, string $productGid, array $moves): void
    {
        if ($moves === []) {
            return;
        }

        $payloadMoves = [];

        foreach ($moves as $mediaId => $position) {
            $payloadMoves[] = [
                'id' => $mediaId,
                'newPosition' => (string) max(0, $position - MediaPlacement::FIRST_POSITION),
            ];
        }

        $data = $this->client->query($connection, ShopifyMediaQueries::productReorderMedia(), [
            'id' => $productGid,
            'moves' => $payloadMoves,
        ]);

        $this->assertNoMediaUserErrors((array) ($data[self::KEY_REORDER] ?? []));
    }

    /**
     * Delete media from a product. The ONLY caller that may reach this for a REPLACE is the
     * pusher, and only AFTER awaitReady() has confirmed the replacement is live.
     *
     * @param  array<int,string>  $mediaIds
     * @return array<int,string> the ids Shopify actually deleted
     *
     * @throws ShopifyMediaException
     */
    public function deleteMedia(ShopifyConnection $connection, string $productGid, array $mediaIds): array
    {
        if ($mediaIds === []) {
            return [];
        }

        $data = $this->client->query($connection, ShopifyMediaQueries::productDeleteMedia(), [
            'productId' => $productGid,
            'mediaIds' => array_values($mediaIds),
        ]);

        $payload = (array) ($data[self::KEY_DELETE] ?? []);
        $this->assertNoMediaUserErrors($payload);

        return array_map('strval', (array) ($payload[self::KEY_DELETED_IDS] ?? []));
    }

    /**
     * POST the raw bytes to the one-time staged target. The target's own parameters must be sent
     * FIRST and the file part LAST (the storage backend's signed-policy contract), so the
     * multipart parts are built in an explicit order — never left to a map's iteration.
     *
     * @param  array<int,array<string,mixed>>  $parameters
     *
     * @throws ShopifyMediaException
     */
    private function postBytes(string $url, array $parameters, string $bytes, string $filename, string $mime): void
    {
        $parts = [];

        foreach ($parameters as $parameter) {
            $parameter = (array) $parameter;

            $parts[] = [
                'name' => (string) ($parameter['name'] ?? ''),
                'contents' => (string) ($parameter['value'] ?? ''),
            ];
        }

        $parts[] = [
            'name' => self::UPLOAD_FILE_FIELD,
            'contents' => $bytes,
            'filename' => $filename,
            'headers' => ['Content-Type' => $mime],
        ];

        try {
            $response = Http::asMultipart()
                ->timeout(self::UPLOAD_TIMEOUT_SECONDS)
                ->post($url, $parts);
        } catch (Throwable $e) {
            throw ShopifyMediaException::stagedUpload(sprintf('Could not reach the staged upload target (%s).', $e::class));
        }

        if (! $response->successful()) {
            throw ShopifyMediaException::uploadFailed($response->status());
        }
    }

    /** mediaUserErrors are the merchant's explanation — surfaced verbatim, never swallowed. */
    private function assertNoMediaUserErrors(array $payload): void
    {
        $errors = (array) ($payload[self::KEY_MEDIA_USER_ERRORS] ?? []);

        if ($errors !== []) {
            throw ShopifyMediaException::fromMediaUserErrors($errors);
        }
    }

    private function assertNoUserErrors(array $errors): void
    {
        if ($errors !== []) {
            throw ShopifyMediaException::fromMediaUserErrors($errors);
        }
    }

    private function readyAttempts(): int
    {
        return max(1, (int) (config(self::CFG_READY_ATTEMPTS) ?? self::DEFAULT_READY_ATTEMPTS));
    }

    private function readyDelay(): int
    {
        return max(1, (int) (config(self::CFG_READY_DELAY) ?? self::DEFAULT_READY_DELAY));
    }

    private function perProduct(): int
    {
        return max(1, (int) (config(self::CFG_PER_PRODUCT) ?? self::DEFAULT_PER_PRODUCT));
    }

    private function maxPages(): int
    {
        return max(1, (int) (config(self::CFG_MAX_PAGES) ?? self::DEFAULT_MAX_PAGES));
    }
}
