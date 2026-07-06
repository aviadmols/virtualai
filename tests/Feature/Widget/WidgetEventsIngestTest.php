<?php

namespace Tests\Feature\Widget;

use App\Domain\Activity\EndUserActivityItem;
use App\Domain\Activity\EndUserActivityTimeline;
use App\Http\Requests\Widget\EventRequest;
use App\Models\ActivityEvent;
use App\Models\EndUser;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Phase 1d — the widget behavioral-events ingest (POST /widget/v1/events).
 *
 * Proves the endpoint records page views + interactions tied to the RIGHT end user,
 * account-isolated (account B never sees account A's events), curates only non-secret
 * scalars (no raw query strings / PII), tolerates a malformed/oversized batch without a
 * 500, and surfaces the new kinds on the per-user timeline.
 */
final class WidgetEventsIngestTest extends TestCase
{
    use RefreshDatabase, WidgetApiTestSupport;

    // === CONSTANTS ===
    private const ENDPOINT = '/widget/v1/events';

    private const ANON = 'anon_events_1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWidgetEnv();
    }

    public function test_it_records_a_page_view_and_an_interaction_for_the_right_end_user(): void
    {
        $ctx = $this->makeSiteContext();

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson(self::ENDPOINT, [
                'anon_token' => self::ANON,
                'events' => [
                    ['kind' => 'page_view', 'at' => '2026-07-06T10:00:00Z', 'path' => '/products/red-sneaker'],
                    ['kind' => 'interaction', 'at' => '2026-07-06T10:00:05Z', 'path' => '/products/red-sneaker',
                        'referrer_host' => 'google.com',
                        'interaction' => ['type' => 'tray_on_click', 'label' => 'Try it on']],
                ],
            ]);

        $response->assertOk()->assertJson(['ok' => true, 'recorded' => 2]);

        $endUser = $this->endUserFor($ctx['account'], $ctx['site'], self::ANON);
        $this->assertNotNull($endUser);

        $events = Tenant::run($ctx['account'], fn () => ActivityEvent::query()
            ->where('subject_type', EndUser::class)
            ->where('subject_id', $endUser->getKey())
            ->orderBy('id')
            ->get());

        $this->assertCount(2, $events);

        // Page view: only the bare path is persisted, actor is the end user, tied to the site.
        $pageView = $events->firstWhere('kind', ActivityEvent::KIND_PAGE_VIEW);
        $this->assertNotNull($pageView);
        $this->assertSame(ActivityEvent::ACTOR_END_USER, $pageView->actor);
        $this->assertSame((int) $ctx['site']->getKey(), (int) $pageView->site_id);
        $this->assertSame(['path' => '/products/red-sneaker'], $pageView->details);

        // Interaction: path + referrer_host + curated interaction type/label only.
        $interaction = $events->firstWhere('kind', ActivityEvent::KIND_INTERACTION);
        $this->assertNotNull($interaction);
        $this->assertSame([
            'path' => '/products/red-sneaker',
            'referrer_host' => 'google.com',
            'interaction_type' => 'tray_on_click',
            'interaction_label' => 'Try it on',
        ], $interaction->details);
    }

    public function test_it_strips_query_string_and_fragment_from_the_path(): void
    {
        $ctx = $this->makeSiteContext();

        $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson(self::ENDPOINT, [
                'anon_token' => self::ANON,
                'events' => [
                    // A path carrying a token + email in the query string + a fragment.
                    ['kind' => 'page_view', 'path' => '/checkout?token=secret123&email=a@b.co#section'],
                ],
            ])->assertOk();

        $endUser = $this->endUserFor($ctx['account'], $ctx['site'], self::ANON);
        $event = Tenant::run($ctx['account'], fn () => ActivityEvent::query()
            ->where('subject_id', $endUser->getKey())
            ->where('kind', ActivityEvent::KIND_PAGE_VIEW)
            ->firstOrFail());

        // Only the bare path survives — no query string, no fragment, no PII.
        $this->assertSame(['path' => '/checkout'], $event->details);
        $this->assertStringNotContainsString('secret123', json_encode($event->details));
        $this->assertStringNotContainsString('a@b.co', json_encode($event->details));
    }

    public function test_events_are_account_isolated_account_b_never_sees_account_a_events(): void
    {
        $a = $this->makeSiteContext([], 'https://a.example.com');
        $b = $this->makeSiteContext([], 'https://b.example.com');

        // Account A records an event under its own site.
        $this->withHeaders($this->widgetHeaders($a['site'], $a['origin']))
            ->postJson(self::ENDPOINT, [
                'anon_token' => 'anon_a_eventss_123456',
                'events' => [['kind' => 'page_view', 'path' => '/a-only']],
            ])->assertOk();

        // Under account B's bound tenant, none of A's activity rows are visible (fail-closed).
        Tenant::run($b['account'], function () use ($a): void {
            $this->assertSame(0, ActivityEvent::query()->where('account_id', $a['account']->id)->count());
            $this->assertSame(0, ActivityEvent::query()->where('kind', ActivityEvent::KIND_PAGE_VIEW)->count());
        });

        // Account A does see its own event.
        Tenant::run($a['account'], function () use ($a): void {
            $this->assertSame(1, ActivityEvent::query()
                ->where('account_id', $a['account']->id)
                ->where('kind', ActivityEvent::KIND_PAGE_VIEW)
                ->count());
        });
    }

    public function test_an_oversized_batch_is_capped_and_never_500s(): void
    {
        $ctx = $this->makeSiteContext();

        // 25 events; only the first MAX_EVENTS (20) are processed, the rest ignored.
        $events = [];
        for ($i = 0; $i < 25; $i++) {
            $events[] = ['kind' => 'page_view', 'path' => '/page-'.$i];
        }

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson(self::ENDPOINT, ['anon_token' => self::ANON, 'events' => $events]);

        $response->assertOk()->assertJson(['ok' => true, 'recorded' => EventRequest::MAX_EVENTS]);

        $endUser = $this->endUserFor($ctx['account'], $ctx['site'], self::ANON);
        $count = Tenant::run($ctx['account'], fn () => ActivityEvent::query()
            ->where('subject_id', $endUser->getKey())
            ->where('kind', ActivityEvent::KIND_PAGE_VIEW)
            ->count());

        $this->assertSame(EventRequest::MAX_EVENTS, $count);
    }

    public function test_a_malformed_batch_drops_bad_rows_without_a_500(): void
    {
        $ctx = $this->makeSiteContext();

        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson(self::ENDPOINT, [
                'anon_token' => self::ANON,
                'events' => [
                    ['kind' => 'page_view', 'path' => '/valid'],       // kept
                    ['kind' => 'unknown_kind', 'path' => '/x'],        // dropped: bad kind
                    ['kind' => 'page_view'],                           // dropped: no path
                    ['kind' => 'page_view', 'path' => '   '],          // dropped: blank path
                    'not-an-array',                                    // dropped: bad shape
                    ['no_kind' => true],                               // dropped: missing kind
                ],
            ]);

        $response->assertOk()->assertJson(['ok' => true, 'recorded' => 1]);

        $endUser = $this->endUserFor($ctx['account'], $ctx['site'], self::ANON);
        $count = Tenant::run($ctx['account'], fn () => ActivityEvent::query()
            ->where('subject_type', EndUser::class)
            ->where('subject_id', $endUser->getKey())
            ->count());

        $this->assertSame(1, $count);
    }

    public function test_a_totally_invalid_body_is_a_typed_json_response_not_html(): void
    {
        $ctx = $this->makeSiteContext();

        // Missing anon_token -> the FormRequest returns typed JSON 422, never HTML/500.
        $response = $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson(self::ENDPOINT, ['events' => [['kind' => 'page_view', 'path' => '/x']]]);

        $response->assertStatus(422)->assertJsonStructure(['message']);
        $response->assertHeader('content-type', 'application/json');
    }

    public function test_the_timeline_surfaces_the_new_kinds(): void
    {
        $ctx = $this->makeSiteContext();

        $this->withHeaders($this->widgetHeaders($ctx['site'], $ctx['origin']))
            ->postJson(self::ENDPOINT, [
                'anon_token' => self::ANON,
                'events' => [
                    ['kind' => 'page_view', 'path' => '/products/red-sneaker'],
                    ['kind' => 'interaction', 'path' => '/products/red-sneaker',
                        'interaction' => ['type' => 'tray_on_click', 'label' => 'Try it on']],
                ],
            ])->assertOk();

        $endUser = $this->endUserFor($ctx['account'], $ctx['site'], self::ANON);

        /** @var Collection<int,EndUserActivityItem> $timeline */
        $timeline = app(EndUserActivityTimeline::class)->for($endUser);

        $kinds = $timeline->pluck('kind')->all();
        $this->assertContains(ActivityEvent::KIND_PAGE_VIEW, $kinds);
        $this->assertContains(ActivityEvent::KIND_INTERACTION, $kinds);

        // The label keys target the shared activity.kind.* catalog.
        $pageView = $timeline->firstWhere('kind', ActivityEvent::KIND_PAGE_VIEW);
        $this->assertSame('activity.kind.page_view', $pageView->labelKey);
        // The page path is surfaced as the curated, non-secret detail line.
        $this->assertSame('/products/red-sneaker', $pageView->detail);

        // The interaction surfaces its label as the detail line (curated, non-secret).
        $interaction = $timeline->firstWhere('kind', ActivityEvent::KIND_INTERACTION);
        $this->assertSame('activity.kind.interaction', $interaction->labelKey);
        $this->assertSame('Try it on', $interaction->detail);
    }

    public function test_the_label_keys_resolve_in_both_locales(): void
    {
        $this->assertSame('Page viewed', __('activity.kind.page_view', [], 'en'));
        $this->assertSame('Interaction', __('activity.kind.interaction', [], 'en'));
        $this->assertNotSame('activity.kind.page_view', __('activity.kind.page_view', [], 'he'));
        $this->assertNotSame('activity.kind.interaction', __('activity.kind.interaction', [], 'he'));
    }
}
