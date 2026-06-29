<?php

namespace Tests\Feature\Platform;

use App\Domain\Generation\GenerateTryOnJob;
use App\Domain\Generation\GenerationFailureCode;
use App\Models\Account;
use App\Models\ActivityEvent;
use App\Models\CreditLedger;
use App\Models\Generation;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Generation\GenerationTestSupport;
use Tests\TestCase;

/**
 * GAP-P2 — Account suspend/restore (super-admin control plane) and the proof that a
 * suspended account's generation is DENIED with the typed ACCOUNT_INACTIVE failure
 * (cancelled, never a 500, never a charge), and a restore re-opens the gate.
 *
 * The deny is already enforced by CreditGate.isActive() inside GenerateTryOnJob — this
 * test proves the suspend() transition flips that gate input end-to-end.
 */
class AccountSuspendRestoreTest extends TestCase
{
    use GenerationTestSupport, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGenerationEnv();
    }

    private function runJob(array $context, Generation $generation): void
    {
        (new GenerateTryOnJob(
            (int) $context['account']->id,
            (int) $context['site']->id,
            (int) $generation->id,
        ))->handle();
    }

    public function test_suspend_writes_status_and_records_an_event_idempotently(): void
    {
        $account = Account::factory()->create();
        $this->assertTrue($account->isActive());

        $changed = $account->suspend('fraud review');

        $this->assertTrue($changed);
        $this->assertTrue($account->fresh()->isSuspended());

        // A second suspend is a no-op (idempotent) — no status churn, no duplicate trace.
        $this->assertFalse($account->suspend('again'));

        $events = Tenant::run($account, fn () => ActivityEvent::query()
            ->where('kind', ActivityEvent::KIND_ACCOUNT_SUSPENDED)
            ->where('subject_id', $account->id)
            ->get());
        $this->assertCount(1, $events);
        $this->assertSame('fraud review', $events->first()->details['reason'] ?? null);
    }

    public function test_restore_writes_status_and_records_an_event_idempotently(): void
    {
        $account = Account::factory()->create();
        $account->suspend(null);

        $changed = $account->restore();

        $this->assertTrue($changed);
        $this->assertTrue($account->fresh()->isActive());
        // A second restore is a no-op.
        $this->assertFalse($account->restore());

        $events = Tenant::run($account, fn () => ActivityEvent::query()
            ->where('kind', ActivityEvent::KIND_ACCOUNT_RESTORED)
            ->where('subject_id', $account->id)
            ->count());
        $this->assertSame(1, $events);
    }

    public function test_restore_on_an_active_account_is_a_no_op(): void
    {
        $account = Account::factory()->create();

        $this->assertFalse($account->restore());
        $this->assertTrue($account->fresh()->isActive());
    }

    public function test_suspended_account_generation_is_denied_then_restore_re_opens_it(): void
    {
        $this->fakeOpenRouterSuccess();

        $context = $this->makeContext();
        $account = $context['account'];

        // --- SUSPEND -> the generation is denied with the typed failure, no charge ---
        $account->suspend('billing hold');

        $blocked = $this->makePendingGeneration($context, 'crq-blocked');
        $this->runJob($context, $blocked);

        $blocked->refresh();
        $this->assertSame(Generation::STATUS_CANCELLED, $blocked->status); // typed, not a 500
        $this->assertSame(GenerationFailureCode::ACCOUNT_INACTIVE, $blocked->failure_code);

        // No charge while suspended.
        $charges = Tenant::run($account, fn () => CreditLedger::query()
            ->where('type', CreditLedger::TYPE_CHARGE)->count());
        $this->assertSame(0, $charges);

        // --- RESTORE -> a fresh generation is allowed and charged ---
        $account->restore();

        $allowed = $this->makePendingGeneration($context, 'crq-allowed');
        $this->runJob($context, $allowed);

        $allowed->refresh();
        $this->assertSame(Generation::STATUS_SUCCEEDED, $allowed->status);
        $this->assertNotNull($allowed->charge_ledger_id);
    }
}
