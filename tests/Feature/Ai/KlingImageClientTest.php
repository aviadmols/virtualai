<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\KlingImageClient;
use App\Domain\Ai\OpenRouterException;
use App\Domain\Ai\ParsedCost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * KlingImageClient: the task dance (submit → poll → succeed) behind the SYNCHRONOUS provider
 * contract, the per-request HS256 JWT auth, the two endpoints (image generation vs the dedicated
 * kolors-virtual-try-on), input images inlined as RAW base64, classified errors (incl. an error
 * envelope served with HTTP 200), and flat-rate cost parsing. HTTP is faked throughout.
 */
class KlingImageClientTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api-singapore.klingai.com';

    private const IMAGE_MODEL = 'kling-v2-1';

    private const TRY_ON_MODEL = 'kolors-virtual-try-on-v1-5';

    private const SUBMIT_IMAGE = self::BASE.'/v1/images/generations';

    private const SUBMIT_TRY_ON = self::BASE.'/v1/images/kolors-virtual-try-on';

    private const TASK = 'task-abc';

    private const QUERY_IMAGE = self::SUBMIT_IMAGE.'/'.self::TASK;

    private const QUERY_TRY_ON = self::SUBMIT_TRY_ON.'/'.self::TASK;

    private const RESULT_URL = 'https://cdn.klingai.test/out.png';

    private const PERSON_URL = 'https://media.test/person.png';

    private const GARMENT_URL = 'https://media.test/garment.png';

    private const API_KEY = 'api-key-kling-test';

    private const ACCESS_KEY = 'ak-test';

    private const SECRET_KEY = 'sk-test';

    protected function setUp(): void
    {
        parent::setUp();
        // The legacy pair is the DEFAULT here (the JWT path); the static-key tests opt in.
        config()->set('services.kling.api_key', '');
        config()->set('services.kling.access_key', self::ACCESS_KEY);
        config()->set('services.kling.secret_key', self::SECRET_KEY);
        config()->set('services.kling.base_url', self::BASE);
        config()->set('services.kling.timeout', 30);
        Sleep::fake();
    }

    private function client(): KlingImageClient
    {
        return app(KlingImageClient::class);
    }

    /** A Kling envelope for a task in a given state. @param array<string,mixed> $extra */
    private function task(string $status, array $extra = []): array
    {
        return [
            'code' => 0,
            'message' => 'SUCCEED',
            'request_id' => 'req-1',
            'data' => ['task_id' => self::TASK, 'task_status' => $status] + $extra,
        ];
    }

    private function succeededTask(): array
    {
        return $this->task('succeed', [
            'task_result' => ['images' => [['index' => 0, 'url' => self::RESULT_URL]]],
        ]);
    }

    public function test_the_task_dance_returns_the_result_and_the_image_downloads(): void
    {
        $png = "\x89PNG\r\n\x1a\nOUT";

        Http::fake([
            self::QUERY_IMAGE => Http::sequence()
                ->push($this->task('processing'), 200)
                ->push($this->succeededTask(), 200),
            self::SUBMIT_IMAGE => Http::response($this->task('submitted'), 200),
            self::RESULT_URL => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $client = $this->client();
        $response = $client->callWithFallback('op', self::IMAGE_MODEL, null, fn (string $m): array => [
            KlingImageClient::KEY_MODEL => $m,
            KlingImageClient::KEY_PROMPT => 'a red apple',
        ]);

        $this->assertSame(self::TASK, $client->extractGenerationId($response));
        $this->assertSame(self::IMAGE_MODEL, $client->extractModelUsed($response, self::IMAGE_MODEL));

        [$bytes, $mime] = $client->extractImage($response);
        $this->assertSame($png, $bytes);
        $this->assertSame('image/png', $mime);

        // The submit carries a fresh HS256 JWT (iss = the access key) and Kling's own model_name.
        Http::assertSent(function ($req): bool {
            if ($req->url() !== self::SUBMIT_IMAGE) {
                return false;
            }

            $authorization = (string) $req->header('Authorization')[0];

            if (! str_starts_with($authorization, 'Bearer ')) {
                return false;
            }

            // Decode the JWT the client actually sent (its exp/nbf move with the clock, so the
            // CLAIMS are the contract, not a byte-for-byte token match).
            $claims = json_decode(base64_decode(strtr(
                explode('.', substr($authorization, 7))[1],
                '-_',
                '+/',
            )), true);

            return $req->data()['model_name'] === self::IMAGE_MODEL
                && $req->data()['prompt'] === 'a red apple'
                && ! isset($req->data()['model'])
                && $claims['iss'] === self::ACCESS_KEY
                && $claims['exp'] > time();
        });
    }

    public function test_a_try_on_model_routes_to_the_kolors_endpoint_with_human_and_cloth_images(): void
    {
        Http::fake([
            self::QUERY_TRY_ON => Http::response($this->succeededTask(), 200),
            self::SUBMIT_TRY_ON => Http::response($this->task('submitted'), 200),
            self::PERSON_URL => Http::response('PERSONBYTES', 200, ['Content-Type' => 'image/png']),
            self::GARMENT_URL => Http::response('GARMENTBYTES', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->client()->callWithFallback('try_on_generation', self::TRY_ON_MODEL, null, fn (string $m): array => [
            KlingImageClient::KEY_MODEL => $m,
            KlingImageClient::KEY_PROMPT => 'ignored by the try-on endpoint',
            KlingImageClient::KEY_IMAGE_URLS => [self::PERSON_URL, self::GARMENT_URL],
        ]);

        Http::assertSent(function ($req): bool {
            if ($req->url() !== self::SUBMIT_TRY_ON) {
                return false;
            }

            $data = $req->data();

            return $data['model_name'] === self::TRY_ON_MODEL
                // ORDER IS THE CONTRACT: person -> human_image, garment -> cloth_image.
                && $data['human_image'] === base64_encode('PERSONBYTES')
                && $data['cloth_image'] === base64_encode('GARMENTBYTES')
                // RAW base64 — Kling takes no data: prefix.
                && ! str_contains($data['human_image'], 'data:')
                // The try-on endpoint takes no prompt.
                && ! isset($data['prompt']);
        });
    }

    public function test_a_try_on_call_without_both_images_fails_before_any_spend(): void
    {
        Http::fake([self::PERSON_URL => Http::response('PERSONBYTES', 200)]);

        try {
            $this->client()->callWithFallback('try_on_generation', self::TRY_ON_MODEL, null, fn (string $m): array => [
                KlingImageClient::KEY_MODEL => $m,
                KlingImageClient::KEY_IMAGE_URLS => [self::PERSON_URL], // no garment
            ]);
            $this->fail('Expected an OpenRouterException.');
        } catch (OpenRouterException $e) {
            $this->assertSame(OpenRouterException::CODE_BAD_REQUEST, $e->errorCode);
        }

        Http::assertNotSent(fn ($req) => $req->url() === self::SUBMIT_TRY_ON);
    }

    public function test_an_image_generation_input_image_becomes_the_raw_base64_reference(): void
    {
        Http::fake([
            self::QUERY_IMAGE => Http::response($this->succeededTask(), 200),
            self::SUBMIT_IMAGE => Http::response($this->task('submitted'), 200),
            self::PERSON_URL => Http::response('REFBYTES', 200, ['Content-Type' => 'image/png']),
        ]);

        $this->client()->callWithFallback('op', self::IMAGE_MODEL, null, fn (string $m): array => [
            KlingImageClient::KEY_MODEL => $m,
            KlingImageClient::KEY_PROMPT => 'restyle this',
            KlingImageClient::KEY_IMAGE_URLS => [self::PERSON_URL],
            // A native Kling knob set on the operation rides through verbatim.
            'image_fidelity' => 0.7,
        ]);

        Http::assertSent(fn ($req) => $req->url() === self::SUBMIT_IMAGE
            && $req->data()['image'] === base64_encode('REFBYTES')
            && $req->data()['image_fidelity'] === 0.7);
    }

    public function test_a_failed_task_is_a_classified_error_carrying_klings_reason(): void
    {
        Http::fake([
            self::QUERY_IMAGE => Http::response($this->task('failed', ['task_status_msg' => 'content policy']), 200),
            self::SUBMIT_IMAGE => Http::response($this->task('submitted'), 200),
        ]);

        try {
            $this->client()->callWithFallback('op', self::IMAGE_MODEL, null, fn (string $m): array => [
                KlingImageClient::KEY_MODEL => $m,
                KlingImageClient::KEY_PROMPT => 'x',
            ]);
            $this->fail('Expected an OpenRouterException.');
        } catch (OpenRouterException $e) {
            $this->assertSame(OpenRouterException::CODE_MODEL_REFUSED, $e->errorCode);
            $this->assertStringContainsString('content policy', $e->getMessage());
        }
    }

    public function test_an_error_envelope_served_with_http_200_is_still_an_error(): void
    {
        // Kling can answer 200 with a non-zero envelope code — trusting the HTTP status alone
        // would treat that as a task and poll forever.
        Http::fake([
            self::SUBMIT_IMAGE => Http::response(['code' => 1103, 'message' => 'account arrears'], 200),
        ]);

        $this->expectException(OpenRouterException::class);

        $this->client()->callWithFallback('op', self::IMAGE_MODEL, null, fn (string $m): array => [
            KlingImageClient::KEY_MODEL => $m,
            KlingImageClient::KEY_PROMPT => 'x',
        ]);
    }

    public function test_http_errors_are_classified_for_the_money_path(): void
    {
        Http::fake([self::SUBMIT_IMAGE => Http::response(['code' => 1004, 'message' => 'rate limited'], 429)]);

        try {
            $this->client()->callWithFallback('op', self::IMAGE_MODEL, null, fn (string $m): array => [
                KlingImageClient::KEY_MODEL => $m,
                KlingImageClient::KEY_PROMPT => 'x',
            ]);
            $this->fail('Expected an OpenRouterException.');
        } catch (OpenRouterException $e) {
            $this->assertSame(OpenRouterException::CODE_RATE_LIMITED, $e->errorCode);
            $this->assertTrue($e->isTransient());
        }
    }

    public function test_cost_is_klings_own_price_then_the_hint_then_honestly_unavailable(): void
    {
        // The full ladder lives in KlingCostParsingTest; this pins the client's contract.
        $client = $this->client();

        // 1. The price Kling BILLED is the charge — the hint is only the estimate.
        $billed = $client->parseCost(
            $this->task('succeed', ['final_balance_deduction' => ['list_price' => '0.056']]),
            42_000,
        );
        $this->assertTrue($billed->available);
        $this->assertEqualsWithDelta(0.056, (float) $billed->costUsd, 0.000001);

        // 2. No cash price on the response (a unit/resource-package account) -> the admin price.
        $priced = $client->parseCost($this->succeededTask(), 42_000);
        $this->assertTrue($priced->available);
        $this->assertEqualsWithDelta(0.042, (float) $priced->costUsd, 0.000001);

        // 3. Neither -> honestly unavailable (the money path never charges a guess, nor a $0).
        $unpriced = $client->parseCost($this->succeededTask(), null);
        $this->assertInstanceOf(ParsedCost::class, $unpriced);
        $this->assertFalse($unpriced->available);
    }

    public function test_a_static_api_key_is_sent_verbatim_and_beats_the_legacy_pair(): void
    {
        // Today's console issues ONE static key — it is the bearer as-is, never a JWT input.
        config()->set('services.kling.api_key', self::API_KEY);

        Http::fake([
            self::QUERY_IMAGE => Http::response($this->succeededTask(), 200),
            self::SUBMIT_IMAGE => Http::response($this->task('submitted'), 200),
            self::RESULT_URL => Http::response('PNG', 200, ['Content-Type' => 'image/png']),
        ]);

        $this->client()->callWithFallback('op', self::IMAGE_MODEL, null, fn (string $m): array => [
            KlingImageClient::KEY_MODEL => $m,
            KlingImageClient::KEY_PROMPT => 'a red apple',
        ]);

        Http::assertSent(fn ($req): bool => $req->url() !== self::SUBMIT_IMAGE
            || (string) $req->header('Authorization')[0] === 'Bearer '.self::API_KEY);
    }

    public function test_the_api_key_alone_is_a_complete_credential(): void
    {
        // No pair at all: the API key on its own must authenticate (it is not half a credential).
        config()->set('services.kling.api_key', self::API_KEY);
        config()->set('services.kling.access_key', '');
        config()->set('services.kling.secret_key', '');

        Http::fake([self::BASE.'/v1/images/generations/*' => Http::response(['code' => 1201], 400)]);

        $this->assertTrue($this->client()->checkConnection()['ok']);
    }

    public function test_a_half_configured_key_pair_is_reported_without_any_request(): void
    {
        // Kling needs BOTH keys — a missing secret is stated plainly, not surfaced as a 401.
        config()->set('services.kling.secret_key', '');
        Http::fake();

        $result = $this->client()->checkConnection();

        $this->assertFalse($result['ok']);
        $this->assertSame('not_configured', $result['reason']);
        Http::assertNothingSent();
    }

    public function test_the_connection_probe_classifies_a_rejected_key_pair(): void
    {
        Http::fake([self::BASE.'/v1/images/generations/*' => Http::response(['code' => 1004], 401)]);

        $this->assertSame('invalid_key', $this->client()->checkConnection()['reason']);
    }

    public function test_the_connection_probe_accepts_any_authenticated_answer_and_never_spends(): void
    {
        // The probe GETs an impossible task id: a good key answers "task not found" (4xx), a bad
        // one answers 401. Either way nothing is generated.
        Http::fake([self::BASE.'/v1/images/generations/*' => Http::response(['code' => 1200, 'message' => 'task not found'], 404)]);

        $this->assertTrue($this->client()->checkConnection()['ok']);

        Http::assertSent(fn ($req) => $req->method() === 'GET');
        Http::assertNotSent(fn ($req) => $req->method() === 'POST');
    }

    public function test_a_model_probe_is_honest_that_kling_publishes_no_catalog(): void
    {
        Http::fake([self::BASE.'/v1/images/generations/*' => Http::response(['code' => 1200], 404)]);

        $result = $this->client()->checkModel(self::IMAGE_MODEL);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('no model catalog', $result['message']);
    }
}
