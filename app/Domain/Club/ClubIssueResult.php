<?php

namespace App\Domain\Club;

/**
 * ClubIssueResult — the typed outcome of a request-code (issue) call.
 *
 * Issuing a code is a business outcome, never an uncaught exception: whether the code
 * was sent, the requester was inside the anti-spam throttle window, or the mail transport
 * failed, the endpoint always returns typed JSON (never a 500). The stable `reason` string
 * is what the endpoint surfaces so the widget i18n catalog can localize it.
 */
enum ClubIssueResult: string
{
    // A fresh code was issued and the email was handed to the transport successfully.
    case Sent = 'sent';

    // Inside the per-email throttle window — no new code, no email (anti-spam).
    case Throttled = 'throttled';

    // The code was issued but the mail transport threw (misconfigured/unreachable SMTP).
    // The error is logged server-side; the shopper is told to try again, never a 500.
    case SendFailed = 'send_failed';

    public function wasSent(): bool
    {
        return $this === self::Sent;
    }
}
