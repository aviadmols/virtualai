<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\AiOperationResolver;
use App\Models\Account;
use App\Models\AiOperation;
use App\Models\Prompt;
use App\Models\Site;
use App\Support\Tenant;
use Database\Seeders\AiControlPlaneSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * AiOperationResolver — the ONLY source of AI config. Proves the resolved bag
 * shape, the prompt override precedence (site > account > product_type > global),
 * the per-site model override, the missing-global loud failure, and that the
 * account/site prompt legs are tenant-isolated (no cross-account read).
 */
class AiOperationResolverTest extends TestCase
{
    use RefreshDatabase;

    private AiOperationResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AiControlPlaneSeeder::class);
        $this->resolver = new AiOperationResolver;
    }

    public function test_resolves_global_bag_with_operation_defaults(): void
    {
        $bag = $this->resolver->for(AiOperation::KEY_TRY_ON_GENERATION);

        $this->assertSame(AiOperation::KEY_TRY_ON_GENERATION, $bag->operationKey);
        $this->assertSame('google/gemini-3.1-flash-image', $bag->model);
        $this->assertSame('google/gemini-2.5-flash-image', $bag->fallbackModel);
        $this->assertSame('high', $bag->imageQuality);
        $this->assertSame('3:4', $bag->aspectRatio);
        $this->assertSame(1234, $bag->params['seed']);          // determinism from the bag
        $this->assertNull($bag->creditMultiplier);
        $this->assertSame(1, $bag->promptVersion);
        $this->assertNotEmpty($bag->userPrompt);
    }

    public function test_per_site_model_override_wins_when_in_allow_list(): void
    {
        // The fallback model is already catalogued (allow-listed) for this
        // operation; a site may legitimately point its default at it.
        $account = Account::factory()->create();
        $site = Tenant::run($account, fn () => Site::create([
            'name' => 'Override',
            'ai_model' => 'google/gemini-2.5-flash-image',
        ]));

        $bag = $this->resolver->for(AiOperation::KEY_TRY_ON_GENERATION, $site);

        // The site override (the catalogued fallback) wins over the operation default (3.1).
        $this->assertSame('google/gemini-2.5-flash-image', $bag->model);
    }

    public function test_site_model_override_outside_allow_list_falls_back_to_operation_default(): void
    {
        $account = Account::factory()->create();
        $site = Tenant::run($account, fn () => Site::create([
            'name' => 'Bad override',
            'ai_model' => 'evil/unlisted-model',
        ]));

        $bag = $this->resolver->for(AiOperation::KEY_TRY_ON_GENERATION, $site);

        // The unlisted model is ignored; the operation default is used.
        $this->assertSame('google/gemini-3.1-flash-image', $bag->model);
    }

    public function test_prompt_precedence_site_over_account_over_product_type_over_global(): void
    {
        $account = Account::factory()->create();
        $site = Tenant::run($account, fn () => Site::create(['name' => 'S']));
        $op = AiOperation::KEY_PRODUCT_SCAN;

        // Global already seeded. Add product_type, account, then site — least to most specific.
        Prompt::create(['scope' => Prompt::SCOPE_PRODUCT_TYPE, 'operation_key' => $op, 'product_type' => 'shoes', 'user_prompt' => 'PT prompt']);
        $this->assertSame('PT prompt', $this->resolver->for($op, $site, 'shoes')->userPrompt);

        Prompt::create(['scope' => Prompt::SCOPE_ACCOUNT, 'operation_key' => $op, 'account_id' => $account->id, 'user_prompt' => 'ACCT prompt']);
        $this->assertSame('ACCT prompt', $this->resolver->for($op, $site, 'shoes')->userPrompt);

        Prompt::create(['scope' => Prompt::SCOPE_SITE, 'operation_key' => $op, 'account_id' => $account->id, 'site_id' => $site->id, 'user_prompt' => 'SITE prompt']);
        $this->assertSame('SITE prompt', $this->resolver->for($op, $site, 'shoes')->userPrompt);
    }

    public function test_falls_through_to_global_when_no_specific_prompt(): void
    {
        $account = Account::factory()->create();
        $site = Tenant::run($account, fn () => Site::create(['name' => 'S']));

        $bag = $this->resolver->for(AiOperation::KEY_PRODUCT_SCAN, $site, 'unknown_type');

        // The seeded global prompt is the floor.
        $this->assertStringContainsString('{{product_name}}', $bag->userPrompt);
    }

    public function test_missing_global_prompt_fails_loud(): void
    {
        // Remove the global floor for one operation.
        Prompt::query()
            ->where('scope', Prompt::SCOPE_GLOBAL)
            ->where('operation_key', AiOperation::KEY_PRODUCT_SCAN)
            ->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/global prompt is the guaranteed floor/');

        $this->resolver->for(AiOperation::KEY_PRODUCT_SCAN);
    }

    public function test_unknown_operation_fails_loud(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown AI operation/');

        $this->resolver->for('not_a_real_operation');
    }

    public function test_account_site_prompt_is_not_resolved_for_another_account(): void
    {
        $op = AiOperation::KEY_PRODUCT_SCAN;

        $accountA = Account::factory()->create();
        $siteA = Tenant::run($accountA, fn () => Site::create(['name' => 'A']));

        $accountB = Account::factory()->create();
        $siteB = Tenant::run($accountB, fn () => Site::create(['name' => 'B']));

        // Account A owns a site-scoped prompt.
        Prompt::create([
            'scope' => Prompt::SCOPE_SITE,
            'operation_key' => $op,
            'account_id' => $accountA->id,
            'site_id' => $siteA->id,
            'user_prompt' => 'A-ONLY prompt',
        ]);

        // Resolving for A's site gets A's prompt.
        $this->assertSame('A-ONLY prompt', $this->resolver->for($op, $siteA)->userPrompt);

        // Resolving for B's site must NOT see A's prompt — it falls through to global.
        $bagB = $this->resolver->for($op, $siteB);
        $this->assertNotSame('A-ONLY prompt', $bagB->userPrompt);
        $this->assertStringContainsString('{{product_name}}', $bagB->userPrompt);
    }

    public function test_account_scoped_prompt_does_not_leak_across_accounts(): void
    {
        $op = AiOperation::KEY_PRODUCT_SCAN;

        $accountA = Account::factory()->create();
        $siteA = Tenant::run($accountA, fn () => Site::create(['name' => 'A']));
        $accountB = Account::factory()->create();
        $siteB = Tenant::run($accountB, fn () => Site::create(['name' => 'B']));

        Prompt::create([
            'scope' => Prompt::SCOPE_ACCOUNT,
            'operation_key' => $op,
            'account_id' => $accountA->id,
            'user_prompt' => 'A-ACCOUNT prompt',
        ]);

        $this->assertSame('A-ACCOUNT prompt', $this->resolver->for($op, $siteA)->userPrompt);
        $this->assertNotSame('A-ACCOUNT prompt', $this->resolver->for($op, $siteB)->userPrompt);
    }

    // === S1b: deterministic in-leg selection ===

    public function test_two_same_leg_prompts_resolve_deterministically(): void
    {
        // Two competing global rows for the SAME operation but different versions.
        // (Inserted raw to bypass the unique constraint, simulating pre-constraint
        // or differing-version data — the resolver must still be unambiguous.)
        $op = 'try_on_generation';
        Prompt::query()->where('scope', Prompt::SCOPE_GLOBAL)->where('operation_key', $op)->delete();

        Prompt::create(['scope' => Prompt::SCOPE_GLOBAL, 'operation_key' => $op, 'user_prompt' => 'OLD v1', 'version' => 1]);
        Prompt::create(['scope' => Prompt::SCOPE_GLOBAL, 'operation_key' => $op, 'user_prompt' => 'NEW v2', 'version' => 2]);

        // Highest version wins, every time.
        $this->assertSame('NEW v2', $this->resolver->for($op)->userPrompt);
        $this->assertSame('NEW v2', $this->resolver->for($op)->userPrompt);
    }

    // === S1a: the unique constraint forbids two competing rows on a leg ===

    public function test_unique_constraint_rejects_duplicate_global_leg_at_same_version(): void
    {
        $op = AiOperation::KEY_PRODUCT_SCAN; // global v1 already seeded.

        $this->expectException(QueryException::class);

        // A second active global row at the same version on the same leg is rejected
        // by the COALESCE-normalized unique index (NULL product_type/account/site
        // collide, not stay distinct).
        DB::table('prompts')->insert([
            'scope' => Prompt::SCOPE_GLOBAL,
            'operation_key' => $op,
            'user_prompt' => 'duplicate',
            'version' => 1,
            'is_active' => true,
        ]);
    }

    // === S2: a dropped per-site model override is warned, not silent ===

    public function test_dropped_site_model_override_logs_a_warning(): void
    {
        Log::spy();

        $account = Account::factory()->create();
        $site = Tenant::run($account, fn () => Site::create([
            'name' => 'Bad override',
            'ai_model' => 'evil/unlisted-model',
        ]));

        $bag = $this->resolver->for(AiOperation::KEY_TRY_ON_GENERATION, $site);

        // The override was dropped (operation default used) AND the admin is warned.
        $this->assertSame('google/gemini-3.1-flash-image', $bag->model);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) {
                return $message === 'ai.resolver.site_model_override_ignored'
                    && ($context['requested_model'] ?? null) === 'evil/unlisted-model';
            })
            ->once();
    }
}
