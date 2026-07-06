<?php

namespace App\Domain\Sites;

use App\Domain\Activity\ActivityRecorder;
use App\Models\ActivityEvent;
use App\Models\Site;

/**
 * SiteSettingsService — the validated writer of a site's privacy / retention / gallery
 * settings (the merchant "settings" screen). Writes ONLY:
 *   retention_days, privacy_config, gallery_settings, free_generations_before_signup.
 *
 * VALIDATE-THEN-PERSIST: the whole patch is validated first; an out-of-range value throws
 * a typed InvalidSiteSettingsException and NOTHING is written (no partial save). The
 * allowed ranges:
 *   - retention_days ∈ {7, 30, 90} or null (the until-manual-delete sentinel).
 *   - free_generations_before_signup ∈ {0, a positive int} or null (signup never required).
 *   - privacy_config / gallery_settings are JSON objects (associative arrays).
 *
 * Only the four whitelisted keys are touched (forceFill of the exact column set), so a
 * settings save can never reach site_key, widget_secret, allowed_origins, etc. Must run
 * inside the site's bound tenant. Records a site_settings_updated trace.
 */
final class SiteSettingsService
{
    // === CONSTANTS ===
    // The exact, only columns this service may write.
    public const KEY_RETENTION_DAYS = 'retention_days';

    public const KEY_PRIVACY_CONFIG = 'privacy_config';

    public const KEY_GALLERY_SETTINGS = 'gallery_settings';

    public const KEY_FREE_GENERATIONS = 'free_generations_before_signup';

    public const KEY_WIDGET_APPEARANCE = 'widget_appearance';

    public const KEY_CLUB_CONFIG = 'club_config';

    private const WRITABLE_KEYS = [
        self::KEY_RETENTION_DAYS,
        self::KEY_PRIVACY_CONFIG,
        self::KEY_GALLERY_SETTINGS,
        self::KEY_FREE_GENERATIONS,
        self::KEY_WIDGET_APPEARANCE,
        self::KEY_CLUB_CONFIG,
    ];

    public function __construct(
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Apply a validated settings patch to a site. Only the four whitelisted keys present
     * in $patch are changed; absent keys are left untouched. Throws (and writes nothing)
     * on any out-of-range value.
     *
     * @param  array<string,mixed>  $patch
     */
    public function update(Site $site, array $patch): Site
    {
        $changes = $this->validate($patch);

        if ($changes !== []) {
            $site->forceFill($changes)->save();

            $this->activity->record(
                kind: ActivityEvent::KIND_SITE_SETTINGS_UPDATED,
                subject: $site,
                details: ['fields' => array_keys($changes)],
                siteId: $site->getKey(),
                actor: ActivityEvent::ACTOR_MERCHANT,
            );
        }

        return $site;
    }

    /**
     * Validate the patch and return the sanitized column => value changes (only the
     * whitelisted keys that were actually present). Throws before any write on a bad value.
     *
     * @param  array<string,mixed>  $patch
     * @return array<string,mixed>
     */
    private function validate(array $patch): array
    {
        $changes = [];

        foreach (self::WRITABLE_KEYS as $key) {
            if (! array_key_exists($key, $patch)) {
                continue; // absent -> leave untouched
            }

            $changes[$key] = match ($key) {
                self::KEY_RETENTION_DAYS => $this->validRetentionDays($patch[$key]),
                self::KEY_FREE_GENERATIONS => $this->validFreeGenerations($patch[$key]),
                self::KEY_PRIVACY_CONFIG => $this->validObject(self::KEY_PRIVACY_CONFIG, $patch[$key]),
                self::KEY_GALLERY_SETTINGS => $this->validObject(self::KEY_GALLERY_SETTINGS, $patch[$key]),
                self::KEY_WIDGET_APPEARANCE => WidgetAppearance::sanitize($this->validObject(self::KEY_WIDGET_APPEARANCE, $patch[$key])),
                self::KEY_CLUB_CONFIG => ClubConfig::sanitize($this->validObject(self::KEY_CLUB_CONFIG, $patch[$key])),
            };
        }

        return $changes;
    }

    /** 7 / 30 / 90, or null (until manual delete). Anything else is rejected. */
    private function validRetentionDays(mixed $value): ?int
    {
        if ($value === Site::RETENTION_UNTIL_DELETE) {
            return Site::RETENTION_UNTIL_DELETE;
        }

        if (is_int($value) && in_array($value, Site::RETENTION_DAYS_ALLOWED, true)) {
            return $value;
        }

        throw InvalidSiteSettingsException::retentionDays($value);
    }

    /** 0 or a positive int, or null (signup never required). Negatives/non-ints rejected. */
    private function validFreeGenerations(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) && $value >= 0) {
            return $value;
        }

        throw InvalidSiteSettingsException::freeGenerations($value);
    }

    /**
     * A JSON config column must be an associative array (object), never a scalar/list.
     *
     * @return array<string,mixed>
     */
    private function validObject(string $field, mixed $value): array
    {
        // An empty array is a valid empty object; a list (sequential keys) is not.
        if (! is_array($value) || array_is_list($value) && $value !== []) {
            throw InvalidSiteSettingsException::notAnObject($field);
        }

        return $value;
    }
}
