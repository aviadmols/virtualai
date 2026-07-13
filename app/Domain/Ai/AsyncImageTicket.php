<?php

namespace App\Domain\Ai;

/**
 * AsyncImageTicket — the receipt for ONE image generation submitted to a provider QUEUE.
 *
 * It is the anti-double-submit anchor of the async money path: the moment a provider accepts
 * a submit it hands back a request id, we persist this ticket on the asset, and from then on
 * a retry can only ever POLL that same request. A network blip therefore re-polls; it never
 * re-submits (which would render — and bill — the image twice upstream).
 *
 * It is stored on product_assets.provider_meta as a plain array (toArray/fromArray), so a
 * worker restart or a re-dispatched poller can rebuild it verbatim from the DB.
 */
final readonly class AsyncImageTicket
{
    // === CONSTANTS ===
    public const KEY_PROVIDER = 'provider';

    public const KEY_MODEL = 'model';

    public const KEY_REQUEST_ID = 'request_id';

    public const KEY_STATUS_URL = 'status_url';

    public const KEY_RESULT_URL = 'result_url';

    public function __construct(
        public string $provider,
        public string $model,
        public string $requestId,
        public ?string $statusUrl = null,
        public ?string $resultUrl = null,
    ) {}

    /** @return array<string,string|null> */
    public function toArray(): array
    {
        return [
            self::KEY_PROVIDER => $this->provider,
            self::KEY_MODEL => $this->model,
            self::KEY_REQUEST_ID => $this->requestId,
            self::KEY_STATUS_URL => $this->statusUrl,
            self::KEY_RESULT_URL => $this->resultUrl,
        ];
    }

    /** Rebuild from the persisted provider_meta bag. Null when the bag is unusable. */
    public static function fromArray(mixed $bag): ?self
    {
        if (! is_array($bag)) {
            return null;
        }

        $provider = $bag[self::KEY_PROVIDER] ?? null;
        $model = $bag[self::KEY_MODEL] ?? null;
        $requestId = $bag[self::KEY_REQUEST_ID] ?? null;

        if (! is_string($provider) || ! is_string($model) || ! is_string($requestId) || $requestId === '') {
            return null;
        }

        return new self(
            provider: $provider,
            model: $model,
            requestId: $requestId,
            statusUrl: is_string($bag[self::KEY_STATUS_URL] ?? null) ? $bag[self::KEY_STATUS_URL] : null,
            resultUrl: is_string($bag[self::KEY_RESULT_URL] ?? null) ? $bag[self::KEY_RESULT_URL] : null,
        );
    }
}
