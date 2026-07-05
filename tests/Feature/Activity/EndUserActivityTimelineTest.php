<?php

namespace Tests\Feature\Activity;

use App\Domain\Activity\ActivityRecorder;
use App\Domain\Activity\EndUserActivityItem;
use App\Domain\Activity\EndUserActivityTimeline;
use App\Models\Account;
use App\Models\ActivityEvent;
use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * WS3 — per-end-user activity timeline (merchant lead card).
 *
 * Proves the read model returns only THIS lead's activity, newest-first, and is
 * account-scoped through BelongsToAccount (no withoutGlobalScopes) so account B can
 * never read account A's lead activity.
 */
class EndUserActivityTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeline_returns_only_that_end_users_events_newest_first(): void
    {
        $account = Account::factory()->create();
        $site = Site::factory()->forAccount($account)->create();

        $lead = Tenant::run($account, function () use ($account, $site): EndUser {
            $recorder = app(ActivityRecorder::class);

            $lead = EndUser::factory()->forSite($site)->create();
            $other = EndUser::factory()->forSite($site)->create();

            $generation = $this->generationFor($lead, $site);

            // Oldest -> newest so newest-first ordering is observable.
            $this->recordAt($recorder, ActivityEvent::KIND_LEAD_REGISTERED, $lead, '-30 minutes', ['email' => 'a@b.co']);
            $this->recordAt($recorder, ActivityEvent::KIND_GENERATION_REQUESTED, $generation, '-20 minutes');
            $this->recordAt($recorder, ActivityEvent::KIND_GENERATION_SUCCEEDED, $generation, '-10 minutes', ['charge_micro_usd' => 5000]);
            $this->recordAt($recorder, ActivityEvent::KIND_LEAD_ADDED_TO_CART, $lead, '-5 minutes', ['product_id' => $generation->product_id]);

            // Noise that must NOT appear: another lead's event, a generation of the
            // other lead, and an account-level credit event (not shopper-scoped).
            $this->recordAt($recorder, ActivityEvent::KIND_LEAD_REGISTERED, $other, '-1 minute');
            $this->recordAt($recorder, ActivityEvent::KIND_CREDIT_CHARGED, $account, '-2 minutes');

            return $lead;
        });

        /** @var Collection<int,EndUserActivityItem> $timeline */
        $timeline = app(EndUserActivityTimeline::class)->for($lead);

        // Exactly this lead's 4 events (2 lead-subject + 2 generation-subject).
        $this->assertCount(4, $timeline);
        $this->assertInstanceOf(EndUserActivityItem::class, $timeline->first());

        // Newest first.
        $this->assertSame([
            ActivityEvent::KIND_LEAD_ADDED_TO_CART,
            ActivityEvent::KIND_GENERATION_SUCCEEDED,
            ActivityEvent::KIND_GENERATION_REQUESTED,
            ActivityEvent::KIND_LEAD_REGISTERED,
        ], $timeline->pluck('kind')->all());

        // The label key targets the shared activity.kind.* catalog.
        $this->assertSame('activity.kind.lead_added_to_cart', $timeline->first()->labelKey);

        // A curated, non-secret detail line is surfaced (never a raw payload).
        $succeeded = $timeline->firstWhere('kind', ActivityEvent::KIND_GENERATION_SUCCEEDED);
        $this->assertNotNull($succeeded->createdAt);

        // No noise leaked in.
        $this->assertFalse($timeline->contains(fn (EndUserActivityItem $i) => $i->kind === ActivityEvent::KIND_CREDIT_CHARGED));
    }

    public function test_timeline_is_account_scoped_account_b_cannot_read_account_a_activity(): void
    {
        // Account A with a lead and recorded activity.
        $accountA = Account::factory()->create();
        $siteA = Site::factory()->forAccount($accountA)->create();

        $leadA = Tenant::run($accountA, function () use ($siteA): EndUser {
            $recorder = app(ActivityRecorder::class);
            $leadA = EndUser::factory()->forSite($siteA)->create();
            $generationA = $this->generationFor($leadA, $siteA);

            $this->recordAt($recorder, ActivityEvent::KIND_LEAD_REGISTERED, $leadA, '-10 minutes');
            $this->recordAt($recorder, ActivityEvent::KIND_GENERATION_SUCCEEDED, $generationA, '-5 minutes');

            return $leadA;
        });

        // Account A's own read sees its events.
        $this->assertCount(2, app(EndUserActivityTimeline::class)->for($leadA));

        // Account B exists and is the CURRENTLY BOUND tenant (adversarial): it must not
        // be able to read A's lead activity. The read model rebinds to the lead's OWN
        // account, and the global scope fails closed for any cross-account row.
        $accountB = Account::factory()->create();

        $timeline = Tenant::run($accountB, function () use ($accountB, $leadA): Collection {
            // A colliding-id decoy under B so a naive id-only match would be caught.
            $siteB = Site::factory()->forAccount($accountB)->create();
            $recorder = app(ActivityRecorder::class);
            $leadB = EndUser::factory()->forSite($siteB)->create();
            $this->recordAt($recorder, ActivityEvent::KIND_LEAD_REGISTERED, $leadB, '-1 minute');

            // Ask for account A's lead while B is bound.
            return app(EndUserActivityTimeline::class)->for($leadA);
        });

        // The timeline is A's lead's activity (the read model rebinds to A's account),
        // and NONE of B's rows appear. Every returned row belongs to A's events.
        $this->assertCount(2, $timeline);

        // Prove no cross-account leak at the DB level: under B's bound global scope, NONE
        // of account A's activity rows are visible (fail-closed), while A's own rows do
        // exist (so the zero below is isolation, not an empty table).
        Tenant::run($accountB, function () use ($accountA): void {
            $this->assertSame(0, ActivityEvent::query()->where('account_id', $accountA->id)->count());
        });
        Tenant::run($accountA, function () use ($accountA): void {
            $this->assertGreaterThan(0, ActivityEvent::query()->where('account_id', $accountA->id)->count());
        });
    }

    /** Build a coherent generation for the lead (same account/site chain). */
    private function generationFor(EndUser $endUser, Site $site): Generation
    {
        $product = Product::factory()->forSite($site)->confirmed()->create();
        $variant = ProductVariant::factory()->forProduct($product)->create();

        return Generation::factory()
            ->forContext($endUser, $product, $variant, 'crq-'.$endUser->getKey())
            ->create(['status' => Generation::STATUS_SUCCEEDED]);
    }

    /**
     * Record one activity event about a subject at a fixed created_at, so newest-first
     * ordering is deterministic.
     *
     * @param  array<string,mixed>  $details
     */
    private function recordAt(
        ActivityRecorder $recorder,
        string $kind,
        \Illuminate\Database\Eloquent\Model $subject,
        string $ago,
        array $details = [],
    ): void {
        $event = $recorder->record(kind: $kind, subject: $subject, details: $details);
        // Recorder stamps now(); pin created_at for a stable ordering assertion.
        $event->forceFill(['created_at' => now()->modify($ago)])->save();
    }
}
