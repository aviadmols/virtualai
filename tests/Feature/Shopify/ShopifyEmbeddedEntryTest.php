<?php

namespace Tests\Feature\Shopify;

use App\Models\Account;
use App\Models\ShopifyConnection;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The embedded entry (GET /shopify/app — the application_url the Shopify admin iframes).
 *
 * Stateless, fail-closed: a signed load for a KNOWN shop renders the App Bridge shell
 * with the per-shop frame-ancestors CSP; an UNKNOWN shop breaks out of the iframe to the
 * install flow (or 302s when not framed); a forged hmac / invalid shop is a typed 403;
 * unset credentials are a typed 502. Never a 500, never a Set-Cookie.
 */
class ShopifyEmbeddedEntryTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const CLIENT_ID = 'test-embedded-client-id';

    private const CLIENT_SECRET = 'test-embedded-client-secret';

    private const SHOP = 'embedded-shop.myshopify.com';

    private const APP_BRIDGE_SRC = 'https://cdn.shopify.com/shopifycloud/app-bridge.js';

    private const CSP_HEADER = 'Content-Security-Policy';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.shopify.client_id', self::CLIENT_ID);
        config()->set('services.shopify.client_secret', self::CLIENT_SECRET);
    }

    public function test_a_signed_load_for_a_known_shop_renders_the_shell_with_the_per_shop_csp(): void
    {
        $this->installShop();

        $response = $this->get($this->entryUrl(signed: true));

        $response->assertOk();
        $response->assertSee('name="shopify-api-key" content="'.self::CLIENT_ID.'"', escape: false);
        $response->assertSee(self::APP_BRIDGE_SRC, escape: false);
        $response->assertSee('BOOTSTRAP_URL'); // the shell wired its token-authed endpoints
        $response->assertSee("credentials: 'include'", escape: false);
        $response->assertSee('if (!session.ok) return fail();', escape: false);
        $response->assertSee('id="toe-dashboard" href="#" target="_self"', escape: false);
        $response->assertDontSee('id="toe-error-fallback"', escape: false);
        $response->assertHeader(
            self::CSP_HEADER,
            'frame-ancestors https://'.self::SHOP.' https://admin.shopify.com;',
        );
    }

    public function test_the_shell_is_stateless_no_cookie_is_ever_set(): void
    {
        $this->installShop();

        $response = $this->get($this->entryUrl(signed: true));

        $response->assertOk();
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_an_unknown_shop_inside_the_iframe_gets_the_breakout_page(): void
    {
        $response = $this->get($this->entryUrl(signed: true, embedded: true));

        $response->assertOk();
        $response->assertSee('/shopify/install?shop='.self::SHOP, escape: false);
        $response->assertSee('_top');
        $response->assertSee(self::APP_BRIDGE_SRC, escape: false);
    }

    public function test_an_unknown_shop_not_framed_redirects_to_the_install_flow(): void
    {
        $response = $this->get($this->entryUrl(signed: true));

        $response->assertRedirect(route('shopify.install', ['shop' => self::SHOP]));
    }

    public function test_a_forged_hmac_is_a_typed_403(): void
    {
        $this->installShop();

        $url = route('shopify.app', ['shop' => self::SHOP, 'hmac' => 'forged', 'timestamp' => time()]);

        $this->get($url)->assertForbidden();
    }

    public function test_a_missing_shop_is_a_typed_403_with_a_closed_csp(): void
    {
        $response = $this->get(route('shopify.app'));

        $response->assertForbidden();
        $response->assertHeader(self::CSP_HEADER, "frame-ancestors 'none';");
    }

    public function test_unset_credentials_are_a_typed_502(): void
    {
        config()->set('services.shopify.client_id', '');
        config()->set('services.shopify.client_secret', '');

        // No hmac (an empty secret would 403 the hmac wall first — also fail-closed).
        $this->get(route('shopify.app', ['shop' => self::SHOP]))->assertStatus(502);
    }

    public function test_a_hebrew_locale_renders_rtl(): void
    {
        $this->installShop();

        $response = $this->get($this->entryUrl(signed: true, locale: 'he'));

        $response->assertOk();
        $response->assertSee('dir="rtl"', escape: false);
        $response->assertSee(__('shopify_embedded.welcome.heading'));
    }

    // === HELPERS ===

    private function installShop(): Site
    {
        $account = Account::factory()->create();
        $site = Site::factory()->create(['account_id' => $account->id]);
        ShopifyConnection::factory()->forSite($site)->create(['shop_domain' => self::SHOP]);

        return $site;
    }

    private function entryUrl(bool $signed = false, bool $embedded = false, ?string $locale = null): string
    {
        $params = ['shop' => self::SHOP, 'timestamp' => (string) time()];

        if ($embedded) {
            $params['embedded'] = '1';
        }

        if ($locale !== null) {
            $params['locale'] = $locale;
        }

        if ($signed) {
            ksort($params);
            $params['hmac'] = hash_hmac('sha256', http_build_query($params), self::CLIENT_SECRET);
        }

        return route('shopify.app').'?'.http_build_query($params);
    }
}
