<?php

namespace App\Domain\Sites;

use RuntimeException;

/**
 * InvalidSiteSettingsException — a privacy/retention settings write was rejected because
 * a value is out of its allowed range. A typed, expected validation outcome (the admin UI
 * surfaces it as a field error), distinct from a 500. NOTHING is persisted when this is
 * thrown — the service validates the whole patch BEFORE any write.
 *
 * Carries the offending field + a stable reason the UI maps to a message.
 */
final class InvalidSiteSettingsException extends RuntimeException
{
    // === CONSTANTS ===
    public const REASON_RETENTION_DAYS = 'invalid_retention_days';

    public const REASON_FREE_GENERATIONS = 'invalid_free_generations_before_signup';

    public const REASON_NOT_AN_OBJECT = 'invalid_json_object';

    public function __construct(
        public readonly string $field,
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function retentionDays(mixed $value): self
    {
        return new self(
            'retention_days',
            self::REASON_RETENTION_DAYS,
            'retention_days must be one of 7, 30, 90, or null (until manual delete); got: '.var_export($value, true),
        );
    }

    public static function freeGenerations(mixed $value): self
    {
        return new self(
            'free_generations_before_signup',
            self::REASON_FREE_GENERATIONS,
            'free_generations_before_signup must be 0, a positive integer, or null (signup never required); got: '.var_export($value, true),
        );
    }

    public static function notAnObject(string $field): self
    {
        return new self(
            $field,
            self::REASON_NOT_AN_OBJECT,
            $field.' must be a JSON object (associative array).',
        );
    }
}
