<?php

namespace App\Domain\Credits\Payments;

use App\Domain\Credits\CreditMath;
use App\Domain\Platform\PlatformSettings;
use App\Models\Account;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PayPlusProvider — the LOCKED v1 credit-purchase rail (ARCHITECTURE.md). The MERCHANT
 * pays the PLATFORM to top up credits; this talks to the platform's single PayPlus
 * account (credentials from config('services.payplus'), NOT per-merchant — the
 * merchant is the customer here, not the PayPlus account holder).
 *
 * Integration shape (mirrors the team's verified PayPlus integration):
 *  - Create page: POST {base}/PaymentPages/generateLink, auth on the two headers
 *    api-key + secret-key. We pass amount, currency, our refURL_callback (the webhook),
 *    and more_info = the deterministic provider_ref so the webhook echoes it back.
 *  - Webhook signature: PayPlus signs the raw callback body; the `hash` header is
 *    base64(HMAC-SHA256(rawBody, secret_key)). We FAIL CLOSED on a missing/forged hash
 *    (this is the platform revenue rail — every webhook must be signed, no exceptions).
 *  - Amount is the PROVIDER-CONFIRMED figure parsed from the signed body, converted to
 *    integer micro-USD selling value — never a client-reported number.
 *
 * No credential or payload is ever logged; only the safe shape (path + status).
 */
final class PayPlusProvider implements CreditPaymentProvider
{
    // === CONSTANTS ===
    public const PROVIDER_NAME = 'payplus';

    // PayPlus REST surface.
    private const PATH_GENERATE_LINK = '/PaymentPages/generateLink';

    // PayPlus authenticates on TWO headers (not a bearer token).
    private const HEADER_API_KEY = 'api-key';
    private const HEADER_SECRET_KEY = 'secret-key';

    // The header PayPlus signs the raw callback body with: base64(HMAC-SHA256(body, secret)).
    private const WEBHOOK_HASH_HEADER = 'hash';

    // PayPlus signals success on the page-create call with results.status === 'success'.
    private const RESULT_STATUS_SUCCESS = 'success';

    // Transaction status codes that mean an APPROVED payment on the webhook.
    private const APPROVED_CODES = ['000', '0', 'approved', 'success'];
    // Codes that mean a reversal/refund of a prior approval.
    private const REFUNDED_CODES = ['refunded', 'refund'];

    private const CONFIG_KEY = 'services.payplus';

    // Credential keys a super-admin may set in the platform Settings page (DB,
    // encrypted) — these take precedence over the env var; everything else
    // (base_url, currency, timeout) reads straight from config.
    private const SETTING_KEYS = [
        'api_key' => PlatformSettings::PAYPLUS_API_KEY,
        'secret_key' => PlatformSettings::PAYPLUS_SECRET_KEY,
        'page_uid' => PlatformSettings::PAYPLUS_PAGE_UID,
        'webhook_secret' => PlatformSettings::PAYPLUS_WEBHOOK_SECRET,
    ];

    public function name(): string
    {
        return self::PROVIDER_NAME;
    }

    public function initiatePurchase(Account $account, int $amountMicroUsd, array $context = []): PurchaseIntent
    {
        $amountMajor = $this->microToMajorUnits($amountMicroUsd);

        // The deterministic page reference we echo through more_info; the webhook
        // returns it so we can resolve the credit_purchases row + the idempotency key.
        $providerRef = $context['provider_ref'] ?? null;

        if ($providerRef === null || $providerRef === '') {
            return PurchaseIntent::failed(self::PROVIDER_NAME, 'missing_provider_ref', 'A provider_ref is required to initiate a PayPlus purchase.');
        }

        $payload = array_filter([
            'payment_page_uid' => $this->cfg('page_uid'),
            'amount' => $amountMajor,
            'currency_code' => $this->currency(),
            'charge_method' => 1, // 1 = charge (J4). Not a token/approval-only flow.
            'more_info' => $providerRef,
            'refURL_callback' => $context['callback_url'] ?? null,
            'refURL_success' => $context['success_url'] ?? null,
            'refURL_failure' => $context['failure_url'] ?? null,
            'refURL_cancel' => $context['cancel_url'] ?? null,
        ], static fn ($v): bool => $v !== null && $v !== '');

        try {
            $response = $this->client()->post($this->endpoint(self::PATH_GENERATE_LINK), $payload);
        } catch (Throwable $e) {
            Log::warning('payplus.purchase.transport_error', ['path' => self::PATH_GENERATE_LINK, 'exception' => $e::class]);

            return PurchaseIntent::failed(self::PROVIDER_NAME, 'transport_error', 'Could not reach the payment provider.');
        }

        $body = $response->json();

        if (! is_array($body)) {
            return PurchaseIntent::failed(self::PROVIDER_NAME, 'malformed_response', 'The payment provider returned a non-JSON body.');
        }

        $status = strtolower((string) data_get($body, 'results.status', ''));

        if ($status !== self::RESULT_STATUS_SUCCESS) {
            $message = (string) data_get($body, 'results.description', 'The payment provider rejected the request.');

            return PurchaseIntent::failed(self::PROVIDER_NAME, (string) data_get($body, 'results.code', 'rejected'), $message);
        }

        $url = (string) (data_get($body, 'data.payment_page_link') ?? data_get($body, 'data.url') ?? '');

        if ($url === '') {
            return PurchaseIntent::failed(self::PROVIDER_NAME, 'no_redirect_url', 'The payment provider did not return a payment page link.');
        }

        return PurchaseIntent::created(
            provider: self::PROVIDER_NAME,
            providerRef: $providerRef,
            redirectUrl: $url,
            amountMicroUsd: $amountMicroUsd,
        );
    }

    public function verifyAndParseWebhook(Request $request): ?PurchaseResult
    {
        $raw = $request->getContent();
        $secret = (string) $this->cfg('secret_key');
        $sentHash = (string) $request->header(self::WEBHOOK_HASH_HEADER, '');

        // FAIL CLOSED: the platform revenue rail requires a valid signature. A missing
        // secret (misconfig), a missing hash header (forged/unsigned), or a mismatch all
        // reject — we never credit on an unverifiable webhook.
        if ($secret === '' || $sentHash === '') {
            Log::warning('payplus.webhook.unsigned', ['has_secret' => $secret !== '', 'has_hash' => $sentHash !== '']);

            return null;
        }

        $expected = base64_encode(hash_hmac('sha256', $raw, $secret, true));

        if (! hash_equals($expected, $sentHash)) {
            Log::warning('payplus.webhook.bad_signature', []);

            return null;
        }

        $payload = (array) $request->json()->all();

        $providerRef = (string) (
            data_get($payload, 'transaction.more_info')
            ?? data_get($payload, 'more_info')
            ?? data_get($payload, 'data.transaction.more_info')
            ?? ''
        );

        if ($providerRef === '') {
            Log::warning('payplus.webhook.no_provider_ref', []);

            return null;
        }

        $statusCode = strtolower((string) (
            data_get($payload, 'transaction.status_code')
            ?? data_get($payload, 'status_code')
            ?? data_get($payload, 'transaction.status')
            ?? data_get($payload, 'status')
            ?? ''
        ));

        // The provider-confirmed amount (major units) -> integer micro-USD selling value.
        $amountMajor = (float) (
            data_get($payload, 'transaction.amount')
            ?? data_get($payload, 'amount')
            ?? data_get($payload, 'data.amount')
            ?? 0
        );

        $status = $this->mapStatus($statusCode);

        return PurchaseResult::make(
            provider: self::PROVIDER_NAME,
            providerRef: $providerRef,
            status: $status,
            amountMicroUsd: $this->majorUnitsToMicro($amountMajor),
            raw: $payload,
        );
    }

    // === Internals ===

    /** Map a PayPlus transaction status code to our credit_purchases status. */
    private function mapStatus(string $code): string
    {
        if (in_array($code, self::APPROVED_CODES, true)) {
            return PurchaseResult::STATUS_PAID;
        }

        if (in_array($code, self::REFUNDED_CODES, true)) {
            return PurchaseResult::STATUS_REFUNDED;
        }

        return PurchaseResult::STATUS_FAILED;
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
            self::HEADER_API_KEY => (string) $this->cfg('api_key'),
            self::HEADER_SECRET_KEY => (string) $this->cfg('secret_key'),
            'Content-Type' => 'application/json',
        ])
            ->timeout((int) ($this->cfg('timeout') ?? 30))
            ->acceptJson();
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) $this->cfg('base_url'), '/').$path;
    }

    private function cfg(string $key): mixed
    {
        // A credential set in the Settings page (DB) wins; PlatformSettings::resolve
        // itself falls back to the env var when the DB value is unset.
        $settingKey = self::SETTING_KEYS[$key] ?? null;

        if ($settingKey !== null) {
            return app(PlatformSettings::class)->resolve($settingKey);
        }

        return config(self::CONFIG_KEY.'.'.$key);
    }

    /** The PayPlus charge currency (USD default; ILS path is the Q1-open decision). */
    private function currency(): string
    {
        return (string) ($this->cfg('currency') ?? 'USD');
    }

    /** Integer micro-USD -> provider major units (e.g. 5_000_000 -> 5.00). */
    private function microToMajorUnits(int $micro): float
    {
        return round(CreditMath::microToUsd($micro), 2);
    }

    /** Provider major units -> integer micro-USD selling value. */
    private function majorUnitsToMicro(float $major): int
    {
        return CreditMath::usdToMicro($major);
    }
}
