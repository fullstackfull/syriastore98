<?php

namespace Tests\Unit\Localisation;

use App\Models\Utility;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * P2.5-PKG-M — the Arabic admin printed `Nov 6, 2026`.
 * ================================================================================================
 *
 * FOUND BY LOOKING AT THE ORDER LIST at 390px in Arabic: every row's date read `Nov 6, 2026` — a
 * Latin month abbreviation inside a right-to-left line — and the status chip read
 * `معلق : Nov 6, 2026`, an Arabic status and a Latin date in one control.
 *
 * THE CAUSE IS `date()`, WHICH IS NOT LOCALE-AWARE AT ALL. `Utility::dateFormat()` is
 *
 *     date(Utility::GetValueByName('site_date_format'), strtotime($date))
 *
 * and PHP's `date()` emits English month and day names whatever the application locale is. There
 * is no setting that changes it; the only fix is a different formatter.
 *
 * THE REPAIR IS A DROP-IN, AND THAT IS MEASURED RATHER THAN HOPED. Carbon's `translatedFormat()`
 * takes the SAME `date()` format characters and translates only the textual ones, so the
 * merchant's configured `site_date_format` keeps working exactly as before:
 *
 *     date('M j, Y')                        Nov 6, 2026
 *     translatedFormat('M j, Y') en         Nov 6, 2026      <- byte-identical
 *     translatedFormat('M j, Y') ar_SY      تشرين الثاني 6, 2026
 *
 * The English half is asserted below across a format set that exercises every textual token, so
 * the claim "nothing changes for an English merchant" is a test rather than a sentence.
 *
 * `ar_SY`, NOT `ar`, for the reason P2.5-PKG-L recorded on the customer timeline: Carbon's generic
 * Arabic gives the transliterated Gregorian names (`نوفمبر`), the Syrian variant gives the
 * Levantine ones a merchant here actually writes (`تشرين الثاني`).
 *
 * TWO MORE COPIES OF THIS FUNCTION EXIST AND ARE DELIBERATELY LEFT ALONE:
 *
 *   `User::dateFormat()`      — same `date()` bug, but FOUR OF ITS SEVEN CALL SITES ARE THE API:
 *                               Api\DashboardController:7461/7464/7472/7473 return
 *                               `plan_expire_date` and `trial_expire_date` to the Flutter app.
 *                               Flutter DISPLAYS them verbatim (my_plan_widget.dart:128) and never
 *                               parses them, so localising would help rather than break — but the
 *                               server's locale is not necessarily the app's, and the owner
 *                               reserves public API compatibility. REPORTED, NOT CHANGED.
 *   `Customer::dateFormat()`  — zero call sites in the whole repository. Dead, and removing a
 *                               public model method is its own decision.
 */
class MerchantDateFormatTest extends TestCase
{
    /** Every textual token `date()` can emit, plus the shipped default. */
    private const FORMATS = ['M j, Y', 'd M Y', 'D, d M Y', 'l jS F Y', 'Y-m-d', 'd/m/Y', 'M j, Y g:i A'];

    private const MOMENT = '2026-11-06 14:32:05';

    public function test_the_english_output_is_byte_identical_to_what_date_produced(): void
    {
        App::setLocale('en');

        foreach (self::FORMATS as $format) {
            $before = date($format, strtotime(self::MOMENT));
            $after = Utility::formatMomentForLocale(self::MOMENT, $format);

            $this->assertSame($before, $after,
                "the English rendering of `{$format}` changed from `{$before}` to `{$after}`. "
                . 'An English merchant must see exactly what they saw before.');
        }
    }

    public function test_arabic_gets_the_levantine_month_names(): void
    {
        App::setLocale('ar');

        $rendered = Utility::formatMomentForLocale(self::MOMENT, 'M j, Y');

        $this->assertStringContainsString('تشرين الثاني', $rendered);
        $this->assertStringNotContainsString('Nov', $rendered);
        $this->assertStringNotContainsString('نوفمبر', $rendered,
            'the generic Arabic locale is being used; `ar_SY` gives the Levantine month names');
    }

    public function test_the_weekday_is_localised_too(): void
    {
        App::setLocale('ar');

        $rendered = Utility::formatMomentForLocale(self::MOMENT, 'D, d M Y');

        $this->assertStringNotContainsString('Fri', $rendered);
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $rendered);
    }

    /**
     * `strtotime(null)` is 0, and `date($fmt, 0)` is 1 January 1970 — which this codebase has
     * already been bitten by (OrderDataTable carries a `hasDate()` guard and a docblock about it).
     * The replacement must not quietly start printing a different wrong answer.
     */
    public function test_an_unusable_value_behaves_exactly_as_it_did_before(): void
    {
        App::setLocale('en');

        foreach ([null, '', '0000-00-00 00:00:00', 'not a date'] as $value) {
            $before = @date('M j, Y', strtotime((string) $value));
            $after = Utility::formatMomentForLocale($value, 'M j, Y');

            $this->assertSame($before, $after,
                'an unusable date value now renders differently: ' . var_export($value, true)
                . " was `{$before}`, is now `{$after}`. Callers guard this themselves "
                . '(OrderDataTable::hasDate), so changing it here would move the goalposts.');
        }
    }

    public function test_date_format_itself_now_goes_through_the_localised_path(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Models/Utility.php');

        $this->assertMatchesRegularExpression(
            '/public static function dateFormat\([^)]*\)\s*\{[^}]*formatMomentForLocale/s',
            $source,
            'Utility::dateFormat() no longer delegates to the localised formatter, so the Arabic '
            . 'admin is back to `Nov 6, 2026`.');
    }

    /** The two copies that are NOT changed, asserted so the docblock above cannot go stale. */
    public function test_the_two_untouched_copies_are_still_untouched(): void
    {
        $user = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Models/User.php');
        $customer = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Models/Customer.php');

        foreach (['User' => $user, 'Customer' => $customer] as $name => $source) {
            $this->assertMatchesRegularExpression(
                '/function dateFormat\([^)]*\)\s*\{[^}]*\bdate\(/s',
                $source,
                "{$name}::dateFormat() has changed. If that was deliberate, say why here: the User "
                . 'copy feeds four API fields the Flutter app displays, and public API '
                . 'compatibility is reserved to the owner.');
        }
    }
}
