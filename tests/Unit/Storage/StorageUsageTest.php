<?php

namespace Tests\Unit\Storage;

use App\Support\Storage\StorageUsage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * STORAGE WAS CHARGED THROUGH A DISPLAY FORMATTER, SO SMALL FILES WERE FREE.
 *
 * The four first-party sites that move `users.storage_limit` all computed
 * `number_format($bytes / 1048576, 2)` and then used the RESULT AS A NUMBER. Two defects at
 * opposite ends of the size range followed; this file pins both, plus the hazard the obvious fix
 * would have introduced.
 *
 * These cases are pure arithmetic and need no container, which is why they are a Unit test. The
 * half that cannot be proved here -- that a fractional figure survives a round trip through the
 * VARCHAR column -- is in `tests/Feature/Storage/StorageAccountingIsExactTest.php`, because
 * `users.storage_limit` is `$table->string(...)` and a float calculation alone proves nothing about
 * what is stored.
 */
class StorageUsageTest extends TestCase
{
    // ---------------------------------------------------------------------------------------------
    // the live defect: tiny files were free
    // ---------------------------------------------------------------------------------------------

    /**
     * THE DEFECT. Every file under 5,243 bytes used to be charged nothing.
     *
     * The old expression is reproduced here rather than described, so the assertion is against
     * measured behaviour and not against a claim about it.
     */
    #[Test]
    #[DataProvider('tinyFiles')]
    public function test_a_file_too_small_for_two_decimals_is_still_charged(int $bytes): void
    {
        $this->assertSame(
            '0.00',
            number_format($bytes / 1048576, 2),
            'premise: this is the size range the old expression charged nothing for. If this fails '
            . 'the premise has moved and the case below no longer tests what it was written for.'
        );

        $charged = StorageUsage::persist(StorageUsage::plus(null, $bytes));

        $this->assertGreaterThan(
            0.0,
            (float) $charged,
            $bytes . ' bytes is still charged nothing, so a merchant has unlimited free storage in '
            . 'files of this size'
        );
    }

    public static function tinyFiles(): array
    {
        return [
            'one byte' => [1],
            'a kilobyte' => [1024],
            'just under the old quantum' => [5242],
        ];
    }

    /** And a real file is charged what it actually is, not a rounded display figure. */
    #[Test]
    public function test_a_file_is_charged_its_own_size(): void
    {
        $this->assertSame(1.0, StorageUsage::mebibytes(1048576));
        $this->assertSame(0.5, StorageUsage::mebibytes(524288));
        $this->assertSame(0.0, StorageUsage::mebibytes(0));
    }

    // ---------------------------------------------------------------------------------------------
    // the latent defect: the thousands separator
    // ---------------------------------------------------------------------------------------------

    /**
     * A LARGE FIGURE IS NOT TRUNCATED AT A THOUSANDS SEPARATOR.
     *
     * NOT A LIVE PATH and not claimed as one: the largest configured ceiling is `max:51200` (50 MB)
     * and this container caps uploads at 2 MB, so no validated door accepts a file of 1,000 MiB.
     * It is pinned because the defect is one `ini_set` away from being live, and because the
     * premise assertion below records exactly what the old expression did.
     */
    #[Test]
    public function test_a_figure_above_a_thousand_is_not_cut_at_the_separator(): void
    {
        $bytes = 2500 * 1048576;

        /*
         * THE PREMISE, DONE IN THE CONTEXT THAT ACTUALLY REPRODUCES IT.
         *
         * The first draft asserted `(float) "2,500.00" === 2.0`, which independent review correctly
         * called tautological: that is a fact about the CAST, not about the defect, which was
         * `$x - "2,500.00"` in ARITHMETIC context -- a different operation, and the one that raises
         * `A non-numeric value encountered`. The arithmetic is now done for real.
         */
        $this->assertSame('2,500.00', number_format($bytes / 1048576, 2), 'premise: the separator');

        $separated = number_format($bytes / 1048576, 2);

        /*
         * THE WARNING IS CAPTURED, NOT SUPPRESSED, because it is the defect's signature.
         *
         * `@` does not help here: PHPUnit installs its own error handler and converts the notice
         * into a test warning before the suppression operator is consulted, which left this file
         * reporting "1 warning" -- an outcome the known-outcome gate has no declaration for. A
         * local handler both silences it and lets the message be asserted, so the test now proves
         * MORE than it did: the arithmetic loses 2,498 MiB *and* PHP says why.
         */
        $raised = null;
        set_error_handler(function (int $severity, string $message) use (&$raised): bool {
            $raised = $message;

            return true;
        });

        $afterOldArithmetic = 5000.0 - $separated;

        restore_error_handler();

        $this->assertSame(
            4998.0,
            $afterOldArithmetic,
            'premise: subtracting the separated string from 5000 must lose 2,498 MiB, which is the '
            . 'defect. PHP read only the leading digits.'
        );
        $this->assertSame(
            'A non-numeric value encountered',
            $raised,
            'premise: PHP must say the value is not numeric. If it stopped warning, the silent '
            . 'half of this defect would be worse, not better.'
        );

        $this->assertSame(2500.0, StorageUsage::mebibytes($bytes));
        $this->assertSame('2500.000000', StorageUsage::persist(2500.0));
    }

