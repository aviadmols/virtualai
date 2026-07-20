<?php

namespace Tests\Feature\Shopify;

use App\Domain\Shopify\Auth\ShopifyOAuthException;
use App\Domain\Shopify\Auth\ShopifyOAuthState;
use App\Domain\Shopify\Webhooks\RegisterShopifyWebhooksJob;
use App\Http\Shopify\Controllers\OAuthController;
use App\Models\Account;
use App\Models\ShopifyConnection;
use App\Models\ShopifyPendingInstall;
use App\Models\Site;
use App\Models\User;
use App\Support\GlobalModels;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * install_new_shop (docs/shopify/DECISIONS.md §2): every verified callback finishes back
 * inside Shopify Admin. A guest is auto-provisioned; an already-authenticated merchant gets
 * the new shop attached directly to their account; a known shop is re-activated in place.
 * Legacy pending-install rows can still be claimed exactly once, but normal installs no
 * longer create one because the intermediate redirect could leak to /merchant/login.
 */
class ShopifyInstallNewShopTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const CLIENT_ID = 'test-shopify-client-id';

    private const CLIENT_SECRET = 'test-shopify-client-secret';

    private const SHOP = 'brand-new.myshopify.com';

    private const CODE = 'authcode-456';

    private const OFFLINE_TOKEN = 'shpat_new_shop_token';

    private const TOKEN_URL = 'https://brand-new.myshopify.com/admin/oauth/access_token';

    private const CLAIM_ROUTE = 'shopify.install.claim';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.shopify.client_id', self::CLIENT_ID);
        config()->set('services.shopify.client_secret', self::CLIENT_SECRET);
    }

    public function test_the_pending_install_is_an_audited_pre_bind_global_model(): void
    {
        // It carries NO account_id (there is no tenant at callback time) — so it must be
        // on the documented allow-list, exactly like the webhook receipt inbox.
        $this->assertTrue(GlobalModels::isGlobal(ShopifyPendingInstall::class));
    }

    public function test_the_shopify_entry_point_hands_off_to_the_grant_screen_via_a_cookie_committing_page(): void
    {
        // A 200 hand-off page (not a 302) so the state-nonce session cookie is committed before
        // the top-level round-trip to Shopify — a freshly-set partitioned cookie set on a redirect
        // could be dropped, failing the callback's state check on the FIRST install (TS-INFRA-005).
        $response = $this->get(route('shopify.install', ['shop' => self::SHOP]));

        $response->assertOk();
        $response->assertSee('https://'.self::SHOP.'/admin/oauth/authorize', false);
    }

    public function test_the_install_handoff_issues_a_state_the_same_session_callback_accepts(): void
    {
        $this->fakeTokenExchange();

        // The hand-off page carries the authorize URL with the state issued into THIS session.
        $handoff = $this->get(route('shopify.install', ['shop' => self::SHOP]));
        $handoff->assertOk();

        $state = $this->extractState($handoff->getContent());
        $this->assertNotSame('', $state);

        // Completing the callback in the SAME session (the merchant's browser) provisions the shop
        // and returns embedded — proving the hand-off page's state survives to a real callback.
        $callback = $this->get($this->signedCallback($state));

        $callback->assertRedirect('https://'.self::SHOP.'/admin/apps/'.self::CLIENT_ID);
        $this->assertSame(1, DB::table('shopify_connections')->count());
        $this->assertSame(1, Account::query()->count());
    }

    public function test_an_authenticated_merchant_is_attached_directly_and_returns_to_shopify_admin(): void
    {
        Bus::fake();
        $this->fakeTokenExchange();

        // A signed-in merchant installs an additional store from Shopify: attach it in this
        // verified callback and return to the embedded app — no claim/login detour.
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);

        $response = $this->actingAs($user)->get($this->signedCallback($this->newShopState()));

        $response->assertRedirect('https://'.self::SHOP.'/admin/apps/'.self::CLIENT_ID);
        $this->assertSame(0, ShopifyPendingInstall::query()->count());
        $this->assertSame(1, Account::query()->count());
        $this->assertSame(1, DB::table('sites')->count());
        $this->assertSame(1, DB::table('shopify_connections')->count());

        $connection = Tenant::run($account, fn (): ?ShopifyConnection => ShopifyConnection::query()->first());
        $this->assertNotNull($connection);
        $this->assertSame(self::SHOP, $connection->shop_domain);
        $this->assertSame(self::OFFLINE_TOKEN, $connection->accessToken());
        Bus::assertDispatched(RegisterShopifyWebhooksJob::class);
    }

    public function test_an_authenticated_account_consumes_the_pending_install_exactly_once(): void
    {
        Bus::fake();

        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);

        // Legacy rows created before direct callback attachment remain safely consumable.
        $claimToken = ShopifyPendingInstall::generateClaimToken();
        ShopifyPendingInstall::factory()->withClaimToken($claimToken)->create([
            'shop_domain' => self::SHOP,
            'credentials' => [
                ShopifyPendingInstall::CRED_ACCESS_TOKEN => self::OFFLINE_TOKEN,
                ShopifyPendingInstall::CRED_SCOPES => (string) config('shopify.scopes'),
                ShopifyPendingInstall::CRED_API_VERSION => (string) config('shopify.api_version'),
            ],
        ]);

        $response = $this->actingAs($user)
            ->withSession([OAuthController::SESSION_CLAIM_TOKEN => $claimToken])
            ->get(route(self::CLAIM_ROUTE));

        // The Site was created inside the tenant, flipped to the Shopify platform, and
        // the connection carries the parked token.
        $connection = Tenant::run($account, fn (): ?ShopifyConnection => ShopifyConnection::query()->first());
        $this->assertNotNull($connection);
        $this->assertSame(self::SHOP, $connection->shop_domain);
        $this->assertSame((int) $account->id, (int) $connection->account_id);
        $this->assertSame(self::OFFLINE_TOKEN, $connection->accessToken());

        $site = Tenant::run($account, fn (): ?Site => Site::query()->find($connection->site_id));
        $this->assertNotNull($site);
        $this->assertTrue($site->isShopify());
        $response->assertRedirect('/merchant/'.$site->slug);

        // Consumed EXACTLY ONCE: the parked row is gone...
        $this->assertSame(0, ShopifyPendingInstall::query()->count());

        // ...and a replay of the same claim token creates nothing.
        $replay = $this->actingAs($user)
            ->withSession([OAuthController::SESSION_CLAIM_TOKEN => $claimToken])
            ->get(route(self::CLAIM_ROUTE));

        $replay->assertStatus(409);
        $replay->assertSee(ShopifyOAuthException::CODE_PENDING_INSTALL_EXPIRED);
        $this->assertSame(1, DB::table('shopify_connections')->count());
        $this->assertSame(1, DB::table('sites')->count());
    }

    public function test_an_unauthenticated_claim_bounces_to_login_and_consumes_nothing(): void
    {
        $claimToken = ShopifyPendingInstall::generateClaimToken();
        ShopifyPendingInstall::factory()->withClaimToken($claimToken)->create(['shop_domain' => self::SHOP]);

        $response = $this->withSession([OAuthController::SESSION_CLAIM_TOKEN => $claimToken])
            ->get(route(self::CLAIM_ROUTE));

        $response->assertRedirect((string) config('shopify.merchant_login_path'));
        $this->assertSame(1, ShopifyPendingInstall::query()->count()); // still parked
        $this->assertSame(0, DB::table('shopify_connections')->count());
    }

    public function test_an_expired_pending_install_can_never_be_claimed(): void
    {
        $claimToken = ShopifyPendingInstall::generateClaimToken();
        ShopifyPendingInstall::factory()->withClaimToken($claimToken)->expired()->create(['shop_domain' => self::SHOP]);

        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);

        $response = $this->actingAs($user)
            ->withSession([OAuthController::SESSION_CLAIM_TOKEN => $claimToken])
            ->get(route(self::CLAIM_ROUTE));

        $response->assertStatus(409);
        $this->assertSame(0, DB::table('shopify_connections')->count());
    }

    public function test_a_shop_already_owned_by_another_account_cannot_be_claimed(): void
    {
        Bus::fake();

        // Account A already owns the store.
        $accountA = Account::factory()->create();
        $siteA = Site::factory()->forAccount($accountA)->create();
        Tenant::run($accountA, fn () => ShopifyConnection::factory()->forSite($siteA)->create(['shop_domain' => self::SHOP]));

        // A parked install for the same shop somehow reaches account B.
        $claimToken = ShopifyPendingInstall::generateClaimToken();
        ShopifyPendingInstall::factory()->withClaimToken($claimToken)->create(['shop_domain' => self::SHOP]);

        $accountB = Account::factory()->create();
        $userB = User::factory()->create(['account_id' => $accountB->id]);

        $response = $this->actingAs($userB)
            ->withSession([OAuthController::SESSION_CLAIM_TOKEN => $claimToken])
            ->get(route(self::CLAIM_ROUTE));

        $response->assertStatus(403);
        $response->assertSee(ShopifyOAuthException::CODE_SHOP_OWNED_BY_ANOTHER_ACCOUNT);
        $this->assertNull(Tenant::run($accountB, fn () => ShopifyConnection::query()->first()));
        $this->assertSame(1, DB::table('shopify_connections')->count());
    }

    public function test_a_state_stolen_from_another_browser_cannot_complete_the_install(): void
    {
        // The install_new_shop flow has no account to check yet, so the BROWSER BINDING is the
        // only wall: whoever completes the callback receives the claim token, and would go on to
        // attach the store to THEIR account. A state lifted out of the merchant's browser (a
        // leaked referrer, a shared link) must therefore be dead in any other browser.
        Bus::fake();
        $this->fakeTokenExchange();

        $state = $this->newShopState();   // issued in the merchant's browser
        $this->flushSession();            // the attacker's browser: it never held the nonce

        $response = $this->get($this->signedCallback($state));

        $response->assertStatus(403);
        $response->assertSee(ShopifyOAuthException::CODE_INVALID_STATE);
        $response->assertSessionMissing(OAuthController::SESSION_CLAIM_TOKEN);
        $this->assertSame(0, ShopifyPendingInstall::query()->count()); // nothing parked
        // The state wall stands BEFORE the auto-provision branch: no account is minted and
        // no one is logged in off a stolen state.
        $this->assertSame(0, Account::query()->count());
        $this->assertGuest();
        Http::assertNothingSent(); // and no token was ever exchanged
    }

    public function test_a_reinstall_from_shopify_of_a_known_shop_reactivates_without_parking(): void
    {
        Bus::fake();
        $this->fakeTokenExchange();

        $account = Account::factory()->create();
        $owner = User::factory()->create(['account_id' => $account->id]);
        $site = Site::factory()->forAccount($account)->create();
        $connection = Tenant::run($account, fn (): ShopifyConnection => ShopifyConnection::factory()->forSite($site)->create([
            'shop_domain' => self::SHOP,
            'status' => ShopifyConnection::STATUS_UNINSTALLED,
            'credentials' => null,
        ]));

        // The merchant re-installs from the Shopify admin — no Tray On session at all.
        $response = $this->get($this->signedCallback($this->newShopState()));

        // Back INTO the Shopify admin (embedded), with the shop owner auto-logged-in.
        $response->assertRedirect('https://'.self::SHOP.'/admin/apps/'.self::CLIENT_ID);
        $this->assertAuthenticatedAs($owner);
        $this->assertSame(0, ShopifyPendingInstall::query()->count()); // never parked

        $fresh = Tenant::run($account, fn (): ?ShopifyConnection => ShopifyConnection::query()->first());
        $this->assertSame((int) $connection->id, (int) $fresh->id); // the SAME row
        $this->assertSame(ShopifyConnection::STATUS_INSTALLED, $fresh->status);
        $this->assertSame(self::OFFLINE_TOKEN, $fresh->accessToken());
        $this->assertSame(1, DB::table('shopify_connections')->count());
    }

    // === HELPERS ===

    private function newShopState(): string
    {
        // The nonce is parked in THIS test session — the browser that started the install.
        $this->startSession();

        return app(ShopifyOAuthState::class)->issue(
            flow: ShopifyOAuthState::FLOW_INSTALL_NEW_SHOP,
            session: app('session.store'),
        );
    }

    /** Pull the OAuth state out of the authorize URL rendered on the hand-off page. */
    private function extractState(string $html): string
    {
        return preg_match('/state=([A-Za-z0-9._-]+)/', $html, $m) === 1 ? $m[1] : '';
    }

    private function signedCallback(string $state): string
    {
        $params = [
            'code' => self::CODE,
            'shop' => self::SHOP,
            'state' => $state,
            'timestamp' => (string) time(),
        ];

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
}
