<?php

namespace App\Domain\Banners;

use Illuminate\Support\Carbon;

/**
 * BannerRules — the per-banner DISPLAY-RULES schema (who/when a banner shows), the single source
 * of truth for the targeting fields. Mirrors ClubConfig's sanitize/resolve contract:
 *  - sanitize() validates a merchant patch (throws InvalidBannerException on any bad value),
 *  - resolve() merges stored over DEFAULTS so the widget always gets a complete, valid set.
 *
 * SCHEDULE is enforced SERVER-SIDE (the bootstrap ships only active + in-window banners, see
 * Banner::scopeActiveInSchedule). AUDIENCE / PAGES / FREQUENCY / LOCALES are evaluated CLIENT-SIDE
 * by the widget from signals it already holds (club membership, lead state, page context, locale,
 * a per-session counter). Every field is pure display targeting — no money, no PII.
 */
final class BannerRules
{
    // === CONSTANTS ===
    public const KEY_AUDIENCE = 'audience';

    public const KEY_PAGES = 'pages';

    public const KEY_SCHEDULE = 'schedule';

    public const KEY_FREQUENCY = 'frequency';

    public const KEY_LOCALES = 'locales';

    // Audience — a single segment (evaluated against the shopper's state).
    public const AUDIENCE_ANY = 'any';

    public const AUDIENCE_CLUB_MEMBERS = 'club_members';

    public const AUDIENCE_NON_MEMBERS = 'non_members';

    public const AUDIENCE_REGISTERED = 'registered';

    public const AUDIENCE_NEW_VISITORS = 'new_visitors';

    public const AUDIENCE_RETURNING_VISITORS = 'returning_visitors';

    public const AUDIENCES = [
        self::AUDIENCE_ANY,
        self::AUDIENCE_CLUB_MEMBERS,
        self::AUDIENCE_NON_MEMBERS,
        self::AUDIENCE_REGISTERED,
        self::AUDIENCE_NEW_VISITORS,
        self::AUDIENCE_RETURNING_VISITORS,
    ];

    // Page context — where in the store the banner may show.
    public const PAGE_ANY = 'any';

    public const PAGE_PDP = 'pdp';

    public const PAGE_CATALOG = 'catalog';

    public const PAGE_CART = 'cart';

    public const PAGE_CONTEXTS = [
        self::PAGE_ANY,
        self::PAGE_PDP,
        self::PAGE_CATALOG,
        self::PAGE_CART,
    ];

    public const KEY_PAGE_CONTEXT = 'context';

    public const KEY_PAGE_URL_CONTAINS = 'url_contains';

    public const URL_CONTAINS_MAX = 200;

    public const KEY_SCHEDULE_STARTS_AT = 'starts_at';

    public const KEY_SCHEDULE_ENDS_AT = 'ends_at';

    public const KEY_FREQUENCY_MAX = 'max_per_session';

    // Max impressions per shopper-session (0 = unlimited).
    public const FREQUENCY_MAX_MIN = 0;

    public const FREQUENCY_MAX_MAX = 100;

    // The storefront locales a banner may target (empty = all locales).
    public const LOCALES = ['en', 'he'];

    public const DEFAULTS = [
        self::KEY_AUDIENCE => self::AUDIENCE_ANY,
        self::KEY_PAGES => [self::KEY_PAGE_CONTEXT => self::PAGE_ANY, self::KEY_PAGE_URL_CONTAINS => null],
        self::KEY_SCHEDULE => [self::KEY_SCHEDULE_STARTS_AT => null, self::KEY_SCHEDULE_ENDS_AT => null],
        self::KEY_FREQUENCY => [self::KEY_FREQUENCY_MAX => 0],
        self::KEY_LOCALES => [],
    ];

    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * Validate a merchant rules patch and return the sanitized FULL set (defaults for absent
     * keys). Throws InvalidBannerException on any bad value — nothing is persisted.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public static function sanitize(array $input): array
    {
        $clean = self::DEFAULTS;

        if (array_key_exists(self::KEY_AUDIENCE, $input)) {
            $clean[self::KEY_AUDIENCE] = self::validEnum($input[self::KEY_AUDIENCE], self::AUDIENCES, 'audience');
        }

        if (array_key_exists(self::KEY_PAGES, $input)) {
            $clean[self::KEY_PAGES] = self::validPages($input[self::KEY_PAGES]);
        }

        if (array_key_exists(self::KEY_SCHEDULE, $input)) {
            $clean[self::KEY_SCHEDULE] = self::validSchedule($input[self::KEY_SCHEDULE]);
        }

        if (array_key_exists(self::KEY_FREQUENCY, $input)) {
            $clean[self::KEY_FREQUENCY] = self::validFrequency($input[self::KEY_FREQUENCY]);
        }

        if (array_key_exists(self::KEY_LOCALES, $input)) {
            $clean[self::KEY_LOCALES] = self::validLocales($input[self::KEY_LOCALES]);
        }

        return $clean;
    }

