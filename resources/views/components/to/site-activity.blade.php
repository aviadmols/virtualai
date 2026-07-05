{{--
    WS1 — Shop recent-activity strip (merchant Overview hub). The site-level
    companion to <x-to.activity-timeline> (which is one lead's log): a calm,
    editorial list of the SHOP's latest events — one row per event, newest first,
    with a localised kind label, an optional short non-secret detail, and a
    relative timestamp. The events are EndUserActivityItem DTOs from
    SiteActivityTimeline::forSite() — account+site-scoped; this view only renders
    them, it never queries. Shares the .to-timeline frame so the hub reads as one
    card family.

    Props:
      events  Collection<EndUserActivityItem> (newest first; may be empty)

    TOKENS: activity-timeline.css. i18n: sites.hub.activity.*, activity.kind.*
--}}
@props([
    'events',
])
<div {{ $attributes->class(['to-timeline']) }}>
    <div class="to-timeline__header">
        <span class="to-timeline__title">{{ __('sites.hub.activity.title') }}</span>
        <span class="to-timeline__subtitle">{{ __('sites.hub.activity.subtitle') }}</span>
    </div>

    <div class="to-timeline__list">
        @forelse($events as $event)
            <div class="to-timeline__row">
                <span class="to-timeline__dot" aria-hidden="true"></span>

                <div class="to-timeline__body">
                    <span class="to-timeline__label">{{ __($event->labelKey) }}</span>
                    @if($event->detail)
                        <span class="to-timeline__detail">{{ $event->detail }}</span>
                    @endif
                </div>

                @if($event->createdAt)
                    <time class="to-timeline__when" datetime="{{ $event->createdAt }}">
                        {{ \Illuminate\Support\Carbon::parse($event->createdAt)->diffForHumans() }}
                    </time>
                @endif
            </div>
        @empty
            <x-to.empty-state variant="first-run" title="sites.hub.activity.empty" />
        @endforelse
    </div>
</div>
