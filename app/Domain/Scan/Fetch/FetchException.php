<?php

namespace App\Domain\Scan\Fetch;

use App\Domain\Scan\ScanConstants;
use RuntimeException;

/**
 * FetchException — a typed, MERCHANT-FACING fetch failure.
 *
 * A scan never 500s and never silently returns an empty page. When a fetch is
 * refused (robots / invalid url / SSRF) or fails (bot-block / timeout / empty
 * render), we throw this with a stable reason code and a clear merchant message
 * plus suggestManual so the orchestrator can transition the product to failed and
 * the merchant gets the manual-entry path.
 */
final class FetchException extends RuntimeException
{
    // === CONSTANTS ===
    // Stable, merchant-facing copy per reason. The reason code is machine-stable;
    // the message is what the merchant reads in the review UI.
    private const MESSAGES = [
        ScanConstants::FAIL_INVALID_URL => 'That does not look like a valid public product URL. Please check the link and try again.',
        ScanConstants::FAIL_ROBOTS_BLOCKED => 'This site asks automated tools not to read this page. Please enter the product details manually.',
        ScanConstants::FAIL_BOT_BLOCKED => 'This page blocks automated scanning. Please enter the product details manually.',
        ScanConstants::FAIL_RENDER_EMPTY => 'We could not read any product content from this page. Please enter the product details manually.',
        ScanConstants::FAIL_TIMEOUT => 'This page took too long to load. Please try again, or enter the product details manually.',
        ScanConstants::FAIL_TOO_LARGE => 'This page is too large to scan automatically. Please enter the product details manually.',
        ScanConstants::FAIL_HTTP_ERROR => 'We could not reach this page. Please check the link, or enter the product details manually.',
        ScanConstants::FAIL_RENDER_DISABLED => 'This page needs a browser to load and automated rendering is currently unavailable. Please enter the product details manually.',
    ];

    private const DEFAULT_MESSAGE = 'We could not scan this page automatically. Please enter the product details manually.';

    public function __construct(
        public readonly string $reason,
        public readonly bool $suggestManual = true,
        ?string $message = null,
    ) {
        parent::__construct($message ?? self::MESSAGES[$reason] ?? self::DEFAULT_MESSAGE);
    }

    /** Refused before any network call (invalid url / robots / SSRF). */
    public static function refused(string $reason): self
    {
        return new self($reason, suggestManual: true);
    }

    /** Failed during/after a network call (bot-block / timeout / empty render). */
    public static function failed(string $reason): self
    {
        return new self($reason, suggestManual: true);
    }

    /** The merchant-facing message (same as getMessage(), named for intent). */
    public function merchantMessage(): string
    {
        return $this->getMessage();
    }
}
