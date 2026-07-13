<?php

namespace App\Domain\Ai;

/**
 * KlingErrorCodes — classify a Kling failure by its ENVELOPE code, not just the HTTP status.
 *
 * Kling answers 429 for three different things, and only one of them is worth retrying:
 *   1302 rate limit exceeded        -> transient (retry)
 *   1303 concurrency limit reached  -> transient (retry, Kling asks for >= 1s backoff)
 *   1101 account in arrears         -> TERMINAL. Retrying can never succeed; it just burns time.
 *   1102 resource pack exhausted    -> TERMINAL.
 *   1100 abnormal account status    -> TERMINAL.
 * Treating those as "transient rate limits" is how a dead account turns into a retry storm.
 *
 * 1301 (content security) is a REFUSAL, not our bug; 1203 is a missing model; 5xx is an outage.
 */
final class KlingErrorCodes
{
    // === CONSTANTS ===
    // Terminal account/authorization states — never retry, never fall back into a loop.
    public const ACCOUNT_BLOCKED = [
        1100, // abnormal account status
        1101, // account in arrears
        1102, // resource pack exhausted or expired
        1103, // unauthorized for this API / model
    ];

    // The model (or resource) does not exist — a configuration error, not a transient one.
    public const NOT_FOUND = 1203;

    // The prompt/image tripped Kling's content policy.
    public const CONTENT_BLOCKED = 1301;

    private const HTTP_RATE_LIMITED = 429;

    private const HTTP_SERVER_MIN = 500;

    /** The OpenRouterException CODE_* this failure maps onto. */
    public static function classify(int $httpStatus, mixed $envelopeCode = null): string
    {
        $code = is_numeric($envelopeCode) ? (int) $envelopeCode : 0;

        if (in_array($code, self::ACCOUNT_BLOCKED, true) || $code === self::NOT_FOUND) {
            return OpenRouterException::CODE_BAD_REQUEST; // terminal — a retry cannot fix it
        }

        if ($code === self::CONTENT_BLOCKED) {
            return OpenRouterException::CODE_MODEL_REFUSED;
        }

        if ($httpStatus === self::HTTP_RATE_LIMITED) {
            return OpenRouterException::CODE_RATE_LIMITED;
        }

        if ($httpStatus >= self::HTTP_SERVER_MIN) {
            return OpenRouterException::CODE_PROVIDER_OUTAGE;
        }

        return OpenRouterException::CODE_BAD_REQUEST;
    }
}
