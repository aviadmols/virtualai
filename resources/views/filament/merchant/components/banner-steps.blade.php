{{--
    The banner editor's guided-flow strip: generate → choose → place → activate. Each step is
    computed from the record's real state (done / current / upcoming) so the merchant always sees
    where the banner stands and what to do next. Pure display — clicking nothing, changing nothing.
    TOKENS: banner-steps.css (.to-banner-steps*). No inline CSS; logical properties (mirrors in HE).
--}}
@php
    use App\Models\Banner;
    use App\Models\BannerAsset;

    /** @var Banner|null $record */
    $record = $getRecord();

    $hasCandidate = $record?->assets()->where('status', BannerAsset::STATUS_SUCCEEDED)->exists() ?? false;
    $hasArtwork = ($record?->selected_asset_id ?? null) !== null;
    $hasPlacements = ! empty($record?->placements);
    $isLive = ($record?->status ?? null) === Banner::STATUS_ACTIVE;

    $steps = [
        ['key' => 'generate', 'done' => $hasCandidate],
        ['key' => 'choose', 'done' => $hasArtwork],
        ['key' => 'place', 'done' => $hasPlacements],
        ['key' => 'activate', 'done' => $isLive],
    ];

    // The first not-done step is the one to act on now; false when every step is done.
    $current = collect($steps)->search(fn (array $s): bool => ! $s['done']);
@endphp

<ol class="to-banner-steps">
    @foreach ($steps as $i => $step)
        <li @class([
            'to-banner-steps__step',
            'to-banner-steps__step--done' => $step['done'],
            'to-banner-steps__step--current' => $i === $current,
        ])>
            <span class="to-banner-steps__dot">
                @if ($step['done'])
                    <x-filament::icon icon="heroicon-m-check" />
                @else
                    {{ $i + 1 }}
                @endif
            </span>

            <span class="to-banner-steps__meta">
                <span class="to-banner-steps__label">{{ __('banners.steps.'.$step['key']) }}</span>
                <span class="to-banner-steps__hint">{{ __('banners.steps.'.$step['key'].'_hint') }}</span>
            </span>
        </li>
    @endforeach
</ol>
