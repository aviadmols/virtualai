<?php

namespace Tests\Feature\Shopify;

use App\Http\Middleware\ShopifyFrameAncestors;
use App\Models\Account;
use App\Models\Generation;
use App\Models\ShopifyConnection;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The embedded API behind the session token: the session bridge (JWT -> partitioned
 * cookie login) and the bootstrap payload. Tenant-safe (a token for shop A can never
 * read shop B), secret-free (site_key ships, widget_secret + offline token never do).
 */
class ShopifyEmbeddedApiTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const CLIENT_ID = 'test-embedded-client-id';

    private const CLIENT_SECRET = 'test-embedded-client-secret';

    private const SHOP = 'embedded-shop.myshopify.com';

    private const OTHER_SHOP = 'other-shop.myshopify.com';

    private const BOOTSTRAP = '/shopify/app/api/bootstrap';

    private const SESSION = '/shopify/app/session';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.shopify.client_id', self::CLIENT_ID);
        config()->set('services.shopify.client_secret', self::CLIENT_SECRET);
        config()->set('session.same_site', 'none');
        config()->set('session.secure', true);
        config()->set('session.partitioned', true);
        // The theme inspector must never make a real call from these tests.
        Http::preventStrayRequests();
        Http::fake(['*/admin/api/*' => Http::response(['data' => ['themes' => ['nodes' => []]]])]);
    }

    public function test_the_session_bridge_logs_the_owner_in_and_returns_the_dashboard_url(): void
    {
        [$site, $owner] = $this->installShop(self::SHOP);

        $response = $this->withToken($this->mintToken(self::SHOP))->postJson(self::SESSION);

        $response->assertOk();
        $response->assertJsonPath('dashboard_url', '/merchant/'.$site->slug);
        $this->assertAuthenticatedAs($owner);
        // The shop is stamped in the session so the panel CSP can name it.
        $this->assertSame(self::SHOP, session(ShopifyFrameAncestors::SESSION_SHOP_DOMAIN));

        // The same bridged session opens Filament inside this iframe; it must not bounce
        // to /merchant/login or require a top-level escape.
        $this->get('/merchant/'.$site->slug)->assertOk();

        $cookieHeader = strtolower(implode('; ', $response->headers->all('set-cookie')));
        $this->assertStringContainsString('secure', $cookieHeader);
        $this->assertStringContainsString('samesite=none', $cookieHeader);
        $this->assertStringContainsString('partitioned', $cookieHeader);
    }

    public function test_an_invalid_token_never_logs_anyone_in(): void
    {
        $this->installShop(self::SHOP);

        $this->withToken('not.a.jwt')->postJson(self::SESSION)->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_the_bootstrap_payload_is_whitelisted_and_carries_no_secrets(): void
    {
        [$site, $owner] = $this->installShop(self::SHOP);
        $secret = Site::query()->withoutGlobalScopes()->whereKey($site->getKey())->first()->widget_secret;

        $response = $this->withToken($this->mintToken(self::SHOP))->getJson(self::BOOTSTRAP);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('site.site_key', $site->site_key);
        $response->assertJsonPath('owner.email', $owner->email);
        $response->assertJsonPath('connection.status', ShopifyConnection::STATUS_INSTALLED);
        $response->assertJsonPath('links.dashboard', config('app.url').'/merchant/'.$site->slug);

        // The widget_secret and any offline token NEVER appear anywhere in the body.
        $body = $response->getContent();
        $this->assertStringNotContainsString((string) $secret, $body);
        $this->assertStringNotContainsString('shpat_', $body);
    }

    public function test_the_checklist_flags_reflect_real_state(): void
    {
        [$site] = $this->installShop(self::SHOP);

        // Nothing yet.
        $before = $this->withToken($this->mintToken(self::SHOP))->getJson(self::BOOTSTRAP);
        $before->assertJsonPath('checklist.products_imported', false);
        $before->assertJsonPath('checklist.first_generation', false);

        // A succeeded generation flips the try-on flag.
        Generation::factory()->create([
            'account_id' => $site->account_id,
            'site_id' => $site->getKey(),
            'status' => Generation::STATUS_SUCCEEDED,
        ]);

        $after = $this->withToken($this->mintToken(self::SHOP))->getJson(self::BOOTSTRAP);
        $after->assertJsonPath('checklist.first_generation', true);
    }

    public function test_a_token_for_shop_a_can_never_read_shop_b(): void
    {
        [$siteA] = $this->installShop(self::SHOP);
        [$siteB] = $this->installShop(self::OTHER_SHOP);

        $response = $this->withToken($this->mintToken(self::SHOP))->getJson(self::BOOTSTRAP);

        $response->assertOk();
        $response->assertJsonPath('site.id', $siteA->getKey());
        $response->assertJsonPath('site.slug', $siteA->slug);
        $this->assertStringNotContainsString($siteB->slug, $response->getContent());
        $this->assertStringNotContainsString($siteB->site_key, $response->getContent());
    }

    public function test_a_stale_uninstalled_connection_self_heals_on_a_valid_session_token(): void
    {
        [$site, $owner] = $this->installShop(self::SHOP);

        // Simulate the "We couldn't load your account" dead-end: an uninstall webhook flipped the
        // connection to 'uninstalled', but Shopify still has the app installed (managed install /
        // reopen), so App Bridge keeps minting a valid session token for this shop.
        $connection = ShopifyConnection::query()->withoutGlobalScopes()
            ->where('shop_domain', self::SHOP)->firstOrFail();
        $connection->forceFill(['status' => ShopifyConnection::STATUS_UNINSTALLED])->save();

        // A valid token is Shopify's own proof of installation — the app must load, not reject.
        $response = $this->withToken($this->mintToken(self::SHOP))->postJson(self::SESSION);

        $response->assertOk();
        $response->assertJsonPath('dashboard_url', '/merchant/'.$site->slug);
        $this->assertAuthenticatedAs($owner);

        // The stale status was healed in place — the next open sees a normal installed shop.
        $this->assertSame(
            ShopifyConnection::STATUS_INSTALLED,
            $connection->fresh()->status,
        );
    }

    // === HELPERS ===

    /** @return array{0: Site, 1: User} */
    private function installShop(string $shopDomain): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->create(['account_id' => $account->id]);
        ShopifyConnection::factory()->forSite($site)->create(['shop_domain' => $shopDomain]);
        $owner = User::factory()->create(['account_id' => $account->id]);

        return [$site, $owner];
    }

    private function mintToken(string $shop): string
    {
        $now = time();
        $payload = [
            'iss' => 'https://'.$shop.'/admin',
            'dest' => 'https://'.$shop,
            'aud' => self::CLIENT_ID,
            'sub' => '2002',
            'exp' => $now + 60,
            'nbf' => $now - 5,
            'iat' => $now - 5,
        ];

        $h = $this->b64url((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $p = $this->b64url((string) json_encode($payload));
        $s = $this->b64url(hash_hmac('sha256', $h.'.'.$p, self::CLIENT_SECRET, true));

        return $h.'.'.$p.'.'.$s;
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
