<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * EndUser — the lead / "Tray On user". Tenant-owned (BelongsToAccount) +
 * site-scoped. One row per (site_id, anon_token) so the free-tries counter
 * survives navigation between a site's PDPs.
 *
 * The lead funnel is FORWARD-ONLY: new -> generated -> added_to_cart -> purchased,
 * and any state -> incomplete. A guarded transitionTo() rejects a backwards move
 * (purchased is terminal-best). generations_used is the counter the LeadGate reads
 * against the site's free_generations_before_signup.
 *
 * This is the END USER, the shopper. It is independent of the merchant's credit
 * balance — the LeadGate (this funnel) and the CreditGate (merchant credits) never
 * collapse into one.
 */
class EndUser extends Model
{
    /** @use HasFactory<\Database\Factories\EndUserFactory> */
    use BelongsToAccount, HasFactory;

    // === CONSTANTS ===
    // The lead funnel states.
    public const STATUS_NEW = 'new';
    public const STATUS_GENERATED = 'generated';
    public const STATUS_ADDED_TO_CART = 'added_to_cart';
    public const STATUS_PURCHASED = 'purchased';
    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_GENERATED,
        self::STATUS_ADDED_TO_CART,
        self::STATUS_PURCHASED,
        self::STATUS_INCOMPLETE,
    ];

    // Forward-only transitions. The funnel only advances; any state can drop to
    // incomplete (abandoned). purchased is terminal-best (only -> incomplete).
    public const TRANSITIONS = [
        self::STATUS_NEW => [self::STATUS_GENERATED, self::STATUS_INCOMPLETE],
        self::STATUS_GENERATED => [self::STATUS_ADDED_TO_CART, self::STATUS_INCOMPLETE],
        self::STATUS_ADDED_TO_CART => [self::STATUS_PURCHASED, self::STATUS_INCOMPLETE],
        self::STATUS_PURCHASED => [self::STATUS_INCOMPLETE],
        self::STATUS_INCOMPLETE => [],
    ];

    private const ILLEGAL_TRANSITION_MESSAGE = 'Illegal end-user status transition %s -> %s (end_user #%s).';

    // anon_token / status / generations_used are set by the lead pipeline, not from
    // arbitrary request input. account_id is stamped by BelongsToAccount.
    protected $fillable = [
        'site_id',
        'anon_token',
        'full_name',
        'email',
        'phone',
        'photo_consent_at',
        'marketing_consent',
        'marketing_consent_at',
        'status',
        'generations_used',
        'registered_at',
        'verified_at',
        'source',
        'utm',
        'last_seen_at',
    ];

    // marketing_consent DEFAULTS OFF (GDPR): a new lead is never opted in. Never
    // pre-check it; never imply it from the use-my-photo consent.
    protected $attributes = [
        'status' => self::STATUS_NEW,
        'generations_used' => 0,
        'marketing_consent' => false,
    ];

    protected function casts(): array
    {
        return [
            'generations_used' => 'integer',
            'photo_consent_at' => 'datetime',
            'marketing_consent' => 'boolean',
            'marketing_consent_at' => 'datetime',
            'registered_at' => 'datetime',
            'verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'utm' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** True once the end user has signed up (lead captured). */
    public function isRegistered(): bool
    {
        return $this->registered_at !== null;
    }

    /**
     * True once the shopper verified email ownership via the one-time code — a
     * Customer-Club member. Independent of isRegistered() (a lead-form signup)
     * and of the credit/lead gates.
     */
    public function isClubMember(): bool
    {
        return $this->verified_at !== null;
    }

    /** True once the shopper gave the use-my-photo consent (the basis to generate). */
    public function hasPhotoConsent(): bool
    {
        return $this->photo_consent_at !== null;
    }

    /** True only when the lead explicitly opted in to marketing (defaults false). */
    public function hasMarketingConsent(): bool
    {
        return (bool) $this->marketing_consent;
    }

    /**
     * Guarded forward-only status move. Only canonical transitions are legal; a
     * backwards or skipping move throws so the funnel can never regress.
     */
    public function transitionTo(string $next): void
    {
        $current = $this->status ?? self::STATUS_NEW;

        if ($current === $next) {
            return; // a no-op move is harmless (idempotent re-mark)
        }

        $allowed = self::TRANSITIONS[$current] ?? [];

        if (! in_array($next, $allowed, true)) {
            throw new RuntimeException(sprintf(
                self::ILLEGAL_TRANSITION_MESSAGE,
                $current,
                $next,
                $this->getKey() ?? 'new',
            ));
        }

        $this->status = $next;
        $this->save();
    }
}