    /**
     * The effective rules for the widget: stored merged OVER defaults, keeping only known/valid
     * values. Always returns a complete, valid set.
     *
     * @param  array<string,mixed>|null  $stored
     * @return array<string,mixed>
     */
    public static function resolve(?array $stored): array
    {
        $resolved = self::DEFAULTS;

        if (! is_array($stored)) {
            return $resolved;
        }

        if (self::isKnown($stored[self::KEY_AUDIENCE] ?? null, self::AUDIENCES)) {
            $resolved[self::KEY_AUDIENCE] = $stored[self::KEY_AUDIENCE];
        }

        if (is_array($stored[self::KEY_PAGES] ?? null)) {
            $ctx = $stored[self::KEY_PAGES][self::KEY_PAGE_CONTEXT] ?? null;
            if (self::isKnown($ctx, self::PAGE_CONTEXTS)) {
                $resolved[self::KEY_PAGES][self::KEY_PAGE_CONTEXT] = $ctx;
            }
            $url = $stored[self::KEY_PAGES][self::KEY_PAGE_URL_CONTAINS] ?? null;
            $resolved[self::KEY_PAGES][self::KEY_PAGE_URL_CONTAINS] = (is_string($url) && $url !== '') ? $url : null;
        }

        if (is_array($stored[self::KEY_SCHEDULE] ?? null)) {
            foreach ([self::KEY_SCHEDULE_STARTS_AT, self::KEY_SCHEDULE_ENDS_AT] as $k) {
                $v = $stored[self::KEY_SCHEDULE][$k] ?? null;
                $resolved[self::KEY_SCHEDULE][$k] = is_string($v) && $v !== '' ? $v : null;
            }
        }

        if (is_array($stored[self::KEY_FREQUENCY] ?? null)) {
            $max = $stored[self::KEY_FREQUENCY][self::KEY_FREQUENCY_MAX] ?? null;
            if (is_int($max) && $max >= self::FREQUENCY_MAX_MIN && $max <= self::FREQUENCY_MAX_MAX) {
                $resolved[self::KEY_FREQUENCY][self::KEY_FREQUENCY_MAX] = $max;
            }
        }

        if (is_array($stored[self::KEY_LOCALES] ?? null)) {
            $resolved[self::KEY_LOCALES] = array_values(array_filter(
                $stored[self::KEY_LOCALES],
                static fn ($l) => in_array($l, self::LOCALES, true),
            ));
        }

        return $resolved;
    }

    private static function validEnum(mixed $value, array $allowed, string $field): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw self::reject($field, 'must be one of: '.implode(', ', $allowed));
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private static function validPages(mixed $value): array
    {
        if (! is_array($value)) {
            throw self::reject('pages', 'must be an object');
        }

        $context = $value[self::KEY_PAGE_CONTEXT] ?? self::PAGE_ANY;
        $context = self::validEnum($context, self::PAGE_CONTEXTS, 'pages.context');

        $urlContains = $value[self::KEY_PAGE_URL_CONTAINS] ?? null;
        if ($urlContains !== null) {
            if (! is_string($urlContains)) {
                throw self::reject('pages.url_contains', 'must be text');
            }
            $urlContains = trim($urlContains);
            if ($urlContains === '') {
                $urlContains = null;
            } elseif (mb_strlen($urlContains) > self::URL_CONTAINS_MAX) {
                throw self::reject('pages.url_contains', 'must be at most '.self::URL_CONTAINS_MAX.' characters');
            }
        }

        return [self::KEY_PAGE_CONTEXT => $context, self::KEY_PAGE_URL_CONTAINS => $urlContains];
    }

    /** @return array<string,?string> */
    private static function validSchedule(mixed $value): array
    {
        if (! is_array($value)) {
            throw self::reject('schedule', 'must be an object');
        }

        $starts = self::validDate($value[self::KEY_SCHEDULE_STARTS_AT] ?? null, 'schedule.starts_at');
        $ends = self::validDate($value[self::KEY_SCHEDULE_ENDS_AT] ?? null, 'schedule.ends_at');

        if ($starts !== null && $ends !== null && Carbon::parse($ends)->lessThanOrEqualTo(Carbon::parse($starts))) {
            throw self::reject('schedule', 'the end must be after the start');
        }

        return [self::KEY_SCHEDULE_STARTS_AT => $starts, self::KEY_SCHEDULE_ENDS_AT => $ends];
    }

    /** A nullable ISO-8601 datetime string; a bad date throws, a blank returns null. */
    private static function validDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw self::reject($field, 'must be a date/time');
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            throw self::reject($field, 'is not a valid date/time');
        }
    }

    /** @return array<string,int> */
    private static function validFrequency(mixed $value): array
    {
        if (! is_array($value)) {
            throw self::reject('frequency', 'must be an object');
        }

        $max = $value[self::KEY_FREQUENCY_MAX] ?? 0;
        if (! is_int($max) || $max < self::FREQUENCY_MAX_MIN || $max > self::FREQUENCY_MAX_MAX) {
            throw self::reject('frequency.max_per_session', 'must be a whole number between '.self::FREQUENCY_MAX_MIN.' and '.self::FREQUENCY_MAX_MAX);
        }

        return [self::KEY_FREQUENCY_MAX => $max];
    }

    /** @return array<int,string> */
    private static function validLocales(mixed $value): array
    {
        if (! is_array($value)) {
            throw self::reject('locales', 'must be a list');
        }

        $clean = [];
        foreach ($value as $locale) {
            if (! in_array($locale, self::LOCALES, true)) {
                throw self::reject('locales', 'has an unknown locale');
            }
            if (! in_array($locale, $clean, true)) {
                $clean[] = $locale;
            }
        }

        return $clean;
    }

    private static function isKnown(mixed $value, array $allowed): bool
    {
        return is_string($value) && in_array($value, $allowed, true);
    }

    private static function reject(string $field, string $detail): InvalidBannerException
    {
        return InvalidBannerException::make('rules.'.$field, InvalidBannerException::REASON_INVALID_RULES, $detail);
    }
}
