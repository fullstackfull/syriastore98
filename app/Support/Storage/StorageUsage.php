<?php

namespace App\Support\Storage;

/**
 * THE ONE PLACE THAT DECIDES HOW STORAGE USAGE IS COMPUTED AND HOW IT IS PERSISTED.
 *
 * `users.storage_limit` holds a merchant's CONSUMED storage in mebibytes. Four first-party sites
 * moved that figure, and all four computed it the same wrong way:
 *
 *   Utility::updateStorageLimit()            `$user->storage_limit + number_format($bytes / 1048576, 2)`
 *   Utility::changeStorageLimit()            `$user->storage_limit - number_format($bytes / 1048576, 2)`
 *   Utility::changeproductStorageLimit()     `$user->storage_limit - number_format($bytes / 1048576, 2)`
 *   AppSettingController (credit-back)       `$owner->storage_limit - number_format($bytes / 1048576, 2)`
 *
 * `number_format()` RETURNS A DISPLAY STRING AND WAS BEING USED AS A NUMBER. Two defects followed,
 * and they are at opposite ends of the size range. Both measured against this repository.
 *
 *  1. LIVE, AND THE ONE THAT MATTERS. Two decimals quantise the charge to 0.01 MiB, so
 *     **every file smaller than 5,243 bytes was charged 0.00** -- free storage, in unlimited
 *     quantity, through any of the 40 call sites. Measured:
 *         1 byte  -> "0.00"      5,000 bytes -> "0.00"      5,242 bytes -> "0.00"
 *         5,243 bytes -> "0.01"
 *     Everything larger was also quantised, so each upload was mischarged by up to 5 KB.
 *
 *  2. LATENT, AND NOT CLAIMED AS LIVE. At 1,000 MiB and above `number_format()` inserts a
 *     THOUSANDS SEPARATOR, and PHP's arithmetic then reads the leading digits only:
 *         2,500 MiB -> "2,500.00" -> `$x - "2,500.00"` subtracts 2, with
 *         `Warning: A non-numeric value encountered`
 *     so a 2,500 MiB credit-back returned 2 MiB. NOT REACHABLE TODAY: the largest configured
 *     ceiling is `max:51200` (50 MB, `config/uploads.php` preview_video) and this container's PHP
 *     caps at `upload_max_filesize = 2M`, so no validated door accepts a file that large. It
 *     becomes live the moment an operator raises those limits past 1,000 MiB.
 *
 * WHY THE FIX IS NOT SIMPLY "DROP `number_format`", which is the obvious move and is WRONG here.
 * `users.storage_limit` is a **VARCHAR**: `2014_10_12_000000_create_users_table.php:38` declares
 * `$table->string('storage_limit')->nullable()`, and `User` casts it to nothing, so whatever PHP
 * stringifies is what the column receives. A raw float round-trips through that column as PHP's
 * own string form, and for a small file that form is SCIENTIFIC NOTATION -- measured:
 *
 *     1 byte  = 9.5367431640625E-7 MiB  ->  (string) "9.5367431640625E-7"
 *
 * A column holding `"9.5367431640625E-7"` is read correctly by PHP's own `(float)` cast but not by
 * a database-side numeric context: MySQL's `CAST(... AS DECIMAL)` stops at the `E`, and the figure
 * is also rendered to merchants directly. So the arithmetic is done in FLOAT and the result is
 * persisted as a FIXED-POINT DECIMAL STRING with no separator and no exponent.
 *
 * PRECISION, CHOSEN BY MEASUREMENT RATHER THAN TASTE. Six decimals of a mebibyte is a quantum of
 * 1.049 bytes, so no real file is free any more.
 *
 * The cost is that each stored total is re-rounded, and A FIRST DRAFT OF THIS PARAGRAPH CLAIMED THE
 * DRIFT IS ALWAYS IN THE PLATFORM'S FAVOUR. It is not, and the Feature test driving the real writer
 * is what refuted it. `number_format()` rounds to NEAREST, so the direction depends on the file:
 *
 *     1 byte     = 9.5367e-7 MiB  -> 0.000001   rounds UP    (over-charges by 0.05 bytes)
 *     2,048 byte = 0.001953125    -> 0.001953   rounds DOWN  (under-charges by 0.13 bytes)
 *
 * so 10,000 one-byte uploads over-charge by 486 bytes while fifty 2 KB uploads under-charge by 6.5.
 * The honest guarantee is that the error per write is bounded by HALF A QUANTUM -- about half a byte
 * -- with no preferred direction, which is what the tests assert. Claiming the stronger property
 * would be claiming a safety margin this code does not provide.
 *
 * A one-directional guarantee IS available by rounding the stored total up instead of to nearest,
 * and it was rejected: ceiling the total leaves a residue on every upload-then-delete cycle, so a
 * merchant who repeatedly uploads and removes a file accrues usage they do not hold. An exact round
 * trip was judged worth more than a sub-byte margin, and that is a trade rather than an oversight.
 *
 * WHAT THIS DELIBERATELY DOES NOT CHANGE, because the owner's instruction is to preserve the
 * approved storage-limit policy and not to redesign the quota:
 *  - THE UNIT STAYS MEBIBYTES. Storing bytes would remove the drift entirely, but every reader
 *    treats the column as megabytes -- `PlatformCapabilities::limit('Storage (MB)', ...)`,
 *    `DashboardController` renders `. ' MB'`, and `HomeController` divides it by the plan's own
 *    limit to get a percentage. Changing the unit silently would break all three.
 *  - THE PLAN COMPARISON KEEPS ITS `<=`. A plan whose limit equals the new total refuses. That is
 *    the shipped behaviour; it is not what this class was opened for.
 *  - NO HISTORICAL USAGE IS DELIBERATELY REWRITTEN, and the earlier wording of this bullet --
 *    "existing stored values are read as they are" -- was FALSE in two cases, found by independent
 *    review. `stored()` normalises, and the next write persists the normalised figure:
 *      * a NEGATIVE stored figure (which the pre-fix unclamped credit-backs could produce) reads as
 *        0 and is persisted as 0 -- the merchant silently loses it;
 *      * a legacy `"2,500.00"` reads as 2.0 and is persisted as `"2.000000"` -- 2,500 MiB of
 *        recorded usage destroyed, in the merchant's favour.
 *    Both are defensible normalisations of values no reader can use correctly anyway, and neither
 *    is a migration: nothing sweeps the table. But they are changes, and this bullet now says so.
 *    Whether past under-charging should be reconciled is an owner decision with no measurement
 *    behind it here.
 */
