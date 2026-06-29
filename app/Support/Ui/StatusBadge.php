<?php

namespace App\Support\Ui;

/**
 * The single source of truth for status → badge tone + i18n key.
 *
 * Tones and keys come from docs/ux/design-tokens.md §5 (the canonical badge map)
 * which maps onto the ARCHITECTURE.md state machines. NEVER recompute a status
 * colour inline (a Filament ->color() closure or a Blade ternary). A status not
 * in TONES is a backend/scanner contract drift → escalate to laravel-backend /
 * pdp-scanner, never paper over it with a default grey.
 *
 * Tone vocabulary (maps to .to-badge--{tone} + the colour tokens):
 *   success → --to-success | warn → --to-warn | danger → --to-danger
 *   info → --to-info | neutral → --to-ink-muted | ink → --to-ink (emphasis neutral)
 */
final class StatusBadge
{
    // === CONSTANTS ===

    /** Fallback tone for an unknown status (also signals a contract drift). */
    public const FALLBACK_TONE = 'neutral';

    /**
     * Domain status (prefixed by its machine) → tone. The prefixed form is the
     * lookup key so the same bare word in two machines never collides
     * (e.g. ledger "charge" vs a hypothetical generation step).
     */
    public const TONES = [
        // generation.status (try-on attempt)
        'generation.pending' => 'warn',
        'generation.processing' => 'warn',
        'generation.succeeded' => 'success',
        'generation.failed' => 'danger',
        'generation.cancelled' => 'neutral',

        // credit_ledger.type (append-only)
        'ledger.grant' => 'success',
        'ledger.purchase' => 'success',
        'ledger.charge' => 'ink',
        'ledger.refund' => 'warn',
        'ledger.adjustment' => 'info',

        // account credit level (derived banner state)
        'credit.low' => 'warn',
        'credit.empty' => 'danger',

        // end_user.status (lead funnel)
        'lead.new' => 'neutral',
        'lead.generated' => 'info',
        'lead.added_to_cart' => 'ink',
        'lead.purchased' => 'success',
        'lead.incomplete' => 'danger',

        // scan confidence (pdp-scanner contract — its own scale, not a status)
        'scan.high' => 'success',
        'scan.medium' => 'warn',
        'scan.low' => 'danger',
        'scan.none' => 'neutral',
    ];

    /** Map a bare status value within a machine to its prefixed lookup key. */
    public const MACHINES = [
        'generation' => 'generation',
        'ledger' => 'ledger',
        'credit' => 'credit',
        'lead' => 'lead',
        'scan' => 'scan',
    ];

    /** i18n key prefix per machine — the catalog nests under these. */
    public const I18N_PREFIX = [
        'generation' => 'status.generation.',
        'ledger' => 'status.ledger.',
        'credit' => 'status.credit.',
        'lead' => 'status.lead.',
        'scan' => 'scan.confidence.',
    ];

    /**
     * Resolve the tone for a status within a machine.
     * Returns FALLBACK_TONE for an unknown status (a drift signal — log/escalate).
     */
    public static function tone(string $machine, string $status): string
    {
        return self::TONES[$machine . '.' . $status] ?? self::FALLBACK_TONE;
    }

    /**
     * The i18n key for a status within a machine. Scan confidence uses a slightly
     * different catalog nesting (scan.confidence.*), handled by I18N_PREFIX.
     */
    public static function label(string $machine, string $status): string
    {
        $prefix = self::I18N_PREFIX[$machine] ?? ('status.' . $machine . '.');

        return $prefix . $status;
    }

    /** Whether a status is known (false = a contract drift to escalate). */
    public static function isKnown(string $machine, string $status): bool
    {
        return isset(self::TONES[$machine . '.' . $status]);
    }
}
