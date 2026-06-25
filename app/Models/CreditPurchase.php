<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CreditPurchase — one INBOUND PAYMENT record on the PLATFORM-REVENUE rail (the
 * merchant pays the platform to top up). Tenant-owned (BelongsToAccount): a row is
 * always read under its owning account, so account B can never see account A's
 * purchases.
 *
 * Kept strictly separate from CreditLedger (the merchant's spend/grant truth). A paid
 * purchase links 1:1 to the single `purchase` ledger row it produced via ledger_id —
 * that link is written in the same transaction as the ledger row, and is the proof the
 * purchase reached the ledger EXACTLY ONCE.
 *
 * The status machine mirrors the provider: pending -> paid | failed | refunded. Only
 * the webhook moves it to paid (and only then is ledger_id set).
 */
class CreditPurchase extends Model
{
    /** @use HasFactory<\Database\Factories\CreditPurchaseFactory> */
    use BelongsToAccount, HasFactory;

    // === CONSTANTS ===
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';
    // The provider-confirmed amount did not match what we recorded on initiate. NO ledger
    // row is written; the row is parked for manual review (never silently credited).
    public const STATUS_AMOUNT_MISMATCH = 'amount_mismatch';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_FAILED,
        self::STATUS_REFUNDED,
        self::STATUS_AMOUNT_MISMATCH,
    ];

    public const PROVIDER_PAYPLUS = 'payplus';

    // account_id is stamped by BelongsToAccount; provider/ref/amounts/key are set by
    // the purchase flow, never from arbitrary request input.
    protected $fillable = [
        'provider',
        'provider_ref',
        'amount_usd',
        'credits_micro_usd',
        'currency',
        'status',
        'ledger_id',
        'idempotency_key',
        'paid_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'currency' => 'USD',
    ];

    protected function casts(): array
    {
        return [
            'amount_usd' => 'decimal:2',
            'credits_micro_usd' => 'integer',
            'ledger_id' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** The single `purchase` ledger row this paid purchase produced (1:1, nullable until paid). */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(CreditLedger::class, 'ledger_id');
    }

    /** True once the webhook has credited the account (ledger_id set, status paid). */
    public function isCredited(): bool
    {
        return $this->ledger_id !== null;
    }
}
