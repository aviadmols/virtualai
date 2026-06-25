<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Ai\AiOperationResolver;
use App\Models\Account;
use App\Models\AiOperation;
use App\Models\Prompt;
use App\Models\Site;
use App\Support\Tenant;
use Database\Seeders\AiControlPlaneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial tenant-isolation spot-check for the Prompt model (TS-OPENROUTER-002).
 *
 * Prompt is the one allow-list-exempt model whose tenant isolation is enforced by
 * explicit resolver query scopes (NOT the BelongsToAccount global scope). This
 * suite is deliberately hostile: it tries to make account A receive account B's
 * tenant prompt by making B's row "win" on every axis the resolver might be tempted
 * to order on (specificity, recency, version), and tries to make a tenant row
 * masquerade as a global one. None of these may succeed.
 */
class PromptResolutionIsolationTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const OP = AiOperation::KEY_PRODUCT_SCAN;
    private const SEEDED_GLOBAL_NEEDLE = '{{product_name}}'; // the seeded global floor's text

    private AiOperationResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AiControlPlaneSeeder::class);
        $this->resolver = new AiOperationResolver;
    }

    /**
     * POINT 1 — B's site prompt is MORE specific, NEWER, and HIGHER version than
     * A's account prompt. Resolving for A's site must STILL never return B's row.
     */
    public function test_more_specific_newer_higher_version_prompt_of_another_account_never_resolves_for_a(): void
    {
        $accountA = Account::factory()->create();
        $siteA = Tenant::run($accountA, fn () => Site::create(['name' => 'A']));

        $accountB = Account::factory()->create();
        $siteB = Tenant::run($accountB, fn () => Site::create(['name' => 'B']));

        // A has only an account-scoped prompt (less specific, older, version 1).
        Prompt::create([
            'scope' => Prompt::SCOPE_ACCOUNT,
            'operation_key' => self::OP,
            'account_id' => $accountA->id,
            'user_prompt' => 'A-ACCOUNT',
            'version' => 1,
        ]);

        // B owns a SITE-scoped prompt: most specific, highest version. Created last
        // (newest). If the resolver leaked on specificity/recency/version it would
        // win — it must not, because it belongs to B.
        Prompt::create([
            'scope' => Prompt::SCOPE_SITE,
            'operation_key' => self::OP,
            'account_id' => $accountB->id,
            'site_id' => $siteB->id,
            'user_prompt' => 'B-SITE-WINS-EVERYTHING',
            'version' => 99,
        ]);

        $bagA = $this->resolver->for(self::OP, $siteA);

        $this->assertSame('A-ACCOUNT', $bagA->userPrompt, 'A must resolve its own account prompt.');
        $this->assertNotSame('B-SITE-WINS-EVERYTHING', $bagA->userPrompt, 'B\'s prompt must never reach A.');

        // And B resolves its own, never A's.
        $this->assertSame('B-SITE-WINS-EVERYTHING', $this->resolver->for(self::OP, $siteB)->userPrompt);
    }

    /**
     * POINT 1 (drain to floor) — when A has NO tenant prompt at all and B has the
     * only specific prompt, A must fall through to the GLOBAL floor, never to B.
     */
    public function test_account_with_no_prompt_falls_to_global_not_to_another_accounts_prompt(): void
    {
        $accountA = Account::factory()->create();
        $siteA = Tenant::run($accountA, fn () => Site::create(['name' => 'A']));

        $accountB = Account::factory()->create();
        $siteB = Tenant::run($accountB, fn () => Site::create(['name' => 'B']));

        Prompt::create([
            'scope' => Prompt::SCOPE_SITE,
            'operation_key' => self::OP,
            'account_id' => $accountB->id,
            'site_id' => $siteB->id,
            'user_prompt' => 'B-ONLY',
            'version' => 50,
        ]);

        $bagA = $this->resolver->for(self::OP, $siteA);

        $this->assertNotSame('B-ONLY', $bagA->userPrompt);
        $this->assertStringContainsString(self::SEEDED_GLOBAL_NEEDLE, $bagA->userPrompt);
    }

    /**
     * POINT 2 — the global / product_type legs strictly whereNull('account_id'):
     * a tenant row whose scope was (mis)set to 'global'/'product_type' but with a
     * NON-NULL account_id must NEVER be picked up by the global/product_type legs.
     * This is the "tenant masquerading as global" probe.
     */
    public function test_global_leg_ignores_a_tenant_row_masquerading_with_a_global_scope(): void
    {
        $accountB = Account::factory()->create();

        // A poisoned row: scope says 'global' but it carries a tenant account_id.
        // The global leg's whereNull('account_id') must exclude it.
        Prompt::create([
            'scope' => Prompt::SCOPE_GLOBAL,
            'operation_key' => self::OP,
            'account_id' => $accountB->id, // NON-NULL: this is the poison.
            'user_prompt' => 'POISONED-GLOBAL-OF-B',
            'version' => 100,
        ]);

        // Resolve with no site/productType — the global leg only.
        $bag = $this->resolver->for(self::OP);

        $this->assertNotSame('POISONED-GLOBAL-OF-B', $bag->userPrompt, 'Tenant row must not masquerade as the global floor.');
        $this->assertStringContainsString(self::SEEDED_GLOBAL_NEEDLE, $bag->userPrompt);

        // Direct scope probe: the global scope must return zero of the poisoned row.
        $this->assertSame(0, Prompt::query()->globalScoped(self::OP)
            ->where('user_prompt', 'POISONED-GLOBAL-OF-B')->count());
    }

    /**
     * POINT 2 (product_type variant) — the product_type leg also strictly
     * whereNull('account_id'); a tenant row with scope=product_type + account_id set
     * must not be resolved as a global product_type prompt.
     */
    public function test_product_type_leg_ignores_a_tenant_row_masquerading_with_product_type_scope(): void
    {
        $accountB = Account::factory()->create();
        $productType = 'shoes';

        Prompt::create([
            'scope' => Prompt::SCOPE_PRODUCT_TYPE,
            'operation_key' => self::OP,
            'product_type' => $productType,
            'account_id' => $accountB->id, // poison: a tenant id on a product_type row.
            'user_prompt' => 'POISONED-PT-OF-B',
            'version' => 100,
        ]);

        // Account A resolves with this product type; the poisoned PT row must not show.
        $accountA = Account::factory()->create();
        $siteA = Tenant::run($accountA, fn () => Site::create(['name' => 'A']));

        $bag = $this->resolver->for(self::OP, $siteA, $productType);

        $this->assertNotSame('POISONED-PT-OF-B', $bag->userPrompt);
        $this->assertStringContainsString(self::SEEDED_GLOBAL_NEEDLE, $bag->userPrompt);

        $this->assertSame(0, Prompt::query()->productTypeScoped($productType, self::OP)
            ->where('user_prompt', 'POISONED-PT-OF-B')->count());
    }

    /**
     * POINT 3 — site->site boundary WITHIN one account: site S1's site-scoped prompt
     * must not resolve for sibling site S2 of the SAME account (it falls to the
     * account prompt / global), and certainly never for another account's site.
     */
    public function test_site_scoped_prompt_does_not_bleed_to_a_sibling_site_of_the_same_account(): void
    {
        $account = Account::factory()->create();
        [$siteOne, $siteTwo] = Tenant::run($account, fn () => [
            Site::create(['name' => 'S1']),
            Site::create(['name' => 'S2']),
        ]);

        // A site-scoped prompt for S1 only.
        Prompt::create([
            'scope' => Prompt::SCOPE_SITE,
            'operation_key' => self::OP,
            'account_id' => $account->id,
            'site_id' => $siteOne->id,
            'user_prompt' => 'S1-SITE',
            'version' => 7,
        ]);

        // An account-scoped fallback for the same account.
        Prompt::create([
            'scope' => Prompt::SCOPE_ACCOUNT,
            'operation_key' => self::OP,
            'account_id' => $account->id,
            'user_prompt' => 'ACCT-FALLBACK',
        ]);

        // S1 gets its own site prompt.
        $this->assertSame('S1-SITE', $this->resolver->for(self::OP, $siteOne)->userPrompt);

        // S2 must NOT get S1's site prompt; it falls to the account prompt.
        $this->assertSame('ACCT-FALLBACK', $this->resolver->for(self::OP, $siteTwo)->userPrompt);
    }

    /**
     * POINT 3 (cross-account site) — S1's site prompt for account A must never
     * resolve for a site of account B even when the site ids happen to collide is
     * not possible (ids are global), but the account constraint must defend even if
     * a B site shared S1's site_id value. We assert the scope filters on BOTH
     * account_id AND site_id by probing the scope directly with B's account id.
     */
    public function test_site_scope_requires_both_account_id_and_site_id_to_match(): void
    {
        $accountA = Account::factory()->create();
        $siteA = Tenant::run($accountA, fn () => Site::create(['name' => 'A']));
        $accountB = Account::factory()->create();

        Prompt::create([
            'scope' => Prompt::SCOPE_SITE,
            'operation_key' => self::OP,
            'account_id' => $accountA->id,
            'site_id' => $siteA->id,
            'user_prompt' => 'A-SITE',
        ]);

        // Same site_id but B's account_id => must NOT match (account_id constraint).
        $this->assertSame(0, Prompt::query()
            ->siteScoped((int) $accountB->id, (int) $siteA->id, self::OP)
            ->count());

        // B's account_id with no matching site => zero.
        $this->assertSame(0, Prompt::query()
            ->siteScoped((int) $accountB->id, 999_999, self::OP)
            ->count());

        // A's own account + site => exactly the one row.
        $this->assertSame(1, Prompt::query()
            ->siteScoped((int) $accountA->id, (int) $siteA->id, self::OP)
            ->count());
    }

    /**
     * POINT 1 (account leg probe) — the account scope must filter on account_id;
     * B's account id must never return A's account prompt.
     */
    public function test_account_scope_strictly_filters_on_account_id(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();

        Prompt::create([
            'scope' => Prompt::SCOPE_ACCOUNT,
            'operation_key' => self::OP,
            'account_id' => $accountA->id,
            'user_prompt' => 'A-ACCT',
        ]);

        $this->assertSame(1, Prompt::query()->accountScoped((int) $accountA->id, self::OP)->count());
        $this->assertSame(0, Prompt::query()->accountScoped((int) $accountB->id, self::OP)->count());
    }
}
