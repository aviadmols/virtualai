<?php

namespace App\Domain\Ai;

use RuntimeException;
use Throwable;

/**
 * OpenRouterException — a TERMINAL, classified OpenRouter failure.
 *
 * Every failure surfaced to laravel-backend carries one of the fixed, documented
 * error codes (the CODE_* set). The caller acts on the code: release the
 * reservation, never bill a failed try-on, surface the right message. A clean
 * code is the whole point of owning the fallback ourselves — it lets the
 * reservation release deterministically (debit only on success, release on
 * failure, the money-path law).
 *
 * The message NEVER carries the bearer key or a full image payload; it carries
 * the provider status + provider error code + the classification, masked.
 */
final class OpenRouterException extends RuntimeException
{
    // === CONSTANTS ===
    // The call exceeded OPENROUTER_TIMEOUT.
    public const CODE_MODEL_TIMEOUT = 'model_timeout';
    // 429 after bounded backoff + fallback.
    public const CODE_RATE_LIMITED = 'rate_limited';
    // 5xx after retry + fallback.
    public const CODE_PROVIDER_OUTAGE = 'provider_outage';
    // The model declined (safety / content filter).
    public const CODE_MODEL_REFUSED = 'model_refused';
    // product_scan returned non-schema output after the repair pass.
    public const CODE_INVALID_JSON = 'invalid_json';
    // The response carried no usable image bytes.
    public const CODE_INVALID_IMAGE = 'invalid_image';
    // We built a malformed request (our bug) — do NOT retry blindly.
    public const CODE_BAD_REQUEST = 'bad_request';

    public const CODES = [
        self::CODE_MODEL_TIMEOUT,
        self::CODE_RATE_LIMITED,
        self::CODE_PROVIDER_OUTAGE,
        self::CODE_MODEL_REFUSED,
        self::CODE_INVALID_JSON,
        self::CODE_INVALID_IMAGE,
        self::CODE_BAD_REQUEST,
    ];

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $providerStatus = null,
        public readonly ?string $modelUsed = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function make(
        string $errorCode,
        string $message,
        ?int $providerStatus = null,
        ?string $modelUsed = null,
        ?Throwable $previous = null,
    ): self {
        return new self($errorCode, $message, $providerStatus, $modelUsed, $previous);
    }

    /** True for a transient failure worth a bounded retry / fallback. */
    public function isTransient(): bool
    {
        return in_array($this->errorCode, [
            self::CODE_MODEL_TIMEOUT,
            self::CODE_RATE_LIMITED,
            self::CODE_PROVIDER_OUTAGE,
        ], true);
    }
}
