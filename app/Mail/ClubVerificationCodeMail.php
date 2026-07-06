<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ClubVerificationCodeMail — delivers the Customer-Club one-time code.
 *
 * A developer-authored blade view (never merchant/admin-edited text), so plain
 * blade `{{ }}` escaping of the scalar code + expiry is safe — no strtr needed
 * here. Uses the global MAIL_FROM_* sender. In dev MAIL_MAILER=log, so the code
 * lands in the log; prod needs a real SMTP transport (flagged in the report).
 */
final class ClubVerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    // === CONSTANTS ===
    private const VIEW = 'emails.club.verification-code';

    private const SUBJECT_KEY = 'club.mail.subject';

    // Minutes-until-expiry surfaced to the template (derived from the TTL seconds).
    private const SECONDS_PER_MINUTE = 60;

    public function __construct(
        public readonly string $code,
        public readonly int $ttlSeconds,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __(self::SUBJECT_KEY),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: self::VIEW,
            with: [
                'code' => $this->code,
                'minutes' => (int) ceil($this->ttlSeconds / self::SECONDS_PER_MINUTE),
            ],
        );
    }
}
