<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;

/**
 * P2.5-PKG-L — one date format on the customer timeline, in the reader's own language.
 * ================================================================================================
 *
 * THE OWNER REVIEWED THE SCREEN AND NAMED THE DATES TWICE: "date-label ambiguity" and "Arabic
 * localization". They are the same defect seen from two sides. MEASURED on the real page:
 *
 *     Register Date:-  2026-10-08 01:24:56     <- the customer's own registration moment,
 *                                                printed as a raw SQL string, in a green chip
 *     07 Nov 2026 01:24 AM                     <- the ACTIVITY LOG ROW's created_at, unlabelled
 *
 * Two dates, two formats, no labels, eight hours and a month apart, on one card — and the second
 * one is IDENTICAL on all seven cards, because that is when the rows were written. A merchant
 * reading the screen cannot tell which is "when this happened".
 *
 * AND NEITHER WAS LOCALISED. `created_at->format('d M Y h:i A')` is `format()`, not
 * `translatedFormat()`, so the Arabic page printed `07 Nov 2026 01:24 AM` — a Latin month
 * abbreviation and a Latin meridiem inside a right-to-left sentence.
 *
 * `ar_SY`, NOT `ar`, AND THAT IS THE POINT OF HAVING A HELPER AT ALL. Carbon's generic Arabic
 * locale gives the transliterated Gregorian names — `أكتوبر` — while `ar_SY` gives the Levantine
 * ones a Syrian merchant actually writes: `تشرين الأول`. Measured side by side:
 *
 *     ar      08 أكتوبر 2026, 1:24 ص
 *     ar_SY   08 تشرين الأول 2026, 1:24 ص
 *
 * THE LOCALE IS SET PER INSTANCE, never globally. `Carbon::setLocale()` would change every date in
 * the admin, which is a far wider change than the screen under review and is not what was asked.
 *
 * IT PARSES, IT DOES NOT COMPUTE. The values arrive as a Carbon instance (`created_at`) or as a
 * raw column string (`customers.regiester_date`, `orders.order_date`), and an unparseable or empty
 * one returns '' so the view can omit the row rather than print "Invalid date". NOTHING HERE
 * CHANGES A STORED VALUE: the owner's instruction is explicit that timestamps, amounts and
 * activity-log data must not be altered to improve appearance, and this only formats.
 */
final class TimelineMoment
{
    /** Day, month, year and time — the same shape in both languages so two dates can be compared. */
    private const PATTERN = 'd M Y، g:i A';

    /** The LTR pattern uses a comma; Arabic uses the Arabic comma, which is part of the language. */
    private const PATTERN_LTR = 'd M Y, g:i A';

    public static function format(mixed $value): string
    {
        $moment = self::parse($value);

        if ($moment === null) {
            return '';
        }

        $locale = self::locale();
        $pattern = str_starts_with($locale, 'ar') ? self::PATTERN : self::PATTERN_LTR;

        return $moment->locale($locale)->translatedFormat($pattern);
    }

    /** Date only, for a value that carries no meaningful time (an order's date column). */
    public static function formatDate(mixed $value): string
    {
        $moment = self::parse($value);

        if ($moment === null) {
            return '';
        }

        return $moment->locale(self::locale())->translatedFormat('d M Y');
    }

    /**
     * `ar_SY` for an Arabic reader, the application locale otherwise.
     *
     * Carbon falls back to `ar` for any `ar_*` it does not carry, so naming the Syrian variant
     * cannot make the output worse than the generic one.
     */
    public static function locale(): string
    {
        $locale = (string) App::getLocale();

        return str_starts_with($locale, 'ar') ? 'ar_SY' : $locale;
    }

    private static function parse(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $raw = trim((string) $value);

        if ($raw === '' || $raw === '0000-00-00 00:00:00' || $raw === '0000-00-00') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
