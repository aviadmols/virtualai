<?php

namespace App\Domain\Generation;

/**
 * GenerationFailureCode — the closed set of reasons a generation ends as failed.
 *
 * Two families: the two GATE denials (lead/credit — a typed, expected outcome, never
 * a 500), and the OpenRouter/storage failures (mapped from OpenRouterException codes).
 * The code is persisted on generations.failure_code and surfaced (mapped) to the
 * widget; it is the audit of WHY a try-on did not complete — and why no charge ran.
 */
final class GenerationFailureCode
{
    // === CONSTANTS ===
    // The two independent gates (both must pass; each denial is a typed result).
    public const SIGNUP_REQUIRED = 'signup_required';            // LeadGate: free tries exhausted, unregistered
    public const POST_SIGNUP_LIMIT = 'post_signup_limit_reached'; // LeadGate: registered, grant exhausted
    public const INSUFFICIENT_CREDITS = 'insufficient_credits';  // CreditGate: balance − reserved < estimate
    public const ACCOUNT_INACTIVE = 'account_inactive';          // CreditGate: suspended/closed account

    // The model/storage failures (the reservation is released; NO charge row).
    public const AI_CALL_FAILED = 'ai_call_failed';              // any classified OpenRouterException
    public const COST_UNAVAILABLE = 'cost_unavailable';          // no real cost came back — cannot charge honestly
    public const STORAGE_FAILED = 'storage_failed';              // result bytes could not be stored
    public const INTERNAL_ERROR = 'internal_error';              // an unexpected exception on the worker

    public const CODES = [
        self::SIGNUP_REQUIRED,
        self::POST_SIGNUP_LIMIT,
        self::INSUFFICIENT_CREDITS,
        self::ACCOUNT_INACTIVE,
        self::AI_CALL_FAILED,
        self::COST_UNAVAILABLE,
        self::STORAGE_FAILED,
        self::INTERNAL_ERROR,
    ];
}
