<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\KlingCost;
use App\Domain\Ai\KlingImageClient;
use App\Domain\Ai\KlingVideoClient;
use App\Domain\Ai\ParsedCost;
use App\Domain\Credits\CreditMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Kling's REAL cost, parsed out of its own responses.
 *
 * The law, in order: the cash price Kling billed (final_balance_deduction.list_price for images,
 * the cash billing[] lines for video) IS the charge; the admin hint is only the reservation
 * estimate and takes over ONLY when there is no cash price (a resource-package/unit account); with
 * neither, the cost is honestly UNAVAILABLE and the money path fails closed — never a $0 charge,
 * never a guessed number. list_price is a STRING, so the parser is defensive: bool / empty /
 * non-numeric / negative / zero / NaN / INF are all "no price".
 */
class KlingCostParsingTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api-singapore.klingai.com';

    private const IMAGE_MODEL = 'kling-v2-1';

    private const TASK = 'task-cost-1';

    private const QUERY_IMAGE = self::BASE.'/v1/images/generations/'.self::TASK;

    private const SUBMIT_IMAGE = self::BASE.'/v1/images/generations';

    private const VIDEO_TASK_ID = '/v1/videos/image2video|vid-1';

    private const QUERY_VIDEO = self::BASE.'/v1/videos/image2video/vid-1';

    private const VIDEO_URL = 'https://cdn.klingai.test/out.mp4';

    // The admin hint (the reservation estimate) — deliberately DIFFERENT from the list_price below,
    // so a test that passes could only have used the real price.
    private const HINT_MICRO_USD = 28_000;   // $0.028

    private const LIST_PRICE = '0.056';      // $0.056 — what Kling actually billed

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.kling.api_key', 'api-key-kling-test');
        config()->set('services.kling.base_url', self::BASE);
        config()->set('services.kling.timeout', 30);
        Sleep::fake();
    }

    private function imageClient(): KlingImageClient
    {
        return app(KlingImageClient::class);
    }

    /** A succeeded IMAGE task envelope, optionally carrying Kling's deduction block. @param array<string,mixed> $deduction */
    private function imageTask(array $deduction = []): array
    {
        return [
            'code' => 0,
            'message' => 'SUCCEED',
            'data' => [
                'task_id' => self::TASK,
                'task_status' => 'succeed',
                'task_result' => ['images' => [['index' => 0, 'url' => 'https://cdn.klingai.test/out.png']]],
            ] + $deduction,
        ];
    }

    public function test_the_real_list_price_is_the_charge_and_beats_the_admin_hint(): void
    {
        $response = $this->imageTask([
            // Kling answers with the cash it deducted. list_price is a STRING.
            'final_balance_deduction' => ['quota' => 56, 'list_price' => self::LIST_PRICE],
        ]);

        $cost = $this->imageClient()->parseCost($response, self::HINT_MICRO_USD);

        $this->assertTrue($cost->available);
        $this->assertSame(ParsedCost::SOURCE_INLINE, $cost->source);
        // The REAL price, not the hint ($0.028).
        $this->assertEqualsWithDelta(0.056, (float) $cost->costUsd, 0.000001);
        $this->assertSame(56_000, CreditMath::usdToMicro((float) $cost->costUsd));
    }

    public function test_a_unit_billed_account_carries_no_cash_price_and_falls_back_to_the_hint(): void
    {
        // A resource-package account is billed in UNITS: there is no cash price to parse at all.
        $response = $this->imageTask(['final_unit_deduction' => ['quota' => 1, 'package_type' => 'image_pack']]);

        $cost = $this->imageClient()->parseCost($response, self::HINT_MICRO_USD);

        $this->assertTrue($cost->available);
        $this->assertSame(ParsedCost::SOURCE_ENDPOINT, $cost->source);
        $this->assertEqualsWithDelta(0.028, (float) $cost->costUsd, 0.000001);
    }

    public function test_no_cash_price_and_no_hint_fails_closed_and_can_never_charge(): void
    {
        // THE GUARD: nothing parsable, nothing configured -> unavailable. ParsedCost couples
        // available <-> non-null cost, so this can never reach the charge math as a $0 charge.
        $cost = $this->imageClient()->parseCost($this->imageTask(), null);

        $this->assertFalse($cost->available);
        $this->assertNull($cost->costUsd);
        $this->assertSame(ParsedCost::SOURCE_UNAVAILABLE, $cost->source);
    }

    /**
     * A hostile / malformed list_price is NOT a price: it must fall through to the hint, never
     * charge a negative, and never silently charge $0.
     */
    public function test_a_hostile_list_price_is_rejected_and_falls_through_to_the_hint(): void
    {
        $hostile = ['0', '-1', '-0.05', '', '   ', 'abc', 'NaN', 'INF', true, false, null, ['0.05']];

        foreach ($hostile as $value) {
            $this->assertNull(KlingCost::usd($value), var_export($value, true).' must not parse as a price.');

            $cost = $this->imageClient()->parseCost(
                $this->imageTask(['final_balance_deduction' => ['list_price' => $value]]),
                self::HINT_MICRO_USD,
            );

            // Falls back to the hint — never a zero/negative charge from a junk value.
            $this->assertSame(ParsedCost::SOURCE_ENDPOINT, $cost->source);
            $this->assertEqualsWithDelta(0.028, (float) $cost->costUsd, 0.000001);
        }

        $this->assertNull(KlingCost::usd(NAN));
        $this->assertNull(KlingCost::usd(INF));
        $this->assertNull(KlingCost::usd(-INF));
    }

    public function test_a_numeric_list_price_is_accepted_whether_string_or_number(): void
    {
        $this->assertEqualsWithDelta(0.028, (float) KlingCost::usd('0.028'), 0.000001);
        $this->assertEqualsWithDelta(0.028, (float) KlingCost::usd(' 0.028 '), 0.000001);
        $this->assertEqualsWithDelta(0.028, (float) KlingCost::usd(0.028), 0.000001);
        $this->assertEqualsWithDelta(1.0, (float) KlingCost::usd(1), 0.000001);
    }

    public function test_the_real_price_survives_the_full_submit_poll_dance(): void
    {
        Http::fake([
            self::QUERY_IMAGE => Http::response($this->imageTask([
                'final_balance_deduction' => ['quota' => 56, 'list_price' => self::LIST_PRICE],
            ]), 200),
            self::SUBMIT_IMAGE => Http::response([
                'code' => 0,
                'data' => ['task_id' => self::TASK, 'task_status' => 'submitted'],
            ], 200),
        ]);

        $client = $this->imageClient();
        $response = $client->callWithFallback('try_on_generation', self::IMAGE_MODEL, null, fn (string $m): array => [
            KlingImageClient::KEY_MODEL => $m,
            KlingImageClient::KEY_PROMPT => 'a red apple',
        ]);

        // The cost is read from the COMPLETED task envelope the poll returned, not the submit.
        $cost = $client->parseCost($response, self::HINT_MICRO_USD);
        $this->assertSame(ParsedCost::SOURCE_INLINE, $cost->source);
        $this->assertEqualsWithDelta(0.056, (float) $cost->costUsd, 0.000001);
    }

    // === VIDEO: billing[] ===

    /** @param array<int,array<string,mixed>> $billing */
    private function videoTask(array $billing): array
    {
        return [
            'code' => 0,
            'data' => [
                'task_id' => 'vid-1',
                'task_status' => 'succeed',
                'task_result' => ['videos' => [['url' => self::VIDEO_URL, 'duration' => '5']]],
                'billing' => $billing,
            ],
        ];
    }

    public function test_a_polled_clip_carries_the_summed_cash_billing_lines(): void
    {
        Http::fake([self::QUERY_VIDEO => Http::response($this->videoTask([
            ['charge_type' => 'cash', 'amount' => 70, 'list_price' => '0.35'],
            // A clip can bill a second cash line (e.g. audio) — the cost is the SUM.
            ['charge_type' => 'cash', 'amount' => 10, 'list_price' => '0.05'],
        ]), 200)]);

        $task = app(KlingVideoClient::class)->pollTask(self::VIDEO_TASK_ID);

        $this->assertSame('succeeded', $task['status']);
        $this->assertSame(400_000, $task['cost']['micro_usd']); // $0.35 + $0.05
    }

    public function test_a_unit_billed_clip_reports_no_cost_so_the_caller_uses_its_hint(): void
    {
        Http::fake([self::QUERY_VIDEO => Http::response($this->videoTask([
            ['charge_type' => 'unit', 'amount' => 1, 'package_type' => 'video_pack', 'list_price' => '0.35'],
        ]), 200)]);

        $task = app(KlingVideoClient::class)->pollTask(self::VIDEO_TASK_ID);

        // A unit (resource-package) line is not cash: no USD was spent, so no cost is reported.
        $this->assertSame('succeeded', $task['status']);
        $this->assertNull($task['cost']['micro_usd']);
    }

    public function test_a_clip_with_no_billing_block_reports_no_cost(): void
    {
        Http::fake([self::QUERY_VIDEO => Http::response([
            'code' => 0,
            'data' => [
                'task_id' => 'vid-1',
                'task_status' => 'succeed',
                'task_result' => ['videos' => [['url' => self::VIDEO_URL]]],
            ],
        ], 200)]);

        $task = app(KlingVideoClient::class)->pollTask(self::VIDEO_TASK_ID);

        $this->assertNull($task['cost']['micro_usd']);
    }
}
