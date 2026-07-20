<?php

namespace Tests\Feature\Shopify;

use App\Domain\Shopify\Auth\ShopifyOAuth;
use App\Domain\Shopify\Auth\ShopifyOAuthException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Token exchange: an App Bridge session token becomes an EXPIRING offline access token.
 * Shopify no longer accepts non-expiring offline tokens for the Admin API (403 "Non-expiring
 * access tokens are no longer accepted"), so the embedded app refreshes the store's token
 * this way. These pin the EXACT Shopify token-exchange request shape — a wrong body fails live.
 */
final class ShopifyTokenExchangeTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'lets-sell-book.myshopify.com';

    private const TOKEN_URL = 'https://lets-sell-book.myshopify.com/admin/oauth/access_token';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.shopify.client_id', 'test-client-id');
        config()->set('services.shopify.client_secret', 'test-client-secret');
    }

    public function test_a_session_token_is_exchanged_for_an_offline_token_with_the_exact_grant(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response([
                'access_token' => 'shpat_expiring_abc',
                'scope' => 'read_products,write_products,read_themes',
                'expires_in' => 86399,
            ]),
        ]);

        $token = app(ShopifyOAuth::class)->exchangeSessionToken(self::SHOP, 'session.jwt.token');

        $this->assertSame('shpat_expiring_abc', $token->accessToken);
        $this->assertSame('read_products,write_products,read_themes', $token->scopes);
        // expires_in is parsed => this is the EXPIRING token the Admin API accepts.
        $this->assertSame(86399, $token->expiresIn);

        // The exact Shopify token-exchange contract: the session token is the subject, the
        // requested type is an OFFLINE access token, via the OAuth token-exchange grant, and —
        // THE fix — expiring=1 so Shopify issues an EXPIRING (accepted) token, not the default
        // non-expiring one the Admin API now rejects.
        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === self::TOKEN_URL
                && $body['grant_type'] === 'urn:ietf:params:oauth:grant-type:token-exchange'
                && $body['subject_token'] === 'session.jwt.token'
                && $body['subject_token_type'] === 'urn:ietf:params:oauth:token-type:id_token'
                && $body['requested_token_type'] === 'urn:shopify:params:oauth:token-type:offline-access-token'
                && $body['expiring'] === '1'
                && $body['client_id'] === 'test-client-id'
                && $body['client_secret'] === 'test-client-secret';
        });
    }

    public function test_a_rejected_exchange_is_a_typed_failure_not_a_500(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['errors' => 'invalid_subject_token'], 400),
        ]);

        $this->expectException(ShopifyOAuthException::class);

        app(ShopifyOAuth::class)->exchangeSessionToken(self::SHOP, 'bad.session.token');
    }

    public function test_a_non_myshopify_shop_never_reaches_the_exchange(): void
    {
        Http::fake();

        $this->expectException(ShopifyOAuthException::class);

        try {
            app(ShopifyOAuth::class)->exchangeSessionToken('evil.example.com', 'session.jwt.token');
        } finally {
            Http::assertNothingSent();
        }
    }
}
