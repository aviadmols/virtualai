<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * PlatformTestMail — a plain "your SMTP works" message the Super-Admin sends from the
 * Settings page to verify the configured transport, without waiting for a real club
 * signup. Developer-authored blade (no merchant/admin text, no strtr needed). Sent
 * synchronously through the DB-bound SMTP config (PlatformMailConfig::apply()).
 */
final class PlatformTestMail extends Mailable
{
    use Queueable, SerializesModels;

    // === CONSTANTS ===
    private const VIEW = 'emails.platform.test';

    private const SUBJECT_KEY = 'platform.settings.smtp.test_mail_subject';

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __(self::SUBJECT_KEY),
        );
    }

    public function content(): Content
    {
        return new Content(view: self::VIEW);
    }
}
