<?php

namespace App\Domain\Activity;

/**
 * EndUserActivityItem — one immutable row of a lead's activity timeline (WS3).
 *
 * Exactly what the lead-card timeline renders per event: the event kind, its
 * localised label key (activity.kind.*), the timestamp, and a small non-secret
 * human-readable detail line. No live model leaks into Blade — the read model
 * (EndUserActivityTimeline) maps each ActivityEvent to one of these.
 *
 * The detail line is a short, non-secret scalar summary (already curated by the
 * recorder); it never carries a widget_secret, an OpenRouter key, or a PII dump.
 */
final readonly class EndUserActivityItem
{
    // === CONSTANTS ===
    // The i18n prefix for the per-kind label (mirrors the platform log catalog).
    public const LABEL_PREFIX = 'activity.kind.';

    public function __construct(
        public int $id,
        // The raw ActivityEvent::KIND_* token (stable taxonomy).
        public string $kind,
        // The full i18n key the view resolves through __() (activity.kind.<kind>).
        public string $labelKey,
        // Who caused it: system | merchant | end_user | webhook.
        public string $actor,
        // A short, non-secret human-readable detail (or null when there is none).
        public ?string $detail,
        // ISO-8601 timestamp; the view formats it (diffForHumans) at render time.
        public ?string $createdAt,
    ) {}
}
