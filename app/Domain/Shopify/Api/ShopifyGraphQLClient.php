<?php

namespace App\Domain\Shopify\Api;

use App\Models\ShopifyConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * ShopifyGraphQLClient — the ONE server-side door to the Shopify Admin API.
 *
 * What is LOCKED here and must not drift:
 *  - the API version is PINNED from config('shopify.api_version') — never "latest",
 *    never a literal at a call site;
 *  - the offline token is read from the connection's ENCRYPTED credentials and sent in
 *    the X-Shopify-Access-Token header — it is never logged, never returned, never
 *    placed in a URL;
 *  - every failure is a TYPED ShopifyApiException (transport / 401 / 429 / http /
 *    graphql), never a raw Throwable leaking a body into a log.
 *
 * THROTTLING (Phase 3). Shopify rate-limits the Admin API by a leaky-bucket QUERY COST,
 * and signals it two ways — a 429 with a `Retry-After` header, and (more often for
 * GraphQL) a 200 carrying `errors[].extensions.code = THROTTLED`. BOTH are recognised,
 * both honour `Retry-After` (else a bounded backoff), and both are retried a bounded
 * number of times before surfacing a typed CODE_THROTTLED exception — which a sync job
 * catches to PARK its cursor and resume later, rather than losing the run.
 */
final class ShopifyGraphQLClient
{
    // === CONSTANTS ===
    private const SCHEME = 'https://';

    private const PATH_TEMPLATE = '/admin/api/%s/graphql.json';

    private const HEADER_ACCESS_TOKEN = 'X-Shopify-Access-Token';

    private const HEADER_RETRY_AFTER = 'Retry-After';

    private const BODY_QUERY = 'query';

    private const BODY_VARIABLES = 'variables';

    private const RESPONSE_DATA = 'data';

    private const RESPONSE_ERRORS = 'errors';

    private const RESPONSE_EXTENSIONS = 'extensions';

    private const ERROR_CODE_KEY = 'code';

    // The GraphQL-level throttle signal (a 200 response with a THROTTLED error).
    private const ERROR_CODE_THROTTLED = 'THROTTLED';

    private const STATUS_THROTTLED = 429;

    private const CFG_API_VERSION = 'shopify.api_version';

    private const CFG_TIMEOUT = 'services.shopify.timeout';

    private const CFG_THROTTLE_RETRIES = 'shopify.throttle.max_retries';

    private const CFG_THROTTLE_BACKOFF = 'shopify.throttle.backoff_seconds';

    private const CFG_THROTTLE_MAX_WAIT = 'shopify.throttle.max_wait_seconds';

    private const DEFAULT_TIMEOUT = 30;

    private const DEFAULT_THROTTLE_RETRIES = 3;

    private const DEFAULT_BACKOFF_SECONDS = 2;

    private const DEFAULT_MAX_WAIT_SECONDS = 30;

    private const LOG_ERROR = 'shopify.api.error';

    private const LOG_THROTTLED = 'shopify.api.throttled';

