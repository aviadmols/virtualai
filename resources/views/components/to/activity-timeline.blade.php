{{--
    WS3 — Per-end-user activity timeline (merchant lead card).
    Renders everything a registered shopper did on the shop: one row per activity
    event, newest first, with a localised kind label, an actor tag, an optional
    short non-secret detail, and a relative timestamp. The events are
    EndUserActivityItem DTOs from EndUserActivityTimeline::for() — account-scoped;
    this view only renders them, it never queries.

    Props:
      events  Collection<EndUserActivityItem> (newest first; may be empty)

    TOKENS: activity-timeline.css. i18n: activity.timeline.*, activity.kind.*
--}}
@props([
    'events',
])
<div {{ $attributes->class(['to-timeline']) }}>
    <div class="to-timeline__header">
        <span class="to-timeline__title">{{ __('activity.timeline.title') }}</span>
        <span class="to-timeline__subtitle">{{ __('activity.timeline.subtitle') }}</span>
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
            <x-to.empty-state variant="first-run" title="activity.timeline.empty" />
        @endforelse
    </div>
</div>
