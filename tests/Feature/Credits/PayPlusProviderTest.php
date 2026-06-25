<?php

namespace Tests\Feature\Credits;

use App\Domain\Credits\Payments\PayPlusProvider;
use App\Domain\Credits\Payments\PurchaseResult;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PayPlusProvider unit-ish coverage (HTTP MOCKED): the page-create call shape, the
 * webhook signature verification (the `hash` header), the status mapping, and the
 * never-trust-the-client-amount rule (the provider parses the provider-confirmed amount).
 */
class PayPlusProviderTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'pp_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.payplus', [
            'api_key' => 'pp_api',
            'secret_key' => self::SECRET,
            'page_uid' => 'pp_page',
            'base_url' => 'https://restapi.payplus.example/api/v1.0',
            'currency' => 'USD',
            'timeout' => 10,
        ]);
    }

    public function test_initiate_posts_to_generate_link_and_returns_the_page(): void
    {
        Http::fake([
            '*/PaymentPages/generateLink' => Http::response([
                'results' => ['status' => 'success'],
                'data' => ['payment_page_link' => 'https://pay.example/x'],
            ], 200),
        ]);

        $account = Account::factory()->create();
        $intent = app(PayPlusProvider::class)->initiatePurchase($account, 12_000_000, ['provider_ref' => 'ref-1']);

        $this->assertTrue($intent->ok);
        $this->assertSame('https://pay.example/x', $intent->redirectUrl);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/PaymentPages/generateLink')
                && $request->hasHeader('api-key', 'pp_api')
                && $request->hasHeader('secret-key', self::SECRET)
                && (float) $body['amount'] === 12.00          // micro -> major units
                && $body['more_info'] === 'ref-1';            // the echoed provider_ref
        });
    }

    public function test_initiate_without_provider_ref_fails_fast(): void
    {
        $account = Account::factory()->create();
        $intent = app(PayPlusProvider::class)->initiatePurchase($account, 1_000_000, []);

        $this->assertFalse($intent->ok);
        $this->assertSame('missing_provider_ref', $intent->errorCode);
    }

    public function test_provider_rejection_is_a_failed_intent_not_an_exception(): void
    {
        Http::fake([
            '*/PaymentPages/generateLink' => Http::response([
                'results' => ['status' => 'error', 'code' => '401', 'description' => 'bad key'],
            ], 200),
        ]);

        $account = Account::factory()->create();
        $intent = app(PayPlusProvider::class)->initiatePurchase($account, 1_000_000, ['provider_ref' => 'ref-2']);

        $this->assertFalse($intent->ok);
        $this->assertSame('401', $intent->errorCode);
    }

    public function test_verify_parses_a_signed_approved_webhook(): void
    {
        $body = (string) json_encode([
            'transaction' => ['more_info' => 'ref-9', 'status_code' => '000', 'amount' => 15.00],
        ]);
        $request = $this->signedRequest($body, self::SECRET);

        $result = app(PayPlusProvider::class)->verifyAndParseWebhook($request);

        $this->assertNotNull($result);
        $this->assertTrue($result->isPaid());
        $this->assertSame('ref-9', $result->providerRef);
        // The PROVIDER-confirmed amount (15.00 major) -> 15_000_000 micro.
        $this->assertSame(15_000_000, $result->amountMicroUsd);
    }

    public function test_verify_rejects_a_forged_signature(): void
    {
        $body = (string) json_encode(['transaction' => ['more_info' => 'ref-9', 'status_code' => '000', 'amount' => 15.00]]);
        $request = $this->signedRequest($body, 'WRONG');

        $this->assertNull(app(PayPlusProvider::class)->verifyAndParseWebhook($request));
    }

    public function test_verify_rejects_an_unsigned_webhook(): void
    {
        $body = (string) json_encode(['transaction' => ['more_info' => 'ref-9', 'status_code' => '000']]);
        $request = Request::create('/webhooks/credits/payplus', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);

        $this->assertNull(app(PayPlusProvider::class)->verifyAndParseWebhook($request));
    }

    public function test_declined_status_code_maps_to_failed(): void
    {
        $body = (string) json_encode(['transaction' => ['more_info' => 'ref-9', 'status_code' => '999', 'amount' => 5.00]]);
        $result = app(PayPlusProvider::class)->verifyAndParseWebhook($this->signedRequest($body, self::SECRET));

        $this->assertNotNull($result);
        $this->assertSame(PurchaseResult::STATUS_FAILED, $result->status);
    }

    private function signedRequest(string $body, string $signingSecret): Request
    {
        $hash = base64_encode(hash_hmac('sha256', $body, $signingSecret, true));

        return Request::create('/webhooks/credits/payplus', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HASH' => $hash,
        ], $body);
    }
}
