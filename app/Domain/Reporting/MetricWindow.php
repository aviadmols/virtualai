<?php

namespace App\Domain\Reporting;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * MetricWindow — the time period a metric is computed over. Immutable.
 *
 * A window is the inclusive interval [from, until]: a row counts when its timestamp
 * is `>= from AND <= until`. The until edge is inclusive so a "now"-anchored window
 * (today / lastDays) counts a row created in the current second (a second-resolution
 * store would otherwise drop a just-created row at the `now` boundary).
 *
 * priorPeriod() returns the equal-length window ending one microsecond BEFORE `from`,
 * so the current and prior windows (a delta chip, A1 value+delta) never share — and
 * never double-count — the boundary instant.
 *
 * The named constructors are the only sanctioned window shapes the dashboards use
 * (today, last N days, all-time). A caller never hand-rolls a raw from/until at a
 * call site — it asks for a named window so the period definitions stay frozen.
 */
final readonly class MetricWindow
{
    // === CONSTANTS ===
    // The dashboard's default rolling window for "recent" KPIs (A1/A11 trend slots).
    public const DEFAULT_DAYS = 30;

    // A human label for the window, surfaced to the UI / report header (not i18n —
    // the admin layer maps these keys to __() strings; here it is a stable token).
    public const LABEL_TODAY = 'today';
    public const LABEL_LAST_N_DAYS = 'last_n_days';
    public const LABEL_ALL_TIME = 'all_time';
    public const LABEL_CUSTOM = 'custom';

    private function __construct(
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $until,
        public string $label,
    ) {}

    /** The current calendar day [start-of-today, now). */
    public static function today(): self
    {
        $now = CarbonImmutable::now();

        return new self($now->startOfDay(), $now, self::LABEL_TODAY);
    }

    /**
     * A rolling window of the last $days days ending now [now - days, now).
     * The dashboard's "generations over a window" KPI uses this (default 30).
     */
    public static function lastDays(int $days = self::DEFAULT_DAYS): self
    {
        $now = CarbonImmutable::now();

        return new self($now->subDays($days), $now, self::LABEL_LAST_N_DAYS);
    }

    /** No bounds — every row counts. The lifetime totals (ledger, all leads). */
    public static function allTime(): self
    {
        return new self(null, null, self::LABEL_ALL_TIME);
    }

    /**
     * Resolve a window from a report page's filter state: `period` of a day count → lastDays(N);
     * `custom` with from+until → between(); anything missing → the default 30-day window. The one
     * place the filter shape maps to a window, shared by the costs widgets + the log page.
     *
     * @param  array<string,mixed>  $filters
     */
    public static function fromFilters(array $filters): self
    {
        $period = (string) ($filters['period'] ?? (string) self::DEFAULT_DAYS);

        if ($period === self::LABEL_CUSTOM) {
            $from = $filters['from'] ?? null;
            $until = $filters['until'] ?? null;

            if (filled($from) && filled($until)) {
                return self::between(CarbonImmutable::parse((string) $from), CarbonImmutable::parse((string) $until));
            }

            return self::lastDays();
        }

        $days = (int) $period;

        return $days > 0 ? self::lastDays($days) : self::lastDays();
    }

    /**
     * An explicit inclusive date range [from, until] — for the Super-Admin report date picker.
     * Normalised to whole days (start-of-day .. end-of-day) so both edge days count in full, and
     * reversed bounds are swapped so a picker never yields an empty window.
     */
    public static function between(CarbonImmutable $from, CarbonImmutable $until): self
    {
        $start = $from->startOfDay();
        $end = $until->endOfDay();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$until->startOfDay(), $from->endOfDay()];
        }

        return new self($start, $end, self::LABEL_CUSTOM);
    }

    /**
     * The window of equal length immediately preceding this one — for a delta /
     * trend comparison (A1 value+delta). All-time has no prior period (returns
     * all-time again; a delta against all-time is meaningless and the UI omits it).
     */
    public function priorPeriod(): self
    {
        if ($this->from === null || $this->until === null) {
            return self::allTime();
        }

        $length = $this->until->diffInSeconds($this->from);
        // End one microsecond before `from` so the prior window does not share the
        // boundary instant with the current window (no double-count on a delta).
        $priorUntil = $this->from->subMicrosecond();

        return new self(
            $priorUntil->subSeconds($length),
            $priorUntil,
            $this->label,
        );
    }

    /** True when the window has no bounds (all-time). */
    public function isUnbounded(): bool
    {
        return $this->from === null && $this->until === null;
    }

    /**
     * Constrain a query's $column to this window. A no-op for all-time. Used by
     * every metric builder so the window semantics live in exactly one place.
     */
    public function constrain(\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query, string $column): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
    {
        if ($this->from !== null) {
            $query->where($column, '>=', $this->from);
        }

        if ($this->until !== null) {
            $query->where($column, '<=', $this->until);
        }

        return $query;
    }

    /** ISO-8601 from-bound (or null for all-time) — for the report header / cache key. */
    public function fromIso(): ?string
    {
        return $this->from?->format(DateTimeInterface::ATOM);
    }

    /** ISO-8601 until-bound (or null for all-time). */
    public function untilIso(): ?string
    {
        return $this->until?->format(DateTimeInterface::ATOM);
    }
}
