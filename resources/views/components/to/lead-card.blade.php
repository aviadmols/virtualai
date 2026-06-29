{{--
    A7 — Lead card / attempt history.
    Renders one lead's identity header + its try-on attempts. Each attempt's
    outcome badge resolves through StatusBadge (generation machine); a purged
    result shows a placeholder tile + leads.history.purged, NEVER a broken image.
    The attempts are LeadAttempt DTOs from LeadAttemptHistory::for() — this view
    only renders them, it never queries.

    Props:
      name      lead full name (nullable → falls back to email/anon copy)
      email     nullable
      phone     nullable
      status    end_user.status (lead funnel) — badge via the lead machine
      attempts  Collection<LeadAttempt> (newest first; may be empty)

    TOKENS: lead-card.css. i18n: leads.col.*, leads.history.*, status.lead.*
--}}
@props([
    'name' => null,
    'email' => null,
    'phone' => null,
    'status',
    'attempts',
])
<div {{ $attributes->class(['to-lead-card']) }}>
    <div class="to-lead-card__header">
        <div class="to-lead-card__identity">
            <span class="to-lead-card__name">{{ $name ?: ($email ?: __('leads.anonymous')) }}</span>
            <div class="to-lead-card__contact">
                @if($email)
                    <span class="to-lead-card__contact-item">
                        <x-filament::icon icon="heroicon-o-envelope" class="to-credit-banner__icon" />
                        {{ $email }}
                    </span>
                @endif
                @if($phone)
                    <span class="to-lead-card__contact-item">
                        <x-filament::icon icon="heroicon-o-phone" class="to-credit-banner__icon" />
                        {{ $phone }}
                    </span>
                @endif
            </div>
        </div>

        <x-to.badge machine="lead" :status="$status" />
    </div>

    <div class="to-lead-card__history">
        @forelse($attempts as $attempt)
            <div class="to-attempt">
                @if($attempt->resultThumbnailUrl)
                    <img
                        src="{{ $attempt->resultThumbnailUrl }}"
                        alt="{{ $attempt->productName ?? __('leads.history.col.result') }}"
                        class="to-attempt__thumb"
                        loading="lazy"
                    >
                @else
                    <span class="to-attempt__thumb to-attempt__thumb--placeholder">
                        <x-filament::icon icon="heroicon-o-photo" class="to-attempt__thumb-glyph" />
                    </span>
                @endif

                <div class="to-attempt__body">
                    <span class="to-attempt__product">{{ $attempt->productName ?? __('leads.history.col.product') }}</span>
                    @if(! empty($attempt->variantOptions))
                        <span class="to-attempt__variant">{{ implode(' · ', $attempt->variantOptions) }}</span>
                    @endif
                    @if($attempt->purged)
                        <span class="to-attempt__purged">{{ __('leads.history.purged') }}</span>
                    @endif
                </div>

                <div class="to-attempt__meta">
                    <x-to.badge machine="generation" :status="$attempt->status" />
                    @if($attempt->createdAt)
                        <span class="to-attempt__when">
                            {{ \Illuminate\Support\Carbon::parse($attempt->createdAt)->diffForHumans() }}
                        </span>
                    @endif
                </div>
            </div>
        @empty
            <x-to.empty-state variant="first-run" title="leads.history.empty" />
        @endforelse
    </div>
</div>
