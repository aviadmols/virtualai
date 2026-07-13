<?php

namespace Tests\Feature\Shopify;

use App\Domain\Shopify\Api\ShopifyApiException;
use App\Domain\Shopify\Api\ShopifyGraphQLClient;
use App\Domain\Shopify\Products\ShopifyProductQueries;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * ShopifyGraphQLClient throttling. Shopify rate-limits the Admin API by query COST and
 * signals it two ways — a 429 with Retry-After, and (more often for GraphQL) a 200 whose
 * `errors[].extensions.code` is THROTTLED. Both must be recognised, both must honour the
 * Retry-After hint, and a spent budget must surface a TYPED throttle (so a sync job can
 * park its cursor) rather than a generic error.
 *
 * MUTATION-VERIFIED: remove the `Sleep::for($waitSeconds)` in waitOrThrow() and
 * test_a_429_honours_retry_after_before_retrying_and_then_succeeds goes red — the client
 * would hammer a rate-limited store, which is how an app gets its API access cut.
 */
class ShopifyThrottleTest extends TestCase
{
    use RefreshDatabase, ShopifyProductTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    public function test_a_429_honours_retry_after_before_retrying_and_then_succeeds(): void
    {
        [$account, , $connection] = $this->connectedShop();

        $calls = 0;
        $this->respondWith(function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response([], 429, ['Retry-After' => '3'])
                : Http::response(['data' => ['productsCount' => ['count' => 7]]]);
        });

        $data = Tenant::run($account, fn (): array => app(ShopifyGraphQLClient::class)
            ->query($connection, ShopifyProductQueries::count()));

        $this->assertSame(7, $data['productsCount']['count']);
        $this->assertSame(2, $calls);

        // The exact wait Shopify asked for — not a guess, not zero.
        Sleep::assertSleptTimes(1);
        Sleep::assertSequence([Sleep::for(3)->seconds()]);
    }

    public function test_a_graphql_200_with_a_throttled_extension_is_recognised_and_retried(): void
    {
        [$account, , $connection] = $this->connectedShop();

        $calls = 0;
        $this->respondWith(function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response(['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]]])
                : Http::response(['data' => ['productsCount' => ['count' => 2]]]);
        });

        $data = Tenant::run($account, fn (): array => app(ShopifyGraphQLClient::class)
            ->query($connection, ShopifyProductQueries::count()));

        $this->assertSame(2, $data['productsCount']['count']);
        $this->assertSame(2, $calls, 'a 200-with-THROTTLED must retry, not be parsed as data');
    }

    public function test_a_spent_retry_budget_surfaces_a_typed_throttle_not_a_generic_error(): void
    {
        [$account, , $connection] = $this->connectedShop();

        config()->set('shopify.throttle.max_retries', 1);

        $this->respondWith(fn () => Http::response([], 429, ['Retry-After' => '1']));

        try {
            Tenant::run($account, fn (): array => app(ShopifyGraphQLClient::class)
                ->query($connection, ShopifyProductQueries::count()));

            $this->fail('a spent throttle budget must throw');
        } catch (ShopifyApiException $e) {
            $this->assertTrue($e->isThrottled());
            $this->assertSame(ShopifyApiException::CODE_THROTTLED, $e->errorCode);
            $this->assertSame(429, $e->status);
        }
    }

    public function test_an_absurd_retry_after_is_clamped_so_a_worker_is_never_parked_for_hours(): void
    {
        [$account, , $connection] = $this->connectedShop();

        $calls = 0;
        $this->respondWith(function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response([], 429, ['Retry-After' => '86400']) // a day
                : Http::response(['data' => ['productsCount' => ['count' => 1]]]);
        });

        Tenant::run($account, fn (): array => app(ShopifyGraphQLClient::class)
            ->query($connection, ShopifyProductQueries::count()));

        Sleep::assertSequence([Sleep::for((int) config('shopify.throttle.max_wait_seconds'))->seconds()]);
    }

    public function test_a_real_graphql_error_is_typed_and_never_retried_as_a_throttle(): void
    {
        [$account, , $connection] = $this->connectedShop();

        $calls = 0;
        $this->respondWith(function () use (&$calls) {
            $calls++;

            return Http::response(['errors' => [['message' => 'Field does not exist']]]);
        });

        try {
            Tenant::run($account, fn (): array => app(ShopifyGraphQLClient::class)
                ->query($connection, ShopifyProductQueries::count()));

            $this->fail('a GraphQL error must throw');
        } catch (ShopifyApiException $e) {
            $this->assertSame(ShopifyApiException::CODE_GRAPHQL, $e->errorCode);
            $this->assertSame(1, $calls, 'a query error must NOT be retried like a throttle');
        }
    }

    public function test_the_access_token_never_appears_in_a_url_and_rides_only_in_the_header(): void
    {
        [$account, , $connection] = $this->connectedShop();

        $this->respondWith(fn () => Http::response(['data' => ['productsCount' => ['count' => 0]]]));

        Tenant::run($account, fn (): array => app(ShopifyGraphQLClient::class)
            ->query($connection, ShopifyProductQueries::count()));

        Http::assertSent(function ($request) use ($connection): bool {
            $this->assertStringNotContainsString((string) $connection->accessToken(), $request->url());
            $this->assertSame($connection->accessToken(), $request->header('X-Shopify-Access-Token')[0] ?? null);
            // The API version is PINNED from config — never "latest".
            $this->assertStringContainsString('/admin/api/'.config('shopify.api_version').'/graphql.json', $request->url());

            return true;
        });
    }
}
