<?php

namespace Tests\Feature\Generation;

use App\Models\Account;
use App\Models\ActivityEvent;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The guarded Generation status machine (ARCHITECTURE.md §4): only canonical moves
 * are legal — pending->processing->succeeded | failed, pending|processing->cancelled.
 * An illegal move THROWS; every accepted move writes an activity_event.
 */
class GenerationStatusMachineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a coherent pending generation owned entirely by $account (site, lead,
     * product, variant all under it) so no nested factory mints a foreign account_id
     * while the tenant is bound. Built OUTSIDE Tenant::run (factories set account_id
     * explicitly); the test then operates on it inside the bound scope.
     */
    private function generation(Account $account): Generation
    {
        $site = Site::factory()->forAccount($account)->create();
        $endUser = EndUser::factory()->forSite($site)->create();
        $product = Product::factory()->forSite($site)->confirmed()->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();

        return Generation::factory()
            ->forContext($endUser, $product, $variant, 'crq-sm')
            ->create(['status' => Generation::STATUS_PENDING]);
    }

    public function test_legal_path_pending_processing_succeeded(): void
    {
        $account = Account::factory()->create();

        Tenant::run($account, function () use ($account) {
            $gen = $this->generation($account);
            $gen->transitionTo(Generation::STATUS_PROCESSING);
            $this->assertSame(Generation::STATUS_PROCESSING, $gen->fresh()->status);

            $gen->transitionTo(Generation::STATUS_SUCCEEDED);
            $this->assertSame(Generation::STATUS_SUCCEEDED, $gen->fresh()->status);
        });
    }

    public function test_legal_path_processing_failed(): void
    {
        $account = Account::factory()->create();

        Tenant::run($account, function () use ($account) {
            $gen = $this->generation($account);
            $gen->transitionTo(Generation::STATUS_PROCESSING);
            $gen->transitionTo(Generation::STATUS_FAILED);
            $this->assertSame(Generation::STATUS_FAILED, $gen->fresh()->status);
        });
    }

    public function test_pending_can_cancel(): void
    {
        $account = Account::factory()->create();

        Tenant::run($account, function () use ($account) {
            $gen = $this->generation($account);
            $gen->transitionTo(Generation::STATUS_CANCELLED);
            $this->assertSame(Generation::STATUS_CANCELLED, $gen->fresh()->status);
        });
    }

    public function test_illegal_pending_to_failed_throws(): void
    {
        $account = Account::factory()->create();

        Tenant::run($account, function () use ($account) {
            $gen = $this->generation($account);
            // pending -> failed is NOT a legal move (failed requires processing first).
            $this->expectException(RuntimeException::class);
            $gen->transitionTo(Generation::STATUS_FAILED);
        });
    }

    public function test_illegal_pending_to_succeeded_throws(): void
    {
        $account = Account::factory()->create();

        Tenant::run($account, function () use ($account) {
            $gen = $this->generation($account);
            $this->expectException(RuntimeException::class);
            $gen->transitionTo(Generation::STATUS_SUCCEEDED);
        });
    }

    public function test_terminal_states_are_locked(): void
    {
        $account = Account::factory()->create();

        Tenant::run($account, function () use ($account) {
            $gen = $this->generation($account);
            $gen->transitionTo(Generation::STATUS_PROCESSING);
            $gen->transitionTo(Generation::STATUS_SUCCEEDED);

            // succeeded is terminal — nothing follows it.
            $this->expectException(RuntimeException::class);
            $gen->transitionTo(Generation::STATUS_FAILED);
        });
    }

    public function test_every_accepted_transition_writes_an_activity_event(): void
    {
        $account = Account::factory()->create();

        Tenant::run($account, function () use ($account) {
            $gen = $this->generation($account);
            $gen->transitionTo(Generation::STATUS_PROCESSING);
            $gen->transitionTo(Generation::STATUS_SUCCEEDED);

            $events = ActivityEvent::query()
                ->where('subject_type', Generation::class)
                ->where('subject_id', $gen->id)
                ->where('kind', Generation::KIND_STATUS_CHANGED)
                ->get();

            // One status_changed event per accepted move (2 moves -> 2 events).
            $this->assertCount(2, $events);
            $this->assertSame('pending', $events[0]->details['from']);
            $this->assertSame('processing', $events[0]->details['to']);
            $this->assertSame('processing', $events[1]->details['from']);
            $this->assertSame('succeeded', $events[1]->details['to']);
        });
    }
}