    /**
     * Run a GraphQL document against the store's Admin API and return the `data` bag.
     * A throttle is retried (honouring Retry-After) up to the configured budget; when
     * the budget is spent the typed CODE_THROTTLED exception surfaces to the caller.
     *
     * @param  array<string,mixed>  $variables
     * @return array<string,mixed>
     *
     * @throws ShopifyApiException
     */
    public function query(ShopifyConnection $connection, string $query, array $variables = []): array
    {
        $shop = (string) $connection->shop_domain;
        $token = $connection->accessToken();

        if ($token === null) {
            throw ShopifyApiException::noAccessToken($shop);
        }

        $attempts = (int) (config(self::CFG_THROTTLE_RETRIES) ?? self::DEFAULT_THROTTLE_RETRIES);

        for ($attempt = 0; ; $attempt++) {
            $response = $this->send($shop, $token, $query, $variables);

            if ($response->status() === self::STATUS_THROTTLED) {
                $this->waitOrThrow($shop, $attempt, $attempts, $this->retryAfter($response));

                continue;
            }

            if (! $response->successful()) {
                Log::warning(self::LOG_ERROR, ['shop_domain' => $shop, 'status' => $response->status()]);

                throw ShopifyApiException::http($shop, $response->status());
            }

            $body = (array) ($response->json() ?? []);
            $errors = $body[self::RESPONSE_ERRORS] ?? null;

            if (is_array($errors) && $errors !== []) {
                if ($this->isThrottleError($errors)) {
                    $this->waitOrThrow($shop, $attempt, $attempts, $this->retryAfter($response));

                    continue;
                }

                $first = (string) ($errors[0]['message'] ?? 'unknown');

                Log::warning(self::LOG_ERROR, ['shop_domain' => $shop, 'code' => ShopifyApiException::CODE_GRAPHQL, 'message' => $first]);

                throw ShopifyApiException::graphql($shop, $first);
            }

            return (array) ($body[self::RESPONSE_DATA] ?? []);
        }
    }

    /** One HTTP round trip. A transport failure is typed, never a raw Throwable. */
    private function send(string $shop, string $token, string $query, array $variables): Response
    {
        try {
            return Http::withHeaders([self::HEADER_ACCESS_TOKEN => $token])
                ->asJson()
                ->acceptJson()
                ->timeout((int) (config(self::CFG_TIMEOUT) ?? self::DEFAULT_TIMEOUT))
                ->post($this->endpoint($shop), [
                    self::BODY_QUERY => $query,
                    self::BODY_VARIABLES => (object) $variables,
                ]);
        } catch (Throwable $e) {
            Log::warning(self::LOG_ERROR, ['shop_domain' => $shop, 'code' => ShopifyApiException::CODE_TRANSPORT, 'exception' => $e::class]);

            throw ShopifyApiException::transport($shop, $e::class);
        }
    }

    /**
     * Back off for the throttle window, or surface the typed throttle once the retry
     * budget is spent — the sync job then parks its cursor and resumes later.
     */
    private function waitOrThrow(string $shop, int $attempt, int $maxAttempts, int $waitSeconds): void
    {
        Log::warning(self::LOG_THROTTLED, [
            'shop_domain' => $shop,
            'attempt' => $attempt + 1,
            'wait_seconds' => $waitSeconds,
        ]);

        if ($attempt >= $maxAttempts) {
            throw ShopifyApiException::http($shop, self::STATUS_THROTTLED);
        }

        Sleep::for($waitSeconds)->seconds();
    }

    /**
     * The wait Shopify asked for: the Retry-After header when present (it is the
     * authoritative signal), else a bounded exponential-ish backoff. Clamped so a
     * hostile/absurd header can never park a worker for hours.
     */
    private function retryAfter(Response $response): int
    {
        $header = $response->header(self::HEADER_RETRY_AFTER);
        $backoff = (int) (config(self::CFG_THROTTLE_BACKOFF) ?? self::DEFAULT_BACKOFF_SECONDS);
        $max = (int) (config(self::CFG_THROTTLE_MAX_WAIT) ?? self::DEFAULT_MAX_WAIT_SECONDS);

        $seconds = is_numeric($header) ? (int) ceil((float) $header) : $backoff;

        return max(1, min($seconds, $max));
    }

    /** True when a 200 response's errors array is Shopify's GraphQL THROTTLED signal. */
    private function isThrottleError(array $errors): bool
    {
        foreach ($errors as $error) {
            $code = $error[self::RESPONSE_EXTENSIONS][self::ERROR_CODE_KEY] ?? null;

            if (is_string($code) && strtoupper($code) === self::ERROR_CODE_THROTTLED) {
                return true;
            }
        }

        return false;
    }

    /** The version-pinned GraphQL endpoint for a store. */
    private function endpoint(string $shop): string
    {
        return self::SCHEME.$shop.sprintf(self::PATH_TEMPLATE, (string) config(self::CFG_API_VERSION));
    }
}
