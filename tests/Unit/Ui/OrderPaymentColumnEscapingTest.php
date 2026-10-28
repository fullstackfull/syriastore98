<?php

namespace Tests\Unit\Ui;

use Tests\TestCase;

/**
 * The order list's payment cell is a RAW column, so its closure must escape.
 * ==========================================================================
 *
 * This exists because the relationship between the two is a page-apart pairing that a comment
 * cannot hold. The closure is near the top of `OrderDataTable::html()`; `rawColumns()` is ~150
 * lines below it, and a cited line number for it has now been wrong twice in one change — once
 * at 21 lines stale, and once because writing the correction moved the target. An invariant that
 * decays every time somebody edits around it is not an invariant, so it is asserted here.
 *
 * WHAT COULD GO WRONG IF IT IS NOT. `PaymentTypeLabel::for()` returns one of its own literals or
 * null for the brands, which is safe whatever happens — but for `cod`, `bank_transfer` and
 * `other_payment` it returns `__(...)`, read from `resources/lang/<locale>.json`, and
 * `LanguageController::storeLanguageData()` writes that file from request input with no escaping.
 * That file is global to every store. The permission it needs (`Create Language`) is operator-only
 * and denylisted for merchants in `config/superadminaccess.php`, so this is a second layer rather
 * than the only one — which is the point: this project's rule is never to let one check be the
 * only one.
 */
class OrderPaymentColumnEscapingTest extends TestCase
{
    private function source(): string
    {
        $path = base_path('app/DataTables/OrderDataTable.php');
        $this->assertFileExists($path);

        $raw = (string) file_get_contents($path);

        /*
         * Comments stripped before matching — the fifth time this project has needed that. The
         * paragraph above the closure quotes the very expression being asserted on.
         */
        $stripped = preg_replace('#/\*.*?\*/#s', '', $raw);
        $stripped = preg_replace('#^\s*//.*$#m', '', (string) $stripped);

        $this->assertNotSame('', trim((string) $stripped), 'stripping removed the whole file');

        return (string) $stripped;
    }

    public function test_the_payment_column_is_raw_and_its_closure_escapes(): void
    {
        $source = $this->source();

        $this->assertSame(
            1,
            preg_match('/->rawColumns\(\[(.*?)\]\)/s', $source, $raw),
            'the table no longer declares rawColumns(), so this pairing cannot be judged'
        );

        $isRaw = str_contains($raw[1], "'payment_type'");

        $this->assertSame(
            1,
            preg_match(
                "/->editColumn\('payment_type'.*?\n\s*\}\)/s",
                $source,
                $closure
            ),
            'the payment_type column no longer has an editColumn closure'
        );

        $escapes = (bool) preg_match('/return\s+e\(/', $closure[0]);

        /*
         * Stated as the implication rather than as two independent facts: a future change that
         * takes `payment_type` OUT of `rawColumns()` may drop the `e()` with it, and that is
         * correct — yajra would escape it. What must never happen is raw without `e()`.
         */
        $this->assertFalse(
            $isRaw && ! $escapes,
            'payment_type is in rawColumns() but its closure does not escape, so a translator-'
            . 'sourced label would be injected unescaped into every order list'
        );

        $this->assertTrue($isRaw, 'premise: payment_type is still a raw column, so this test has something to judge');
        $this->assertTrue($escapes);
    }
}
