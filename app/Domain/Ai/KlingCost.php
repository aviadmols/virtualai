<?php

namespace App\Domain\Ai;

use App\Domain\Credits\CreditMath;

/**
 * KlingCost — the ONE place Kling's REAL, per-task cost is read out of its own response.
 *
 * Kling was originally wired as a "flat-rate, no inline cost" provider. That is wrong: every
 * completed task carries what Kling actually billed —
 *   images: data.final_balance_deduction = { quota, list_price }   (a CASH account)
 *   video : billing[] = { charge_type: cash|unit, amount, package_type, list_price }
 * A resource-package account is billed in UNITS instead (final_unit_deduction / charge_type=unit)
 * and carries no cash price at all — that, and only that, is when the admin hint takes over.
 *
 * MONEY-SAFETY: when a cash price is parsable it IS the charge (the hint was only the reservation
 * estimate). list_price arrives as a STRING, so it is parsed defensively: a bool, an empty string,
 * a non-numeric value, a negative, a NaN/INF or a ZERO yields null — zero is rejected on purpose
 * because "0" is indistinguishable from an unparsable/absent price, and a silent 0 would zero the
 * charge. Null lets the caller fall back to the hint, and with no hint the money path fails closed
 * (cancelled, never charged at $0).
 */
final class KlingCost
{
    // === CONSTANTS ===
    // The IMAGE task envelope: the cash price Kling deducted for the task.
    public const IMAGE_LIST_PRICE_PATH = 'data.final_balance_deduction.list_price';

    // The VIDEO task query's billing lines. Kling's own responses nest this differently across its
    // video routes and it cannot be verified from here (the docs host rejects automated fetches),
    // so every documented location is PROBED — a response shape is never assumed.
    public const VIDEO_BILLING_PATHS = ['data.billing', 'billing', 'data.task_result.billing'];

    // Only a CASH line is money we owe; a resource-package (unit) line costs no USD.
    public const CHARGE_TYPE_CASH = 'cash';

    private const KEY_CHARGE_TYPE = 'charge_type';

    private const KEY_LIST_PRICE = 'list_price';

    /**
     * The cash USD cost of a completed IMAGE task; null when Kling priced it in units or returned
     * no parsable price.
     *
     * @param  array<string,mixed>  $response
     */
    public static function imageUsd(array $response): ?float
    {
        return self::usd(data_get($response, self::IMAGE_LIST_PRICE_PATH));
    }

    /**
     * The cash USD cost of a completed VIDEO task: the SUM of its cash billing lines (a clip can
     * bill video and audio separately). Null when no cash line carries a parsable price.
     *
     * @param  array<string,mixed>  $response
     */
    public static function videoUsd(array $response): ?float
    {
        foreach (self::VIDEO_BILLING_PATHS as $path) {
            $lines = data_get($response, $path);

            if (! is_array($lines)) {
                continue;
            }

            $total = 0.0;
            $found = false;

            foreach ($lines as $line) {
                if (! is_array($line)) {
                    continue;
                }

                if (strtolower(trim((string) ($line[self::KEY_CHARGE_TYPE] ?? ''))) !== self::CHARGE_TYPE_CASH) {
                    continue;
                }

                $usd = self::usd($line[self::KEY_LIST_PRICE] ?? null);

                if ($usd === null) {
                    continue;
                }

                $total += $usd;
                $found = true;
            }

            if ($found) {
                return $total;
            }
        }

        return null;
    }

    /**
     * videoUsd() as integer micro-USD (the unit every cost column stores). Null when unavailable.
     *
     * @param  array<string,mixed>  $response
     */
    public static function videoMicroUsd(array $response): ?int
    {
        $usd = self::videoUsd($response);

        return $usd === null ? null : CreditMath::usdToMicro($usd);
    }

    /**
     * A Kling list_price (a STRING, e.g. "0.028") as a POSITIVE USD amount, or null.
     * Rejects bool / null / empty / non-numeric / negative / zero / NaN / INF.
     */
    public static function usd(mixed $value): ?float
    {
        if ($value === null || is_bool($value) || is_array($value)) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        $usd = (float) $value;

        // A price is usable only when it is finite and strictly positive (see the class docblock:
        // a zero is treated as "no cash price", never as a free generation).
        if (! is_finite($usd) || $usd <= 0.0) {
            return null;
        }

        return $usd;
    }
}
