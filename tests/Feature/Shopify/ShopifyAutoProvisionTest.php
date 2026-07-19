<?php

namespace Tests\Feature\Shopify;

use App\Domain\Shopify\Auth\ShopifyAccessToken;
use App\Domain\Shopify\Auth\ShopifyAccountProvisioner;
use App\Domain\Shopify\Auth\ShopifyOAuthException;
use App\Domain\Shopify\Auth\ShopifyOAuthState;
use App\Models\Account;
use App\Models\CreditLedger;
use App\Models\ShopifyConnection;
use App\Models\ShopifyPendingInstall;
use App\Models\Site;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Shopify-SSO auto-provisioning (install_new_shop, NO Vsio session): the merchant
 * installs from Shopify and lands authenticated in the merchant panel with a fully
 * provisioned account — no manual register/login wall.
 *
 * The HMAC-verified OAuth callback mints the Account + owner User (email from the Shopify
 * shop, verified), applies the opening grant through the ledger, creates the Site +
 * ShopifyConnection, logs the owner in, and redirects to the panel. It is idempotent by
 * shop_domain (a re-run duplicates nothing), never hijacks a shop email that belongs to
 * another account, and CANNOT run without the verified callback (a tampered hmac / forged
 * state provisions nothing and logs no one in).
 */
class ShopifyAutoProvisionTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const CLIENT_ID = 'test-shopify-client-id';

    private const CLIENT_SECRET = 'test-shopify-client-secret';

    private const SHOP = 'brand-new.myshopify.com';

    private const CODE = 'authcode-789';

    private const OFFLINE_TOKEN = 'shpat_auto_provision_token';

    private const TOKEN_URL = 'https://brand-new.myshopify.com/admin/oauth/access_token';

    private const GRAPHQL_URL = 'https://brand-new.myshopify.com/admin/api/*';

    private const SHOP_NAME = 'Lets Sell Book';

    private const SHOP_EMAIL = 'owner@lets-sell-book.example';

    // The shop-derived login the provisioner falls back to (handle @ shop domain).
    private const DETERMINISTIC_EMAIL = 'brand-new@brand-new.myshopify.com';

    private const MERCHANT_LOGIN_PATH = '/merchant/login';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.shopify.client_id', self::CLIENT_ID);
        config()->set('services.shopify.client_secret', self::CLIENT_SECRET);
    }

    public function test_a_fresh_install_auto_provisions_everything_and_logs_the_owner_in(): void
    {
        Bus::fake();
        $this->fakeShopify();

        $response = $this->get($this->signedCallback($this->newShopState()));

        // Exactly ONE account + one owner user, and NOT the Sign-in wall.
        $this->assertSame(1, Account::query()->count());
        $account = Account::query()->firstOrFail();
        $this->assertSame(self::SHOP_NAME, $account->name);

        $this->assertSame(1, User::query()->count());
        $owner = User::query()->firstOrFail();
        $this->assertSame((int) $account->id, (int) $owner->account_id);
        $this->assertFalse($owner->isSuperAdmin());
        $this->assertSame(self::SHOP_EMAIL, $owner->email);       // from the Shopify shop
        $this->assertNotNull($owner->email_verified_at);          // Shopify vouches for it

        // One Site + one ShopifyConnection, created inside the tenant with the token.
        $this->assertSame(1, DB::table('sites')->count());
        $this->assertSame(1, DB::table('shopify_connections')->count());
        $connection = Tenant::run($account, fn (): ?ShopifyConnection => ShopifyConnection::query()->first());
        $this->assertSame(self::SHOP, $connection->shop_domain);
        $this->assertSame(self::OFFLINE_TOKEN, $connection->accessToken());

        $site = Tenant::run($account, fn (): ?Site => Site::query()->firstOrFail());
        $this->assertTrue($site->isShopify());

        // Exactly one opening grant, through the ledger, reflected in the balance.
        $grants = Tenant::run($account, fn (): int => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_GRANT)->count());
        $this->assertSame(1, $grants);
        $this->assertGreaterThan(0, $account->fresh()->balance_micro_usd);

        // Auto-logged-in and sent BACK INTO the Shopify admin (embedded app), never the
        // Sign-in wall and never an external panel tab.
        $this->assertAuthenticatedAs($owner);
        $response->assertRedirect('https://'.self::SHOP.'/admin/apps/'.self::CLIENT_ID);
        $this->assertSame(0, ShopifyPendingInstall::query()->count()); // never parked
    }

    public function test_a_second_callback_for_the_same_shop_creates_no_duplicates(): void
    {
        Bus::fake();
        $this->fakeShopify();

        // First install provisions everything.
        $this->get($this->signedCallback($this->newShopState()))->assertRedirect();
        $this->assertSame(1, Account::query()->count());

        // A second install callback for the SAME shop, from a fresh browser: the shop is
        // now known, so it re-activates in place — no second account/user/site/grant.
        $this->flushSession();
        auth()->logout();
        $this->get($this->signedCallback($this->newShopState()))->assertRedirect();

        $this->assertSame(1, Account::query()->count());
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, DB::table('sites')->count());
        $this->assertSame(1, DB::table('shopify_connections')->count());

        $account = Account::query()->firstOrFail();
        $grants = Tenant::run($account, fn (): int => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_GRANT)->count());
        $this->assertSame(1, $grants);
    }

    public function test_provisioning_is_idempotent_at_the_service_level(): void
    {
        // The provisioner's OWN idempotency wall (independent of the reconnect branch in the
        // controller): calling it twice for the same shop resolves the existing owner + site,
        // never a second account. Removing the shop_domain re-check would mint a second
        // account here — this assertion fails if that guard is gone.
        Bus::fake();
        $this->fakeShopify();

        $token = new ShopifyAccessToken(
            accessToken: self::OFFLINE_TOKEN,
            scopes: (string) config('shopify.scopes'),
            apiVersion: (string) config('shopify.api_version'),
        );

        $provisioner = app(ShopifyAccountProvisioner::class);
        $first = $provisioner->provisionForInstall(self::SHOP, $token, 'corr-1');
        $second = $provisioner->provisionForInstall(self::SHOP, $token, 'corr-2');

        $this->assertSame((int) $first->owner->id, (int) $second->owner->id);
        $this->assertSame($first->siteId, $second->siteId);
        $this->assertSame(1, Account::query()->count());
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, DB::table('shopify_connections')->count());
    }

    public function test_provisioning_never_runs_from_a_tampered_hmac_callback(): void
    {
        // THE SECURITY WALL. Provisioning + auto-login may only happen from the HMAC-verified
        // callback. A tampered signature is rejected BEFORE the auto-provision branch — remove
        // the hmac guard and this request would mint an account and log a stranger in, which
        // the zero-count + guest assertions below would catch.
        Bus::fake();
        $this->fakeShopify();

        $tampered = str_replace('code='.self::CODE, 'code=stolen', $this->signedCallback($this->newShopState()));

        $response = $this->get($tampered);

        $response->assertStatus(403);
        $response->assertSee(ShopifyOAuthException::CODE_INVALID_HMAC);
        $this->assertSame(0, Account::query()->count());
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, DB::table('shopify_connections')->count());
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_a_forged_state_never_auto_provisions(): void
    {
        Bus::fake();
        $this->fakeShopify();

        $response = $this->get($this->signedCallback('not-a-real-state'));

        $response->assertStatus(403);
        $response->assertSee(ShopifyOAuthException::CODE_INVALID_STATE);
        $this->assertSame(0, Account::query()->count());
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_a_shop_email_owned_by_another_account_is_not_hijacked(): void
    {
        Bus::fake();
        // The shop reports an email that already belongs to a DIFFERENT account.
        $this->fakeShopify(email: 'taken@other.example');

        $other = Account::factory()->create();
        User::factory()->forAccount($other)->create(['email' => 'taken@other.example']);

        $this->get($this->signedCallback($this->newShopState()))->assertRedirect();

        // The new owner did NOT get the taken email — a deterministic shop-derived login.
        $newAccount = Account::query()->where('id', '!=', $other->id)->firstOrFail();
        $newOwner = User::query()->where('account_id', $newAccount->id)->firstOrFail();
        $this->assertNotSame('taken@other.example', $newOwner->email);
        $this->assertSame(self::DETERMINISTIC_EMAIL, $newOwner->email);

        // The pre-existing user is untouched (still exactly one, still on the other account).
        $this->assertSame(1, User::query()->where('email', 'taken@other.example')->count());
        $taken = User::query()->where('email', 'taken@other.example')->firstOrFail();
        $this->assertSame((int) $other->id, (int) $taken->account_id);
    }

    public function test_a_shop_without_an_email_falls_back_to_a_deterministic_login(): void
    {
        Bus::fake();
        $this->fakeShopify(name: null, email: null);

        $this->get($this->signedCallback($this->newShopState()))->assertRedirect();

        $owner = User::query()->firstOrFail();
        $this->assertSame(self::DETERMINISTIC_EMAIL, $owner->email);
        // The account name falls back to the headlined shop handle.
        $this->assertSame('Brand New', Account::query()->firstOrFail()->name);
    }

    public function test_an_authenticated_install_attaches_directly_and_returns_to_shopify(): void
    {
        // An already-signed-in merchant must not get a second account or leave Shopify for
        // /claim. The verified callback attaches the new shop directly to their account.
        Bus::fake();
        $this->fakeShopify();

        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);

        $response = $this->actingAs($user)->get($this->signedCallback($this->newShopState()));

        $response->assertRedirect('https://'.self::SHOP.'/admin/apps/'.self::CLIENT_ID);
        $this->assertSame(1, Account::query()->count()); // no second account
        $this->assertSame(0, ShopifyPendingInstall::query()->count());
        $this->assertSame(1, DB::table('sites')->count());
        $this->assertSame(1, DB::table('shopify_connections')->count());

        $connection = Tenant::run($account, fn (): ?ShopifyConnection => ShopifyConnection::query()->first());
        $this->assertNotNull($connection);
        $this->assertSame(self::SHOP, $connection->shop_domain);
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

    /** Fake BOTH Shopify calls the install makes: the token exchange and the shop-profile read. */
    private function fakeShopify(?string $name = self::SHOP_NAME, ?string $email = self::SHOP_EMAIL): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response([
                'access_token' => self::OFFLINE_TOKEN,
                'scope' => (string) config('shopify.scopes'),
            ]),
            self::GRAPHQL_URL => Http::response([
                'data' => ['shop' => [
                    'name' => $name,
                    'email' => $email,
                    'contactEmail' => $email,
                    'myshopifyDomain' => self::SHOP,
                ]],
            ]),
        ]);
    }
}
