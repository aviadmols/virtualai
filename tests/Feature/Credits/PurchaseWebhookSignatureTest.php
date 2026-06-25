<?php

namespace Tests\Feature\Credits;

use App\Models\Account;
use App\Models\CreditLedger;
use App\Models\CreditPurchase;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The webhook HTTP surface end-to-end: signature verification + the idempotent ledger
 * write, driven through the real route + controller. The PayPlus `hash` header is
 * base64(HMAC-SHA256(rawBody, secret_key)); a forged/unsigned/wrong-secret body is
 * REJECTED (no ledger row, no credit). The route is registered in routes/webhooks.php
 * with no CSRF (signature IS the auth).
 */
class PurchaseWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_payplus_secret';
    private const WEBHOOK_URL = '/webhooks/credits/payplus';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.payplus', [
            'api_key' => 'test_api_key',
            'secret_key' => self::SECRET,
            'page_uid' => 'test_page_uid',
            'base_url' => 'https://restapi.payplus.example/api/v1.0',
            'currency' => 'USD',
            'timeout' => 10,
        ]);
    }

    /** A correctly-signed approved webhook credits the account exactly once. */
    public function test_signed_approved_webhook_credits_once(): void
    {
        [$account, $purchase] = $this->seedPendingPurchase(6_000_000);
        $openingBalance = $account->fresh()->balance_micro_usd;

        $body = $this->approvedBody($purchase->provider_ref, 6.00);
        $response = $this->postSigned($body, self::SECRET);

        $response->assertOk();
        $response->assertJson(['ok' => true, 'credited' => true, 'status' => 'paid']);

        $count = Tenant::run($account, fn () => CreditLedger::query()->where('type', 'purchase')->count());
        $this->assertSame(1, $count);
        $this->assertSame($openingBalance + 6_000_000, $account->fresh()->balance_micro_usd);
    }

    /** A FORGED signature (wrong secret) is rejected: no ledger row, no credit. */
    public function test_forged_signature_is_rejected_no_credit(): void
    {
        [$account, $purchase] = $this->seedPendingPurchase(6_000_000);
        $openingBalance = $account->fresh()->balance_micro_usd;

        $body = $this->approvedBody($purchase->provider_ref, 6.00);
        $response = $this->postSigned($body, 'WRONG_SECRET');

        $response->assertOk(); // ack so the provider stops retrying
        $response->assertJson(['credited' => false]);

        $count = Tenant::run($account, fn () => CreditLedger::query()->where('type', 'purchase')->count());
        $this->assertSame(0, $count);
        $this->assertSame($openingBalance, $account->fresh()->balance_micro_usd);
        $this->assertSame(CreditPurchase::STATUS_PENDING, $purchase->fresh()->status);
    }

    /** An UNSIGNED webhook (no hash header) is rejected: no ledger row, no credit. */
    public function test_unsigned_webhook_is_rejected_no_credit(): void
    {
        [$account, $purchase] = $this->seedPendingPurchase(6_000_000);

        $body = $this->approvedBody($purchase->provider_ref, 6.00);
        $response = $this->call('POST', self::WEBHOOK_URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body); // no hash header

        $response->assertOk();
        $response->assertJson(['credited' => false]);

        $count = Tenant::run($account, fn () => CreditLedger::query()->where('type', 'purchase')->count());
        $this->assertSame(0, $count);
    }

    /** Replaying the SAME signed webhook twice credits exactly once (HTTP-level idempotency). */
    public function test_replayed_signed_webhook_credits_once(): void
    {
        [$account, $purchase] = $this->seedPendingPurchase(8_000_000);
        $openingBalance = $account->fresh()->balance_micro_usd;

        $body = $this->approvedBody($purchase->provider_ref, 8.00);

        $this->postSigned($body, self::SECRET)->assertOk();
        $this->postSigned($body, self::SECRET)->assertOk(); // replay

        $count = Tenant::run($account, fn () => CreditLedger::query()->where('type', 'purchase')->count());
        $this->assertSame(1, $count);
        $this->assertSame($openingBalance + 8_000_000, $account->fresh()->balance_micro_usd);
    }

    // === Helpers ===

    private function seedPendingPurchase(int $amountMicro): array
    {
        $account = Account::factory()->create();
        $purchase = CreditPurchase::factory()->forAccount($account)->state([
            'credits_micro_usd' => $amountMicro,
            'amount_usd' => round($amountMicro / 1_000_000, 2),
        ])->create();

        return [$account, $purchase];
    }

    private function approvedBody(string $providerRef, float $amount): string
    {
        return (string) json_encode([
            'transaction' => [
                'more_info' => $providerRef,
                'status_code' => '000',
                'amount' => $amount,
                'uid' => 'tx_'.$providerRef,
            ],
        ]);
    }

    /** POST the raw body with a valid (or invalid) PayPlus `hash` signature header. */
    private function postSigned(string $body, string $signingSecret)
    {
        $hash = base64_encode(hash_hmac('sha256', $body, $signingSecret, true));

        return $this->call('POST', self::WEBHOOK_URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HASH' => $hash,
        ], $body);
    }
}
