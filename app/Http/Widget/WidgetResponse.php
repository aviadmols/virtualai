<?php

namespace App\Http\Widget;

use Illuminate\Http\JsonResponse;

/**
 * WidgetResponse — the single JSON envelope shape for the widget API.
 *
 * Every response is typed JSON (never a 500/HTML to the widget). Three shapes:
 *  - ok(data)            : 200/2xx, { ok:true, ...data }
 *  - error(code, msg, s) : a typed 4xx, { ok:false, error:{ code, message } }
 *  - gate(reason, …)     : a typed business denial, { ok:false, blocked:true, reason, … }
 *
 * Only whitelisted, secret-free fields are ever serialized here — the widget_secret and
 * the OpenRouter key are never assembled into a payload (the Site model also $hidden's
 * widget_secret, so even an accidental ->toArray() cannot leak it).
 */
final class WidgetResponse
{
    // === CONSTANTS ===
    public const STATUS_OK = 200;
    public const STATUS_CREATED = 201;
    public const STATUS_BAD_REQUEST = 400;
    public const STATUS_UNAUTHORIZED = 401;
    public const STATUS_FORBIDDEN = 403;
    public const STATUS_NOT_FOUND = 404;
    public const STATUS_UNPROCESSABLE = 422;
    public const STATUS_TOO_MANY = 429;

    // Gate-denial -> HTTP status mapping. A credit/usage wall is a business outcome the
    // widget renders gracefully; a 402 ("payment required") flags out-of-credits, 429 a
    // rate cap, 200 a still-actionable signup prompt.
    public const STATUS_PAYMENT_REQUIRED = 402;

    /** A successful response: { ok:true, ...data }. */
    public static function ok(array $data = [], int $status = self::STATUS_OK): JsonResponse
    {
        return response()->json(['ok' => true] + $data, $status);
    }

    /** A typed error: { ok:false, error:{ code, message } } + optional details. */
    public static function error(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => ['code' => $code] + ['message' => $message] + $extra,
        ], $status);
    }

    /**
     * A typed gate denial: a normal business block the widget renders a screen for.
     * NOT an error envelope and NEVER a 500. Carries the stable reason code + any
     * actionable hint (e.g. retry_after seconds, free_remaining).
     */
    public static function gate(string $reason, string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'blocked' => true,
            'reason' => $reason,
            'message' => $message,
        ] + $extra, $status);
    }
}