final class StorageUsage
{
    /** One mebibyte. The divisor every one of the four sites already used. */
    public const BYTES_PER_MEBIBYTE = 1048576;

    /**
     * Decimal places kept in the stored string: a quantum of 1.049 bytes.
     *
     * Not arbitrary. At 2 (the old value) every file under 5,243 bytes was free. At 6 the smallest
     * possible file is charged. Raising it further buys nothing a byte-sized quantum does not
     * already give, and widens the stored string for no reader's benefit.
     */
    public const DECIMALS = 6;

    private function __construct()
    {
    }

    /** Exact mebibytes for a byte count, as a float and with no formatting applied. */
    public static function mebibytes(int|float $bytes): float
    {
        return max(0.0, (float) $bytes) / self::BYTES_PER_MEBIBYTE;
    }

    /**
     * The usage currently stored for a merchant, as a float.
     *
     * The column is nullable and holds a string, so a merchant who has never uploaded reads `null`.
     * A non-numeric value -- which a pre-fix write could have left, since `"2,500.00"` was
     * assignable -- reads as its leading numeric part exactly as PHP's own arithmetic read it
     * before, rather than throwing on a row this class did not write. Never negative.
     */
    public static function stored(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return max(0.0, (float) $value);
    }

    /**
     * The string to persist for a usage figure.
     *
     * Fixed point, no thousands separator, no exponent -- the three properties the column needs and
     * the old call did not have. Clamped at zero: a credit-back for a file whose charge was lost
     * must not drive the stored figure negative, which would hand the merchant free quota.
     */
    public static function persist(float $mebibytes): string
    {
        return number_format(max(0.0, $mebibytes), self::DECIMALS, '.', '');
    }

