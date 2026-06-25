<?php

namespace App\Http\Widget;

use App\Domain\Ai\AiOperationResolver;
use App\Domain\Credits\CreditDenied;
use App\Domain\Credits\CreditGate;
use App\Domain\Credits\GateDenied;
use App\Domain\Credits\UsageGate;
use App\Domain\Generation\CreditEstimator;
use App\Domain\Leads\LeadDecision;
use App\Domain\Leads\LeadGate;
use App\Models\Account;
use App\Models\EndUser;
use App\Models\Site;

/**
 * WidgetGateService — runs the three INDEPENDENT widget gates as a fast pre-dispatch
 * check at the HTTP boundary, so a denial NEVER creates a generation, dispatches a job,
 * calls OpenRouter, or charges. Defense in depth: GenerateTryOnJob re-runs LeadGate +
 * CreditGate authoritatively on the worker (the row-locked money path), so this HTTP
 * check is a fast, friendly short-circuit, not the only guard.
 *
 * The three gates stay independent (they never collapse into one):
 *  - UsageGate  : per-(account,site) + per-account generations/min cap -> typed 429.
 *  - LeadGate   : end-user free tries / post-signup grant -> typed "signup required".
 *  - CreditGate : merchant has credits -> typed "out of credits".
 *
 * Precedence on the COMBINED outcome (flows.md gate-precedence decision):
 *   1. rate cap first (cheapest to reject, protects the system);
 *   2. then the CREDIT wall — if the merchant cannot pay, prompting signup is a dead end,
 *      so out-of-credits is shown even when the lead gate would also block;
 *   3. then the LEAD gate (signup-required) when the merchant CAN pay.
 * Each gate is still evaluated on its own terms; only the surfaced screen is ordered.
 */
final class WidgetGateService
{
    // === CONSTANTS ===
    private const OPERATION_KEY = 'try_on_generation';

    public function __construct(
        private readonly AiOperationResolver $resolver,
        private readonly CreditEstimator $estimator,
    ) {}

    /**
     * Evaluate all three gates for a try-on on this (account, site, end user). Returns a
     * typed combined decision; never throws for a denial.
     */
    public function check(Account $account, Site $site, EndUser $endUser): WidgetGateDecision
    {
        // 1) Usage / rate cap (per (account,site) + per account). Consumes a token on pass.
        $usage = UsageGate::for($account)->checkWidgetGenerate($site);

        if ($usage->denied() && $usage->isRateLimited()) {
            return WidgetGateDecision::deny(
                WidgetGateDecision::REASON_RATE_LIMITED,
                $usage->retryAfterSeconds,
            );
        }

        // 2) Credit wall (merchant). Estimate the max charge from the DB-managed AI bag
        // (no OpenRouter call) × the resolved markup — never a literal at this call site.
        $estimate = $this->estimateMicroUsd($site);
        $credit = CreditGate::for($account)->assertCanSpend($estimate);

        if ($credit->denied()) {
            return WidgetGateDecision::deny($this->creditReason($credit));
        }

        // 3) Lead gate (end user). Independent of the credit gate above.
        $lead = LeadGate::for($site, $endUser)->assertCanTry();

        if ($lead->denied()) {
            return WidgetGateDecision::deny($this->leadReason($lead));
        }

        return WidgetGateDecision::allow($lead->freeRemaining);
    }

    /** The lead state for the bootstrap config (no token consumed, no side effects). */
    public function leadState(Site $site, EndUser $endUser): LeadDecision
    {
        return LeadGate::for($site, $endUser)->assertCanTry();
    }

    /** The max selling value (micro-USD) to reserve, from the DB-managed AI config. */
    private function estimateMicroUsd(Site $site): int
    {
        $config = $this->resolver->for(self::OPERATION_KEY, $site);

        return $this->estimator->estimateMicroUsd($config);
    }

    private function creditReason(CreditDenied $denied): string
    {
        return $denied->reason === CreditDenied::REASON_ACCOUNT_INACTIVE
            ? WidgetGateDecision::REASON_ACCOUNT_INACTIVE
            : WidgetGateDecision::REASON_INSUFFICIENT_CREDITS;
    }

    private function leadReason(LeadDecision $decision): string
    {
        return $decision->reason === LeadDecision::REASON_POST_SIGNUP_LIMIT
            ? WidgetGateDecision::REASON_POST_SIGNUP_LIMIT
            : WidgetGateDecision::REASON_SIGNUP_REQUIRED;
    }
}
