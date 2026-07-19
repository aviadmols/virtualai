<?php

namespace Tests\Feature\Shopify;

use App\Domain\Shopify\Auth\ShopifySessionToken;
use App\Http\Middleware\VerifyShopifySessionToken;
use App\Http\Shopify\ShopifyEmbeddedContext;
use App\Models\Account;
use App\Models\ShopifyConnection;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The App Bridge session-token walls. One test per verifier check — deleting any single
 * check in ShopifySessionToken::verify() fails a NAMED test here (mutation-verified),
 * and the middleware behind it authenticates the account owner inside the tenant bind,
 * or answers a typed 401/403, never a 500.
 */
class ShopifySessionTokenTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const CLIENT_ID = 'test-embedded-client-id';

    private const CLIENT_SECRET = 'test-embedded-client-secret';

    private const SHOP = 'embedded-shop.myshopify.com';

    private const OTHER_SHOP = 'other-shop.myshopify.com';

    private const PROBE_PATH = '/_test/embedded-probe';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.shopify.client_id', self::CLIENT_ID);
        config()->set('services.shopify.client_secret', self::CLIENT_SECRET);

        // A probe route behind the middleware: echoes the resolved context facts.
        Route::middleware(VerifyShopifySessionToken::class)->get(self::PROBE_PATH, function () {
            $context = ShopifyEmbeddedContext::of(request());

            return response()->json([
                'site_id' => $context->site->getKey(),
                'site_slug' => $context->site->slug,
                'account_id' => $context->accountId(),
                'owner_email' => $context->owner->email,
                'auth_id' => Auth::id(),
            ]);
        });
    }

    // === The verifier walls (unit level) ===

    public function test_a_valid_token_verifies_and_carries_the_shop(): void
    {
        $payload = $this->verifier()->verify($this->mintToken());

        $this->assertNotNull($payload);
        $this->assertSame(self::SHOP, $payload->shopDomain);
        $this->assertSame('1001', $payload->userId);
    }

    public function test_a_token_expired_beyond_leeway_is_rejected(): void
    {
        $this->assertNull($this->verifier()->verify($this->mintToken(['exp' => time() - 30])));
    }

    public function test_a_token_expired_within_leeway_still_verifies(): void
    {
        $this->assertNotNull($this->verifier()->verify($this->mintToken(['exp' => time() - 2])));
    }

    public function test_a_future_nbf_is_rejected(): void
    {
        $this->assertNull($this->verifier()->verify($this->mintToken(['nbf' => time() + 60])));
    }

    public function test_a_wrong_audience_is_rejected(): void
    {
        $this->assertNull($this->verifier()->verify($this->mintToken(['aud' => 'another-apps-client-id'])));
    }

    public function test_a_non_myshopify_dest_is_rejected(): void
    {
        $this->assertNull($this->verifier()->verify($this->mintToken([
            'dest' => 'https://evil.example.com',
            'iss' => 'https://evil.example.com/admin',
        ])));
    }

    public function test_an_iss_not_matching_dest_is_rejected(): void
    {
        $this->assertNull($this->verifier()->verify($this->mintToken([
            'iss' => 'https://'.self::OTHER_SHOP.'/admin',
        ])));
    }

    public function test_a_forged_signature_is_rejected(): void
    {
        $this->assertNull($this->verifier()->verify($this->mintToken(secret: 'not-the-client-secret')));
    }

    public function test_an_alg_none_header_is_rejected_even_when_signed(): void
    {
        // Correctly signed over the segments, but the header claims alg=none — the
        // header wall must kill it independently of the signature wall.
        $this->assertNull($this->verifier()->verify(
            $this->mintToken(header: ['alg' => 'none', 'typ' => 'JWT']),
        ));
    }

    public function test_a_two_part_token_is_rejected(): void
    {
        $token = $this->mintToken();
        $twoParts = substr($token, 0, (int) strrpos($token, '.'));

        $this->assertNull($this->verifier()->verify($twoParts));
    }

    public function test_a_missing_sub_is_rejected(): void
    {
        $this->assertNull($this->verifier()->verify($this->mintToken(['sub' => ''])));
    }

    public function test_an_empty_client_secret_fails_closed(): void
    {
        $token = $this->mintToken();
        config()->set('services.shopify.client_secret', '');

        $this->assertNull($this->verifier()->verify($token));
    }

    public function test_a_placeholder_client_secret_fails_closed(): void
    {
        // A shipped REPLACE_... secret is PUBLICLY known — a token signed with it must
        // NOT verify, or a misconfigured deploy would authenticate anyone.
        $placeholder = 'REPLACE_WITH_REAL_SHOPIFY_SECRET';
        config()->set('services.shopify.client_secret', $placeholder);

        $this->assertNull($this->verifier()->verify($this->mintToken(secret: $placeholder)));
    }

    public function test_a_dest_with_a_trailing_newline_is_rejected(): void
    {
        // Without PCRE_DOLLAR_ENDONLY the shop regex would match "...myshopify.com\n"
        // and (since iss mirrors dest) the token would pass — this pins the `D` flag.
        $shop = self::SHOP."\n";

        $this->assertNull($this->verifier()->verify($this->mintToken([
            'dest' => 'https://'.$shop,
            'iss' => 'https://'.$shop.'/admin',
        ])));
    }

    public function test_an_array_dest_claim_fails_closed(): void
    {
        // A signed-but-malformed claim must fail closed, never raise a string-cast warning.
        $this->assertNull($this->verifier()->verify($this->mintToken(['dest' => ['not', 'a', 'string']])));
    }

    // === The middleware (request level) ===

    public function test_a_valid_token_authenticates_the_account_owner_inside_the_tenant(): void
    {
        [$site, $owner] = $this->installShop(self::SHOP);

        $response = $this->withToken($this->mintToken())->getJson(self::PROBE_PATH);

        $response->assertOk();
        $response->assertJson([
            'site_id' => $site->getKey(),
            'account_id' => (int) $site->account_id,
            'owner_email' => $owner->email,
            'auth_id' => $owner->getKey(),
        ]);
    }

    public function test_a_missing_authorization_header_is_a_typed_401(): void
    {
        $this->installShop(self::SHOP);

        $this->getJson(self::PROBE_PATH)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'missing_token');
    }

    public function test_an_invalid_token_is_a_typed_401(): void
    {
        $this->installShop(self::SHOP);

        $this->withToken('not.a.jwt')->getJson(self::PROBE_PATH)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_token');
    }

    public function test_a_shop_never_installed_is_a_typed_401(): void
    {
        // Valid token, but no connection row exists for the shop.
        $this->withToken($this->mintToken())->getJson(self::PROBE_PATH)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unknown_shop');
    }

    public function test_an_uninstalled_shop_answers_exactly_like_an_unknown_one(): void
    {
        [$site] = $this->installShop(self::SHOP);
        DB::table('shopify_connections')
            ->where('shop_domain', self::SHOP)
            ->update(['status' => ShopifyConnection::STATUS_UNINSTALLED]);

        $this->withToken($this->mintToken())->getJson(self::PROBE_PATH)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unknown_shop');
    }

    public function test_an_account_with_no_owner_is_a_typed_403(): void
    {
        [$site] = $this->installShop(self::SHOP, withOwner: false);

        $this->withToken($this->mintToken())->getJson(self::PROBE_PATH)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'no_owner');
    }

    public function test_a_token_for_shop_a_can_never_read_shop_b(): void
    {
        [$siteA] = $this->installShop(self::SHOP);
        [$siteB] = $this->installShop(self::OTHER_SHOP);

        $response = $this->withToken($this->mintToken())->getJson(self::PROBE_PATH);

        $response->assertOk();
        $this->assertSame($siteA->getKey(), $response->json('site_id'));
        $this->assertNotSame($siteB->getKey(), $response->json('site_id'));
        $this->assertSame((int) $siteA->account_id, $response->json('account_id'));
    }

    // === Fixtures + helpers ===

    /** @return array{0: Site, 1: ?User} an installed shop: account + site + connection (+ owner). */
    private function installShop(string $shopDomain, bool $withOwner = true): array
    {
        $account = Account::factory()->create();
        $site = Site::factory()->create(['account_id' => $account->id]);
        ShopifyConnection::factory()->forSite($site)->create(['shop_domain' => $shopDomain]);

        $owner = $withOwner ? User::factory()->create(['account_id' => $account->id]) : null;

        return [$site, $owner];
    }

    private function verifier(): ShopifySessionToken
    {
        return app(ShopifySessionToken::class);
    }

    /**
     * Mint a session token the way App Bridge would: HS256 over header.payload with the
     * client secret. Overrides patch individual claims; a different secret forges.
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>|null  $header
     */
    private function mintToken(array $claims = [], ?string $secret = null, ?array $header = null): string
    {
        $now = time();

        $payload = array_merge([
            'iss' => 'https://'.self::SHOP.'/admin',
            'dest' => 'https://'.self::SHOP,
            'aud' => self::CLIENT_ID,
            'sub' => '1001',
            'exp' => $now + 60,
            'nbf' => $now - 5,
            'iat' => $now - 5,
            'jti' => 'jti-'.$now,
            'sid' => 'sid-abc',
        ], $claims);

        $headerB64 = $this->b64url((string) json_encode($header ?? ['alg' => 'HS256', 'typ' => 'JWT']));
        $payloadB64 = $this->b64url((string) json_encode($payload));
        $signature = $this->b64url(hash_hmac(
            'sha256',
            $headerB64.'.'.$payloadB64,
            $secret ?? self::CLIENT_SECRET,
            true,
        ));

        return $headerB64.'.'.$payloadB64.'.'.$signature;
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