    /**
     * Decimals shown on a merchant-facing figure before trailing zeros are trimmed.
     *
     * Deliberately NOT `DECIMALS`: six is the precision the column must KEEP, two is the precision
     * a dashboard sentence can READ. `display()` falls back to the former when the latter would
     * round a real file down to nothing.
     */
    private const DISPLAY_DECIMALS = 2;

    /**
     * A usage figure as it is shown to a merchant. NEVER an exponent, never a false zero.
     *
     * THIS METHOD EXISTS BECAUSE OF A REGRESSION THIS SLICE CAUSED. The dashboard note
     * concatenated the raw float, which after the six-decimal change printed "1.988443MB / 100MB"
     * and, for usage under ~105 bytes, PHP's scientific notation -- measured, "4.8E-5MB". The first
     * repair put `number_format($x, 2)` in the view and broke three asserted sentences:
     * `DashboardFigureHonestyTest` pins "12MB / 500MB", "12 MB used of unlimited storage" and
     * "12 MB used", and two fixed decimals render all three with a ".00". The docblock in
     * `resources/views/dashboard.blade.php` states those same three sentences, so the view and its
     * tests agreed and the change disagreed with both.
     *
     * Four properties, and no single `number_format()` call has all four:
     *
     *   a whole figure prints whole          12.0     -> "12"        (the three pinned sentences)
     *   no exponent, at any magnitude        9.5e-7   -> "0.000001"
     *   a stored file never reads as zero    1 byte   -> not "0"
     *   a dashboard figure stays short       1.988443 -> "1.99"
     *
     * Two decimals fail the second and third -- every file under 5,243 bytes displays "0.00", which
     * tells a merchant who is storing something that they are storing nothing, the same lie as the
     * old charge in the opposite direction. Six decimals fail the first and fourth. So: two
     * decimals with trailing zeros trimmed, widening to the stored precision ONLY when two decimals
     * would collapse a nonzero figure to zero.
     *
     * No thousands separator, for the reason the rest of this class has none: `2500.0` must not
     * become `"2,500"`, which is the string whose arithmetic started all of this.
     */
    public static function display(int|float $mebibytes): string
    {
        $value = max(0.0, (float) $mebibytes);

        if ($value === 0.0) {
            return '0';
        }

        $shown = self::trimTrailingZeros(number_format($value, self::DISPLAY_DECIMALS, '.', ''));

        if ($shown === '0') {
            $shown = self::trimTrailingZeros(number_format($value, self::DECIMALS, '.', ''));
        }

        return $shown === '' ? '0' : $shown;
    }

    /**
     * "12.00" -> "12", "12.10" -> "12.1", "12.34" -> "12.34".
     *
     * Only ever called on `number_format()` output, which is why trimming the '0' characters is
     * safe: the string is guaranteed to carry a '.' and a fixed number of decimals, so "500" can
     * never arrive here and lose its zeros.
     */
    private static function trimTrailingZeros(string $fixedPoint): string
    {
        if (! str_contains($fixedPoint, '.')) {
            return $fixedPoint;
        }

        return rtrim(rtrim($fixedPoint, '0'), '.');
    }

    /** Stored usage plus a file, as a float. The caller decides what to do with the total. */
    public static function plus(mixed $stored, int|float $bytes): float
    {
        return self::stored($stored) + self::mebibytes($bytes);
    }

    /** Stored usage minus a file, never below zero. */
    public static function minus(mixed $stored, int|float $bytes): float
    {
        return max(0.0, self::stored($stored) - self::mebibytes($bytes));
    }
}
