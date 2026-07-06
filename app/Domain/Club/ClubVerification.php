<?php

namespace App\Domain\Club;

use App\Domain\Platform\PlatformMailConfig;
use App\Mail\ClubVerificationCodeMail;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Mail;

/**
 * ClubVerification — issue + verify the Customer-Club email one-time code.
 *
 * The code store is the CACHE (auto-expiring), not a model: a 6-digit numeric code
 * with a bounded attempt counter, keyed per (site_id, anon_token, email). A cache
 * entry disappears on TTL, so an expired code needs no cleanup job. A separate
 * short-lived throttle key rate-limits how often ONE (site, token, email) may
 * REQUEST a code (anti-spam), independent of the widget's per-account rate limiter.
 *
 * Tenant note: the store is keyed by the SERVER-resolved site_id (from the bound
 * WidgetContext), never a client id — so site A can never read/verify site B's
 * pending code. No DB row, so no BelongsToAccount surface here; isolation is the
 * key prefix (site_id) + the caller passing the bound site.
 */
final class ClubVerification
{
    // === CONSTANTS ===
    // Code shape: a zero-padded 6-digit numeric string ("000000".."999999").
    private const CODE_DIGITS = 6;

    private const CODE_MIN = 0;

    private const CODE_MAX = 999999;

    // How long a freshly issued code stays valid.
    private const CODE_TTL_SECONDS = 600;          // 10 minutes

    // Wrong-attempt ceiling before the code is burned (a new one must be requested).
    private const MAX_ATTEMPTS = 5;

    // Anti-spam: a given (site, token, email) may request a new code at most once
    // per this window. A second request inside it is throttled (no email sent).
    private const REQUEST_THROTTLE_SECONDS = 60;

    // Cache key namespaces (kept distinct so a code and its throttle never collide).
    private const CODE_KEY_PREFIX = 'club_otp_code';

    private const THROTTLE_KEY_PREFIX = 'club_otp_throttle';

    // The pending-code record shape (cache value).
    private const FIELD_CODE = 'code';

    private const FIELD_ATTEMPTS = 'attempts';

    public function __construct(
        private readonly Cache $cache,
        private readonly PlatformMailConfig $mailConfig,
    ) {}

    /** The code TTL in seconds — exposed so the endpoint/UI can report expiry. */
    public static function codeTtlSeconds(): int
    {
        return self::CODE_TTL_SECONDS;
    }

    /**
     * Issue a fresh code for (siteId, anonToken, email) and email it — unless the
     * requester is inside the per-email throttle window, in which case NO new code is
     * issued and NO email is sent. Returns true when a code was sent, false when
     * throttled. Idempotent-ish: a fresh request outside the window overwrites any
     * prior pending code (resets attempts).
     */
    public function issue(int $siteId, string $anonToken, string $email): bool
    {
        $email = $this->normalizeEmail($email);
        $throttleKey = $this->throttleKey($siteId, $anonToken, $email);

        // add() is atomic put-if-absent: the first request in the window wins and
        // sets the throttle marker; a racing/repeat request returns false (throttled).
        if (! $this->cache->add($throttleKey, true, self::REQUEST_THROTTLE_SECONDS)) {
            return false;
        }

        $code = $this->generateCode();

        $this->cache->put(
            $this->codeKey($siteId, $anonToken, $email),
            [self::FIELD_CODE => $code, self::FIELD_ATTEMPTS => 0],
            self::CODE_TTL_SECONDS,
        );

        // Fold the Super-Admin-managed SMTP config into the live mailer on-demand —
        // only this send path reads it, so the widget hot path stays DB-query-free.
        $this->mailConfig->apply();

        Mail::to($email)->send(new ClubVerificationCodeMail($code, self::CODE_TTL_SECONDS));

        return true;
    }

    /**
     * Verify a submitted code. On a match: consume the pending code (single-use) and
     * return Verified. On a miss: increment attempts; return Locked once the cap is
     * reached (the code is then burned), else Invalid. Expired returns when there is
     * no pending code (TTL passed or never issued).
     */
    public function verify(int $siteId, string $anonToken, string $email, string $submitted): ClubVerifyResult
    {
        $email = $this->normalizeEmail($email);
        $codeKey = $this->codeKey($siteId, $anonToken, $email);

        $record = $this->cache->get($codeKey);

        if (! is_array($record) || ! isset($record[self::FIELD_CODE])) {
            return ClubVerifyResult::Expired;
        }

        $submitted = trim($submitted);

        // Constant-time compare so a wrong code leaks no timing signal.
        if (hash_equals((string) $record[self::FIELD_CODE], $submitted)) {
            $this->cache->forget($codeKey);      // single-use — consume on success

            return ClubVerifyResult::Verified;
        }

        $attempts = (int) ($record[self::FIELD_ATTEMPTS] ?? 0) + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->cache->forget($codeKey);      // burn the code after too many misses

            return ClubVerifyResult::Locked;
        }

        // Re-store with the bumped attempt count; keep the ORIGINAL TTL window so a
        // wrong guess can't extend the code's life.
        $record[self::FIELD_ATTEMPTS] = $attempts;
        $this->cache->put($codeKey, $record, self::CODE_TTL_SECONDS);

        return ClubVerifyResult::Invalid;
    }

    /** A zero-padded 6-digit numeric code. */
    private function generateCode(): string
    {
        return str_pad((string) random_int(self::CODE_MIN, self::CODE_MAX), self::CODE_DIGITS, '0', STR_PAD_LEFT);
    }

    /** Lower-cased, trimmed email so the key is stable regardless of input casing. */
    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function codeKey(int $siteId, string $anonToken, string $email): string
    {
        return self::CODE_KEY_PREFIX.':'.$siteId.':'.sha1($anonToken.'|'.$email);
    }

    private function throttleKey(int $siteId, string $anonToken, string $email): string
    {
        return self::THROTTLE_KEY_PREFIX.':'.$siteId.':'.sha1($anonToken.'|'.$email);
    }
}
