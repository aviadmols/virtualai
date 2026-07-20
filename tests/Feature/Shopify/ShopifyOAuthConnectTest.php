<?php

namespace Tests\Feature\Shopify;

use App\Domain\Shopify\Auth\ShopifyOAuthException;
use App\Domain\Shopify\Auth\ShopifyOAuthState;
use App\Domain\Shopify\Webhooks\RegisterShopifyWebhooksJob;
use App\Models\Account;
use App\Models\ShopifyConnection;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * connect_existing_site (docs/shopify/DECISIONS.md §2): the merchant starts inside the
 * Tray On panel. Proves the happy path persists ONE connection inside the tenant with an
 * encrypted offline token and platform=shopify, that a re-install RE-ACTIVATES the same
 * row (a shop_domain never duplicates), and that EVERY tampered input (forged hmac,
 * forged/replayed state, a non-myshopify shop, another account's shop or site) is a typed
 * 403 that writes NOTHING — never a 500.
 */
class ShopifyOAuthConnectTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const CLIENT_ID = 'test-shopify-client-id';

    private const CLIENT_SECRET = 'test-shopify-client-secret';

    private const SHOP = 'demo-shop.myshopify.com';

    private const CODE = 'authcode-123';

    private const OFFLINE_TOKEN = 'shpat_offline_token';

    private const TOKEN_URL = 'https://demo-shop.myshopify.com/admin/oauth/access_token';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.shopify.client_id', self::CLIENT_ID);
        config()->set('services.shopify.client_secret', self::CLIENT_SECRET);
    }

    // --- The panel entry point (Connect) ---

    public function test_start_redirects_the_merchant_to_the_shopify_grant_screen(): void
    {
        [$account, $site, $user] = $this->merchant();

        $response = $this->actingAs($user)->get(route('shopify.oauth.start', ['site' => $site->id, 'shop' => 'demo-shop']));

        $response->assertRedirectContains('https://'.self::SHOP.'/admin/oauth/authorize');
        $response->assertRedirectContains('client_id='.self::CLIENT_ID);
        $response->assertRedirectContains(urlencode(route('shopify.oauth.callback')));
    }

    public function test_start_cannot_connect_a_site_belonging_to_another_account(): void
    {
        [, , $user] = $this->merchant();
        $foreignSite = Site::factory()->forAccount(Account::factory()->create())->create();

        $response = $this->actingAs($user)->get(route('shopify.oauth.start', ['site' => $foreignSite->id, 'shop' => 'demo-shop']));

        $response->assertStatus(403);
        $response->assertSee(ShopifyOAuthException::CODE_SITE_NOT_OWNED);
    }

    public function test_start_rejects_a_shop_that_is_not_a_myshopify_host(): void
    {
        [, $site, $user] = $this->merchant();

        $response = $this->actingAs($user)->get(route('shopify.oauth.start', ['site' => $site->id, 'shop' => 'evil.example.com']));

        $response->assertStatus(403);
        $response->assertSee(ShopifyOAuthException::CODE_INVALID_SHOP);
    }

    // --- The callback (the persist path) ---

    public function test_the_happy_path_persists_the_connection_inside_the_tenant(): void
    {
        Bus::fake();
        $this->fakeTokenExchange();
        [$account, $site, $user] = $this->merchant();
        $this->actingAs($user); // the connect flow only completes for the account that started it

        $response = $this->get($this->signedCallback($this->issuedState($account->id, $site->id)));

        $response->assertRedirect('/merchant/'.$site->slug);

        $connection = $this->connectionFor($account);
        $this->assertNotNull($connection);
        $this->assertSame(self::SHOP, $connection->shop_domain);
        $this->assertSame((int) $site->id, (int) $connection->site_id);
        $this->assertSame((int) $account->id, (int) $connection->account_id);
        $this->assertSame(ShopifyConnection::STATUS_INSTALLED, $connection->status);

        // The install requested an EXPIRING offline token (expiring=1) — the Admin API rejects
        // the default non-expiring one, so a plain install must opt in.
        Http::assertSent(fn ($request): bool => $request->url() === self::TOKEN_URL
            && ($request->data()['expiring'] ?? null) === '1'
            && ($request->data()['code'] ?? null) === self::CODE);

        // The offline token round-trips through the EncryptedJson cast...
        $this->assertSame(self::OFFLINE_TOKEN, $connection->accessToken());
        // ...and is NOT readable as plaintext in the column.
        $this->assertStringNotContainsString(
            self::OFFLINE_TOKEN,
            (string) DB::table('shopify_connections')->where('id', $connection->id)->value('credentials'),
        );

        // The site is flipped to the Shopify platform, and webhook registration is queued
        // with the EXPLICIT account_id.
        $this->assertTrue($site->fresh()->isShopify());
        Bus::assertDispatched(
            RegisterShopifyWebhooksJob::class,
            fn (RegisterShopifyWebhooksJob $job): bool => $job->accountId === (int) $account->id && $job->siteId === (int) $site->id,
        );
    }

    public function test_a_reinstall_reactivates_the_same_row_and_never_duplicates(): void
    {
        Bus::fake();
        $this->fakeTokenExchange();
        [$account, $site, $user] = $this->merchant();
        $this->actingAs($user); // the connect flow only completes for the account that started it

        // First install, then the merchant uninstalls (credentials wiped by the model).
        $this->get($this->signedCallback($this->issuedState($account->id, $site->id)))->assertRedirect();
        $first = $this->connectionFor($account);
        Tenant::run($account, fn () => $first->transitionTo(ShopifyConnection::STATUS_UNINSTALLED));
        $this->assertNull($first->fresh()->accessToken());

        // Re-install: the SAME row is re-activated with fresh credentials.
        $this->get($this->signedCallback($this->issuedState($account->id, $site->id)))->assertRedirect();

        $reinstalled = $this->connectionFor($account);
        $this->assertSame((int) $first->id, (int) $reinstalled->id);
        $this->assertSame(ShopifyConnection::STATUS_INSTALLED, $reinstalled->status);
        $this->assertSame(self::OFFLINE_TOKEN, $reinstalled->accessToken());
        $this->assertSame(1, DB::table('shopify_connections')->where('shop_domain', self::SHOP)->count());
    }

    public function test_a_tampered_hmac_is_403_and_persists_nothing(): void
    {
        Bus::fake();
        $this->fakeTokenExchange();
        [$account, $site, $user] = $this->merchant();
        $this->actingAs($user); // the connect flow only completes for the account that started it

        $url = $this->signedCallback($this->issuedState($account->id, $site->id));
        $tampered = str_replace('code='.self::CODE, 'code=stolen', $url);

        $response = $this->get($tampered);

        $response->assertStatus(403);
        $response->assertSee(ShopifyOAuthException::CODE_INVALID_HMAC);
        $this->assertNull($this->connectionFor($account));
        Http::assertNothingSent();
    }

    public function test_a_forged_state_is_403_and_persists_nothing(): void
    {
        Bus::fake();
        $this->fakeTokenExchange();
        [$account] = $this->merchant();

        $response = $this->get($this->signedCallback('not-a-signed-state'));

        $response->assertStatus(403);
        $response->assertSee(ShopifyOAuthException::CODE_INVALID_STATE);
        $this->assertNull($this->connectionFor($account));
        Http::assertNothingSent();
    }

    public function test_a_replayed_state_is_rejected_the_second_time(): void
    {
        Bus::fake();
        $this->fakeTokenExchange();
        [$account, $site, $user] = $this->merchant();
        $this->actingAs($user); // the connect flow only completes for the account that started it

        $state = $this->issuedState($account->id, $site->id);

        $this->get($this->signedCallback($state))->assertRedirect();
        // The nonce is single-use: the identical callback URL cannot be replayed.
        $replay = $this->get($this->signedCallback($state));

        $replay->assertStatus(403);
        $replay->assertSee(ShopifyOAuthException::CODE_INVALID_STATE);
    }

    public function test_a_non_myshopify_shop_never_reaches_the_token_exchange(): void
    {
        Bus::fake();
        Http::fake();
        [$account, $site, $user] = $this->merchant();
        $this->actingAs($user); // the connect flow only completes for the account that started it

        $response = $this->get($this->signedCallback($this->issuedState($account->id, $site->id), ['shop' => 'evil.example.com']));

        $response->assertStatus(403);
        $response->assertSee(ShopifyOAuthException::CODE_INVALID_SHOP);
        Http::assertNothingSent(); // the regex is the wall: no POST to an attacker host
    }

    public function test_a_shop_owned_by_another_account_cannot_be_stolen(): void
    {
        Bus::fake();
        $this->fakeTokenExchange();

        // Account A owns the shop.
        [$accountA, $siteA, $userA] = $this->merchant();
        $this->actingAs($userA)->get($this->signedCallback($this->issuedState($accountA->id, $siteA->id)))->assertRedirect();

        // Account B runs a perfectly-signed install, from its own browser, for the SAME shop.
        [$accountB, $siteB, $userB] = $this->merchant();
        $response = $this->actingAs($userB)->get($this->signedCallback($this->issuedState($accountB->id, $siteB->id)));

        $response->assertStatus(403);
        $response->assertSee(ShopifyOAuthException::CODE_SHOP_OWNED_BY_ANOTHER_ACCOUNT);
        $this->assertNull($this->connectionFor($accountB));
        $this->assertSame(self::OFFLINE_TOKEN, $this->connectionFor($accountA)->accessToken()); // A untouched
        $this->assertFalse($siteB->fresh()->isShopify());
    }

    public function test_a_phished_store_admin_cannot_hand_their_store_to_the_attackers_account(): void
    {
        // THE STORE-THEFT PATH. The attacker mints a genuine state for THEIR OWN account and
        // phishes the victim's store admin with the real Shopify grant link. The victim approves,
        // and Shopify redirects the VICTIM'S browser to our callback with a valid hmac + code.
        // The wall is the callback's ACCOUNT RE-CHECK: the state names the attacker's account, but
        // the victim's browser is NOT signed in as the attacker (account 0 ≠ the attacker's), so
        // the connect is refused — even though the single-use nonce (now cache-based) is present.
        Bus::fake();
        $this->fakeTokenExchange();

        [$attacker, $attackerSite, $attackerUser] = $this->merchant();
        $state = $this->issuedState($attacker->id, $attackerSite->id);

        // The victim's browser: not signed in as the attacker — the account re-check is the wall.
        $this->flushSession();

        $response = $this->get($this->signedCallback($state));

        $response->assertStatus(403);
        $response->assertSee(ShopifyOAuthException::CODE_INVALID_STATE);
        $this->assertNull($this->connectionFor($attacker));
        $this->assertFalse($attackerSite->fresh()->isShopify());
        Http::assertNothingSent(); // the wall stands BEFORE the token exchange
    }

    public function test_a_state_naming_another_account_cannot_be_redeemed(): void
    {
        // Defence in depth: even inside a browser that legitimately holds the nonce, the caller
        // must BE the account the state names.
        Bus::fake();
        $this->fakeTokenExchange();

        [$victim, $victimSite] = $this->merchant();
        [, , $attackerUser] = $this->merchant();

        $state = $this->issuedState($victim->id, $victimSite->id);

        $response = $this->actingAs($attackerUser)->get($this->signedCallback($state));

        $response->assertStatus(403);
        $response->assertSee(ShopifyOAuthException::CODE_INVALID_STATE);
        $this->assertNull($this->connectionFor($victim));
        Http::assertNothingSent();
    }

    public function test_unset_app_credentials_are_a_typed_502_not_a_500(): void
    {
        Bus::fake();
        Http::fake();
        config()->set('services.shopify.client_id', null);
        config()->set('services.shopify.client_secret', null);
        [$account, $site, $user] = $this->merchant();
        $this->actingAs($user); // the connect flow only completes for the account that started it

        $response = $this->get(route('shopify.oauth.callback', ['shop' => self::SHOP, 'code' => self::CODE, 'state' => 'x', 'hmac' => 'y']));

        $response->assertStatus(502);
        $response->assertSee(ShopifyOAuthException::CODE_NOT_CONFIGURED);
        $this->assertNull($this->connectionFor($account));
    }

    public function test_a_shopify_side_token_exchange_failure_is_a_typed_502(): void
    {
        Bus::fake();
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_request'], 400)]);
        [$account, $site, $user] = $this->merchant();
        $this->actingAs($user); // the connect flow only completes for the account that started it

        $response = $this->get($this->signedCallback($this->issuedState($account->id, $site->id)));

        $response->assertStatus(502);
        $response->assertSee(ShopifyOAuthException::CODE_TOKEN_EXCHANGE_FAILED);
        $this->assertNull($this->connectionFor($account));
    }

    // === HELPERS ===

    /** @return array{0: Account, 1: Site, 2: User} */
    private function merchant(): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();
        $user = User::factory()->create(['account_id' => $account->id]);

        return [$account, $site, $user];
    }

    /**
     * A state as the merchant's own browser would hold it: the nonce is parked in THIS test
     * session, which the callback request then presents (the same-browser case).
     */
    private function issuedState(int $accountId, int $siteId): string
    {
        $this->startSession();

        return app(ShopifyOAuthState::class)->issue(
            flow: ShopifyOAuthState::FLOW_CONNECT_EXISTING_SITE,
            session: app('session.store'),
            accountId: $accountId,
            siteId: $siteId,
        );
    }

    /** A callback URL signed exactly the way Shopify signs it (sorted query, minus hmac). */
    private function signedCallback(string $state, array $overrides = []): string
    {
        $params = array_merge([
            'code' => self::CODE,
            'shop' => self::SHOP,
            'state' => $state,
            'timestamp' => (string) time(),
        ], $overrides);

        ksort($params);
        $params['hmac'] = hash_hmac('sha256', http_build_query($params), self::CLIENT_SECRET);

        return route('shopify.oauth.callback').'?'.http_build_query($params);
    }

    private function fakeTokenExchange(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response([
                'access_token' => self::OFFLINE_TOKEN,
                'scope' => (string) config('shopify.scopes'),
            ]),
        ]);
    }

    /** Read the connection through the FAIL-CLOSED tenant scope (never unscoped). */
    private function connectionFor(Account $account): ?ShopifyConnection
    {
        return Tenant::run($account, fn (): ?ShopifyConnection => ShopifyConnection::query()->first());
    }
}
