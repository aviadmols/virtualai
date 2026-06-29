<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Ai\Preview\OperationPreview;
use App\Domain\Ai\Preview\ResolutionStep;
use App\Models\Account;
use App\Models\AiOperation;
use App\Models\Prompt;
use App\Models\Site;
use App\Support\Tenant;
use Database\Seeders\AiControlPlaneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AiOperationResolver::preview() — the read-only "which prompt + which model wins,
 * and why" capability for the Phase-8c prompts editor. Proves:
 *  - the preview reports the SAME winner for() does at EVERY precedence level
 *    (it reuses the shared core, never a forked copy);
 *  - the resolution trace names the level that supplied the winner and marks the
 *    legs considered / not-reached / skipped faithfully;
 *  - it makes NO OpenRouter HTTP call and writes NOTHING;
 *  - the safe strtr substitution helper renders {{placeholders}} literally and
 *    never evaluates Blade/PHP (RCE-safe).
 */
class AiOperationResolverPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const TRYON = AiOperation::KEY_TRY_ON_GENERATION;
    private const SCAN = AiOperation::KEY_PRODUCT_SCAN;

    private AiOperationResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AiControlPlaneSeeder::class);
        // Any stray OpenRouter call during a "preview" would be a contract breach.
        Http::preventStrayRequests();
        Http::fake();
        $this->resolver = new AiOperationResolver;
    }

    // === winner parity: preview() must match for() at every level ===

    public function test_preview_global_winner_matches_for(): void
    {
        $bag = $this->resolver->for(self::TRYON);
        $preview = $this->resolver->preview(self::TRYON);

        $this->assertSame($bag->model, $preview->winningModel);
        $this->assertSame($bag->fallbackModel, $preview->fallbackModel);
        $this->assertSame($bag->userPrompt, $preview->winningUserPrompt);
        $this->assertSame($bag->systemPrompt, $preview->winningSystemPrompt);
        $this->assertSame($bag->promptVersion, $preview->winningPromptVersion);
        $this->assertSame(Prompt::SCOPE_GLOBAL, $preview->winningPromptLevel);
        $this->assertSame(Prompt::SCOPE_GLOBAL, $preview->promptTrace->winningLevel());
    }

    public function test_preview_winner_matches_for_at_every_prompt_precedence_level(): void
    {
        $account = Account::factory()->create();
        $site = Tenant::run($account, fn () => Site::create(['name' => 'S']));

        // product_type leg.
        Prompt::create(['scope' => Prompt::SCOPE_PRODUCT_TYPE, 'operation_key' => self::SCAN, 'product_type' => 'shoes', 'user_prompt' => 'PT prompt']);
        $this->assertWinnerParity(self::SCAN, $site, 'shoes', Prompt::SCOPE_PRODUCT_TYPE, 'PT prompt');

        // account leg now outranks product_type.
        Prompt::create(['scope' => Prompt::SCOPE_ACCOUNT, 'operation_key' => self::SCAN, 'account_id' => $account->id, 'user_prompt' => 'ACCT prompt']);
        $this->assertWinnerParity(self::SCAN, $site, 'shoes', Prompt::SCOPE_ACCOUNT, 'ACCT prompt');

        // site leg now outranks account.
        Prompt::create(['scope' => Prompt::SCOPE_SITE, 'operation_key' => self::SCAN, 'account_id' => $account->id, 'site_id' => $site->id, 'user_prompt' => 'SITE prompt']);
        $this->assertWinnerParity(self::SCAN, $site, 'shoes', Prompt::SCOPE_SITE, 'SITE prompt');
    }

    public function test_preview_falls_through_to_global_when_no_specific_prompt(): void
    {
        $account = Account::factory()->create();
        $site = Tenant::run($account, fn () => Site::create(['name' => 'S']));

        $preview = $this->resolver->preview(self::SCAN, $site, 'unknown_type');

        $this->assertSame(Prompt::SCOPE_GLOBAL, $preview->winningPromptLevel);
        $this->assertSame($this->resolver->for(self::SCAN, $site, 'unknown_type')->userPrompt, $preview->winningUserPrompt);
    }

    // === the resolution trace explains "why" ===

    public function test_prompt_trace_marks_won_not_reached_and_no_match_faithfully(): void
    {
        $account = Account::factory()->create();
        $site = Tenant::run($account, fn () => Site::create(['name' => 'S']));
        Prompt::create(['scope' => Prompt::SCOPE_ACCOUNT, 'operation_key' => self::SCAN, 'account_id' => $account->id, 'user_prompt' => 'ACCT prompt']);

        $preview = $this->resolver->preview(self::SCAN, $site, 'shoes');
        $steps = $this->indexByLevel($preview->promptTrace->steps);

        // site has no row -> NO_MATCH; account wins; product_type + global never reached.
        $this->assertSame(ResolutionStep::OUTCOME_NO_MATCH, $steps[Prompt::SCOPE_SITE]->outcome);
        $this->assertSame(ResolutionStep::OUTCOME_WON, $steps[Prompt::SCOPE_ACCOUNT]->outcome);
        $this->assertSame(ResolutionStep::OUTCOME_NOT_REACHED, $steps[Prompt::SCOPE_PRODUCT_TYPE]->outcome);
        $this->assertSame(ResolutionStep::OUTCOME_NOT_REACHED, $steps[Prompt::SCOPE_GLOBAL]->outcome);
        $this->assertSame(Prompt::SCOPE_ACCOUNT, $preview->promptTrace->winningLevel());
    }

    public function test_prompt_trace_skips_site_and_account_legs_when_no_site(): void
    {
        $preview = $this->resolver->preview(self::SCAN, null, null);
        $steps = $this->indexByLevel($preview->promptTrace->steps);

        $this->assertSame(ResolutionStep::OUTCOME_SKIPPED, $steps[Prompt::SCOPE_SITE]->outcome);
        $this->assertSame(ResolutionStep::OUTCOME_SKIPPED, $steps[Prompt::SCOPE_ACCOUNT]->outcome);
        $this->assertSame(ResolutionStep::OUTCOME_SKIPPED, $steps[Prompt::SCOPE_PRODUCT_TYPE]->outcome);
        $this->assertSame(ResolutionStep::OUTCOME_WON, $steps[Prompt::SCOPE_GLOBAL]->outcome);
    }

    public function test_model_trace_and_chain_report_the_operation_default_and_fallback(): void
    {
        $preview = $this->resolver->preview(self::TRYON);

        $this->assertSame('google/gemini-2.5-flash-image-preview', $preview->winningModel);
        $this->assertSame('openai/gpt-image-1', $preview->fallbackModel);
        // Chain = [winner, fallback] in the order the client would try them.
        $this->assertSame(['google/gemini-2.5-flash-image-preview', 'openai/gpt-image-1'], $preview->modelChain);
        $this->assertSame('operation_default', $preview->modelTrace->winningLevel());
    }

    public function test_model_trace_shows_a_dropped_unlisted_site_override(): void
    {
        $account = Account::factory()->create();
        $site = Tenant::run($account, fn () => Site::create([
            'name' => 'Bad override',
            'ai_model' => 'evil/unlisted-model',
        ]));

        $preview = $this->resolver->preview(self::TRYON, $site);
        $steps = $this->indexByLevel($preview->modelTrace->steps);

        // The unlisted override is NO_MATCH; the operation default wins — same as for().
        $this->assertSame(ResolutionStep::OUTCOME_NO_MATCH, $steps['site_override']->outcome);
        $this->assertSame('operation_default', $preview->modelTrace->winningLevel());
        $this->assertSame($this->resolver->for(self::TRYON, $site)->model, $preview->winningModel);
    }

    public function test_preview_honoured_in_list_site_override_wins_in_both_paths(): void
    {
        $account = Account::factory()->create();
        $site = Tenant::run($account, fn () => Site::create([
            'name' => 'Override',
            'ai_model' => 'openai/gpt-image-1', // allow-listed fallback model
        ]));

        $preview = $this->resolver->preview(self::TRYON, $site);

        $this->assertSame('openai/gpt-image-1', $preview->winningModel);
        $this->assertSame('site_override', $preview->modelTrace->winningLevel());
        $this->assertSame($this->resolver->for(self::TRYON, $site)->model, $preview->winningModel);
    }

    // === read-only: no HTTP, no writes ===

    public function test_preview_makes_no_openrouter_http_call(): void
    {
        $account = Account::factory()->create();
        $site = Tenant::run($account, fn () => Site::create(['name' => 'S']));

        $this->resolver->preview(self::TRYON, $site, 'shoes');
        $this->resolver->preview(self::SCAN, $site, 'shoes');

        Http::assertNothingSent();
    }

    public function test_preview_performs_no_database_writes(): void
    {
        $account = Account::factory()->create();
        $site = Tenant::run($account, fn () => Site::create(['name' => 'S']));

        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            $sql = strtolower(ltrim($query->sql));
            if (str_starts_with($sql, 'insert') || str_starts_with($sql, 'update') || str_starts_with($sql, 'delete')) {
                $writes[] = $query->sql;
            }
        });

        $this->resolver->preview(self::TRYON, $site, 'shoes');
        $this->resolver->preview(self::SCAN);

        $this->assertSame([], $writes, 'preview() must only SELECT; it wrote: '.implode(' | ', $writes));
    }

    // === safe strtr substitution (RCE-safe), never Blade ===

    public function test_render_user_prompt_substitutes_placeholders_with_strtr(): void
    {
        $preview = $this->resolver->preview(self::TRYON);

        $rendered = $preview->renderUserPrompt([
            'product_name' => 'Blue Tee',
            'variant' => 'M',
            'height' => 170,
        ]);

        $this->assertStringContainsString('Blue Tee', $rendered);
        $this->assertStringContainsString('170', $rendered);
        $this->assertStringNotContainsString('{{product_name}}', $rendered);
    }

    public function test_render_treats_blade_and_php_in_values_as_literal_text(): void
    {
        // A malicious sample value must NEVER be evaluated — strtr does a literal swap.
        $account = Account::factory()->create();
        $site = Tenant::run($account, fn () => Site::create(['name' => 'S']));
        Prompt::create([
            'scope' => Prompt::SCOPE_SITE,
            'operation_key' => self::SCAN,
            'account_id' => $account->id,
            'site_id' => $site->id,
            'user_prompt' => 'Product: {{product_name}}',
        ]);

        $preview = $this->resolver->preview(self::SCAN, $site, null);
        $rendered = $preview->renderUserPrompt(['product_name' => '{{ 7 * 7 }} @php echo "x"; @endphp']);

        // The dangerous string appears VERBATIM, never executed (no "49", no "x").
        $this->assertSame('Product: {{ 7 * 7 }} @php echo "x"; @endphp', $rendered);
        $this->assertStringNotContainsString('49', $rendered);
    }

    public function test_preview_exposes_raw_template_unsubstituted(): void
    {
        // The method exposes the RAW resolved template; substitution is opt-in via
        // the safe helper. The UI escapes at its render boundary.
        $preview = $this->resolver->preview(self::TRYON);

        $this->assertStringContainsString('{{product_name}}', $preview->winningUserPrompt);
        $this->assertInstanceOf(OperationPreview::class, $preview);
    }

    public function test_preview_to_array_is_a_stable_serializable_contract(): void
    {
        $account = Account::factory()->create();
        $site = Tenant::run($account, fn () => Site::create(['name' => 'S']));

        $array = $this->resolver->preview(self::TRYON, $site, 'shoes')->toArray();

        $this->assertSame(self::TRYON, $array['operation_key']);
        $this->assertArrayHasKey('chain', $array['model']);
        $this->assertArrayHasKey('trace', $array['model']);
        $this->assertArrayHasKey('level', $array['prompt']);
        $this->assertArrayHasKey('trace', $array['prompt']);
        $this->assertSame($site->id, $array['site_id']);
        $this->assertSame($account->id, $array['account_id']);
    }

    // === helpers ===

    private function assertWinnerParity(string $op, Site $site, ?string $productType, string $expectedLevel, string $expectedPrompt): void
    {
        $bag = $this->resolver->for($op, $site, $productType);
        $preview = $this->resolver->preview($op, $site, $productType);

        $this->assertSame($expectedPrompt, $bag->userPrompt);
        $this->assertSame($bag->userPrompt, $preview->winningUserPrompt, 'preview prompt must equal for() prompt');
        $this->assertSame($bag->model, $preview->winningModel, 'preview model must equal for() model');
        $this->assertSame($expectedLevel, $preview->winningPromptLevel);
        $this->assertSame($expectedLevel, $preview->promptTrace->winningLevel());
    }

    /**
     * @param  list<ResolutionStep>  $steps
     * @return array<string,ResolutionStep>
     */
    private function indexByLevel(array $steps): array
    {
        $byLevel = [];
        foreach ($steps as $step) {
            $byLevel[$step->level] = $step;
        }

        return $byLevel;
    }
}
