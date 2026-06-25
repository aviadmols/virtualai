<?php

namespace App\Observers;

use App\Domain\Credits\CreditLedgerService;
use App\Domain\Credits\CreditMath;
use App\Domain\Credits\IdempotencyKey;
use App\Models\Account;
use App\Models\ActivityEvent;
use App\Support\Tenant;

/**
 * AccountObserver — gives every new account its single opening $5 grant.
 *
 * The grant goes through the LEDGER WRITER (never a bare balance column write), so
 * the opening credit IS a grant row and the balance reflects it from row one. It
 * is idempotent on the deterministic opening-grant key — one opening grant per
 * account, ever, even if the observer somehow fires twice.
 *
 * The grant amount comes from config (CREDIT_OPENING_GRANT_USD), never a literal.
 * The grant runs with the new account bound as the tenant so the ledger row's
 * account_id stamps correctly under BelongsToAccount.
 */
final class AccountObserver
{
    // === CONSTANTS ===
    private const OPENING_GRANT_CONFIG_KEY = 'trayon.pricing.opening_grant_usd';
    private const OPENING_GRANT_DESCRIPTION = 'Opening credit for a new account.';

    public function __construct(
        private readonly CreditLedgerService $ledger,
    ) {}

    /** After an account is created, write its one opening grant (idempotent). */
    public function created(Account $account): void
    {
        $grantUsd = (float) config(self::OPENING_GRANT_CONFIG_KEY);

        if ($grantUsd <= 0) {
            return; // a zero/disabled opening grant writes no row
        }

        $amountMicroUsd = CreditMath::usdToMicro($grantUsd);
        $key = IdempotencyKey::forGrant($account->getKey(), IdempotencyKey::OPENING_GRANT_SLUG);

        // Bind the new account so the ledger row stamps account_id correctly.
        Tenant::run($account, function () use ($account, $amountMicroUsd, $key): void {
            $this->ledger->grant(
                account: $account,
                amountMicroUsd: $amountMicroUsd,
                idempotencyKey: $key,
                description: self::OPENING_GRANT_DESCRIPTION,
                meta: ['source' => ActivityEvent::KIND_OPENING_GRANT],
                kind: ActivityEvent::KIND_OPENING_GRANT,
            );
        });
    }
}