    /**
     * AND THE STORED FORM NEVER CARRIES AN EXPONENT -- the hazard the obvious fix introduces.
     *
     * `users.storage_limit` is a VARCHAR with no cast, so the column receives whatever PHP
     * stringifies. A raw float for a one-byte file stringifies as `"9.5367431640625E-7"`, which
     * PHP's own `(float)` reads back correctly but a database-side numeric cast does not. Simply
     * deleting `number_format` would have traded a rounding defect for a storage-format defect.
     */
    #[Test]
    #[DataProvider('everySizeThatMustStorePlainly')]
    public function test_the_stored_form_is_plain_fixed_point(int|float $bytes): void
    {
        $stored = StorageUsage::persist(StorageUsage::mebibytes($bytes));

        $this->assertDoesNotMatchRegularExpression('/[eE]/', $stored,
            'the stored value carries an exponent: ' . $stored);
        $this->assertStringNotContainsString(',', $stored,
            'the stored value carries a thousands separator: ' . $stored);
        $this->assertMatchesRegularExpression('/^\d+\.\d{6}$/', $stored,
            'the stored value is not plain fixed point: ' . $stored);
    }

    public static function everySizeThatMustStorePlainly(): array
    {
        return [
            'one byte (the exponent case)' => [1],
            'half a mebibyte' => [524288],
            'one mebibyte' => [1048576],
            'fifty megabytes, the configured ceiling' => [52428800],
            'a thousand mebibytes (the separator case)' => [1048576000],
            'a very large figure' => [1048576 * 1234567],
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // the arithmetic contracts the owner named
    // ---------------------------------------------------------------------------------------------

    /** Repeated uploads accumulate; they do not overwrite. */
    #[Test]
    public function test_repeated_uploads_accumulate(): void
    {
        $usage = 0.0;

        for ($i = 0; $i < 4; $i++) {
            $usage = StorageUsage::plus(StorageUsage::persist($usage), 1048576);
        }

        $this->assertSame('4.000000', StorageUsage::persist($usage));
    }

    /** A deletion credits back exactly what the same file was charged. */
    #[Test]
    public function test_a_deletion_credits_back_what_the_upload_charged(): void
    {
        $bytes = 1234567;

        $after = StorageUsage::persist(StorageUsage::plus(null, $bytes));
        $back = StorageUsage::persist(StorageUsage::minus($after, $bytes));

        $this->assertSame('0.000000', $back,
            'the credit-back and the charge disagree, so usage drifts on every upload-delete cycle');
    }

    /**
     * USAGE NEVER GOES NEGATIVE.
     *
     * A credit-back can legitimately exceed the recorded usage -- a file charged before this fix
     * was charged 0.00 and is credited its real size now. Negative usage would hand the merchant
     * free quota on top of a plan limit, so it is clamped.
     */
    #[Test]
    public function test_usage_never_goes_negative(): void
    {
        $this->assertSame(0.0, StorageUsage::minus('0.500000', 1048576));
        $this->assertSame('0.000000', StorageUsage::persist(-5.0));
        $this->assertSame(0.0, StorageUsage::stored('-12.5'));
    }

    /**
     * CUMULATIVE ROUNDING IS BOUNDED, AND SYMMETRIC -- NOT ONE-DIRECTIONAL.
     *
     * CORRECTED: this test first asserted that accumulation can never UNDERCHARGE, and it passed.
     * The guarantee was accidental. A one-byte upload is 9.537e-7 MiB and rounds UP to 1e-6, so
     * 10,000 of them over-charge; but 2,048 bytes is 0.001953125 and rounds DOWN to 0.001953, so
     * fifty of those UNDER-charge. The Feature test driving the real writer caught it. Rounding to a
     * fixed number of decimals is NEAREST, so the error per write is bounded by half a quantum and
     * has no preferred direction. That is the real property, and asserting the stronger one would
     * have been a guarantee this code does not provide.
     *
     * The quantum is 1.049 bytes, so the bound per write is about half a byte. What matters for the
     * defect this class exists for -- that no file is charged NOTHING -- is asserted separately.
     */
    #[Test]
    public function test_cumulative_rounding_stays_within_the_quantum(): void
    {
        $writes = 10000;
        $exact = 0.0;
        $stored = '0';

        for ($i = 0; $i < $writes; $i++) {
            $exact += StorageUsage::mebibytes(1);
            $stored = StorageUsage::persist(StorageUsage::plus($stored, 1));
        }

        $bound = $writes * (0.5 / (10 ** StorageUsage::DECIMALS));

        /*
         * AN ABSOLUTE FLOOR, BECAUSE THE BOUND ABOVE IS SELF-REFERENTIAL -- found by independent
         * review, which asked exactly the right question about this assertion.
         *
         * `$bound` is computed FROM `DECIMALS`, the constant under test. Revert it to 2 -- the
         * original defect -- and every write persists "0.00", so the drift is 0.00954 while the
         * bound becomes 10,000 x 0.005 = 50. It passes. At `DECIMALS = 0` the bound is 5,000 and it
         * passes more comfortably still. So the bound alone catches gross arithmetic errors and
         * never a coarsening of the quantum, which is the defect.
         *
         * The Feature twin was sound for precisely the reason this was not: it carries an absolute
         * floor beside the same relative bound. This now does too.
         */
        $this->assertGreaterThan(
            0.009,
            (float) $stored,
            'Ten thousand one-byte uploads accumulated ' . $stored . ' MiB, which is far below '
            . 'their true 0.00954. The quantum has been coarsened and the relative bound below '
            . 'cannot see that, because it is computed from the same constant.'
        );

        $this->assertLessThanOrEqual(
            $bound,
            abs((float) $stored - $exact),
            'the drift over ' . $writes . ' uploads exceeds half a quantum per write, which is more '
            . 'than nearest-rounding explains: stored ' . $stored . ' against an exact ' . $exact
        );
    }

    /** A merchant who has never uploaded reads zero, not an error. */
    #[Test]
    #[DataProvider('absentValues')]
    public function test_an_absent_stored_value_reads_as_zero(mixed $value): void
    {
        $this->assertSame(0.0, StorageUsage::stored($value));
    }

    public static function absentValues(): array
    {
        return [
            'never uploaded (nullable column)' => [null],
            'empty string' => [''],
            'zero' => ['0'],
        ];
    }

    /**
     * A value a PRE-FIX write could have left behind is read, not thrown on.
     *
     * `"2,500.00"` was assignable to this column before the fix, so such rows may exist. Reading one
     * must not be a fatal error on a merchant's dashboard; it reads as PHP's arithmetic already read
     * it, which is the pre-existing behaviour rather than a new decision.
     */
    #[Test]
    public function test_a_pre_fix_separated_value_is_read_rather_than_fatal(): void
    {
        $this->assertSame(2.0, StorageUsage::stored('2,500.00'));
    }

    /*
     * ------------------------------------------------------------------------------------------
     *  display() -- THE DASHBOARD SENTENCE, AND THE REGRESSION THAT MADE IT NECESSARY.
     * ------------------------------------------------------------------------------------------
     *
     * The storage note on the merchant dashboard concatenated the raw float, so a six-decimal
     * stored figure printed "1.988443MB / 100MB" and a sub-kilobyte one printed an EXPONENT --
     * measured, "4.8E-5MB". The first repair applied `number_format($x, 2)` in the view, which
     * removed the exponent and BROKE THREE ASSERTED SENTENCES: `DashboardFigureHonestyTest`
     * pins "12MB / 500MB", "12 MB used of unlimited storage" and "12 MB used", and two decimals
     * render those as "12.00". That regression is what these cases exist to stop recurring.
     *
     * So the formatter has to satisfy four properties at once, and they pull against each other:
     *   a whole number prints whole        12       -> "12"      (the three pinned sentences)
     *   no exponent, ever                  9.5e-7   -> "0.000001"
     *   a nonzero usage never reads zero   1 byte   -> not "0"
     *   a dashboard figure stays short     1.988443 -> "1.99"
     *
     * Two decimals alone fails the second and third; six decimals alone fails the first and
     * fourth. Hence two decimals, trailing zeros trimmed, FALLING BACK to the stored precision
     * only when two decimals would round a real file down to nothing.
     */

    /** A whole number of mebibytes prints with no decimal part -- the three pinned sentences. */
    #[Test]
    #[DataProvider('wholeFigures')]
    public function test_a_whole_figure_prints_without_a_decimal_part(float $mebibytes, string $expected): void
    {
        $this->assertSame($expected, StorageUsage::display($mebibytes));
    }

    public static function wholeFigures(): array
    {
        return [
            'the used figure the dashboard test pins' => [12.0, '12'],
            'the quota the dashboard test pins' => [500.0, '500'],
            'nothing stored' => [0.0, '0'],
            'a figure stored to six decimals that is still whole' => [12.000000, '12'],
            'four digits, and NO thousands separator (the column has none either)' => [2500.0, '2500'],
        ];
    }

    /** A fractional figure is shortened to two decimals, with no trailing zeros. */
    #[Test]
    #[DataProvider('fractionalFigures')]
    public function test_a_fractional_figure_is_shortened_for_the_dashboard(float $mebibytes, string $expected): void
    {
        $this->assertSame($expected, StorageUsage::display($mebibytes));
    }

    public static function fractionalFigures(): array
    {
        return [
            'the six-decimal figure that printed in full' => [1.988443, '1.99'],
            'a half' => [0.5, '0.5'],
            'two decimals exactly' => [12.34, '12.34'],
            'a trailing zero is trimmed' => [12.10, '12.1'],
        ];
    }

    /**
     * NO EXPONENT, which is the defect this method was added for.
     *
     * PHP stringifies a float under ~1e-5 in scientific notation, so the sentence read
     * "9.5367431640625E-7 MB used" to a merchant who had uploaded one byte.
     */
    #[Test]
    public function test_a_sub_kilobyte_figure_never_prints_an_exponent(): void
    {
        $oneByte = StorageUsage::mebibytes(1);

        $this->assertStringContainsString('E-', (string) $oneByte,
            'PREMISE: this figure does not stringify with an exponent, so the case proves nothing.');

        $shown = StorageUsage::display($oneByte);

        $this->assertStringNotContainsString('E', $shown);
        $this->assertStringNotContainsString('e', $shown);
        $this->assertSame('0.000001', $shown);
    }

    /**
     * A REAL FILE IS NEVER DESCRIBED AS ZERO USAGE -- the opposite lie to the one two decimals fixed.
     *
     * At two decimals every file under 5,243 bytes displays as "0.00", which reads as "you are
     * storing nothing" to a merchant who is storing something. The fallback to the stored
     * precision is what stops that, and `DECIMALS` is a 1.049-byte quantum, so the smallest
     * possible file still shows.
     */
    #[Test]
    #[DataProvider('smallRealFiles')]
    public function test_a_stored_file_is_never_displayed_as_zero(int $bytes): void
    {
        $shown = StorageUsage::display(StorageUsage::mebibytes($bytes));

        $this->assertNotSame('0', $shown, $bytes . ' bytes are stored but the card says 0');
        $this->assertNotSame('0.00', $shown);
        $this->assertGreaterThan(0.0, (float) $shown, $bytes . ' bytes display as a zero figure');
    }

    public static function smallRealFiles(): array
    {
        return [
            'one byte' => [1],
            'a 2 KB thumbnail' => [2048],
            'the old free-storage ceiling' => [5242],
        ];
    }

    /** Negative is clamped, as everywhere else in this class. */
    #[Test]
    public function test_a_negative_figure_displays_as_zero(): void
    {
        $this->assertSame('0', StorageUsage::display(-5.0));
    }
}
