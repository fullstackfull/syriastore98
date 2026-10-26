<?php

namespace Tests\Unit\Payments;

use PHPUnit\Framework\TestCase;

/**
 * F46 — an order's customer may never be taken from request input on an authenticated branch.
 * =============================================================================================
 *
 * TEN COPIES OF ONE BLOCK, AND THE REVIEW THAT FOUND IT LOOKED AT ONE FILE. The finding was
 * recorded against `PaymentService.php` alone:
 *
 *     $product_order_id = '0' . date('YmdHis');
 *     $is_guest = 1;
 *     if (auth('customers')->check()) {
 *         $product_order_id = $request->customer_id . date('YmdHis');   // <-- request input
 *         $is_guest = 0;
 *     }
 *     ...
 *     $order->customer_id = !empty($request->customer_id) ? $request->customer_id : 0;
 *
 * A census found the same block, byte for byte, in NINE files: PaymentService, AuthorizeNet,
 * PayU, PaymentController (twice), NMIPay, Khalti, Easebuzz, PaiementPro and CinetPay. Fixing
 * only the one that had been read would have left the control bypassable by choosing a different
 * payment method — the same mistake, in the same subsystem, that ledger finding F50 records
 * ("P01-SEC-14 E put this refusal on `payment_sheet` alone... so the whole control was bypassed
 * by placing a COD order instead of paying by card").
 *
 * WHY THE INSERT DOES NOT SAVE YOU. `orders.customer_id` is `unsignedBigInteger`, so the obvious
 * objection is that a non-numeric value would be rejected. It is not: `config/database.php:55`
 * sets `'strict' => false`, so MySQL coerces that column to 0 — while `product_order_id` is a
 * STRING column and keeps whatever was posted, whole. That is how the order list's reference cell
 * became an injection vector. The rendering half is pinned separately
 * (`OrderListCellEscapingTest`); this is the input half.
 *
 * WHAT THIS RATCHETS. On any branch guarded by `auth('customers')->check()`, the id must come
 * from the session. A ratchet rather than ten more fixes, because three rounds of review in this
 * phase have each found the previous round's siblings, and the next copy of this block will be
 * pasted by somebody adding a payment gateway.
 *
 * TWO SITES ARE DELIBERATELY EXEMPT and are listed by name below, with the reason. An exemption
 * that is not enumerated is a hole, so the list is exact and the test fails if a file on it stops
 * matching — otherwise a later refactor would quietly widen the exemption.
 */
class OrderAttributionRatchetTest extends TestCase
{
    /**
     * Files where `$request->customer_id` is the CORRECT source, with the reason each one is not
     * the defect. Keyed by path so a failure names the file.
     */
    private const EXEMPT = [
        // The MERCHANT is the authenticated principal here (`auth()`, not `auth('customers')`),
        // the handler is gated on `isAbleTo('Create Pos')`, and the merchant legitimately chooses
        // which customer the counter sale belongs to. Verified at PosController.php:179-268.
        'app/Http/Controllers/PosController.php' =>
            'point of sale: the merchant picks the customer, under the web guard and Create Pos',

        // `product_wishlist()` OVERWRITES the posted value with the session's before any use:
        // `$customer_id = auth('customers')->user()->id ?? null;` then
        // `$request->merge(['customer_id' => $customer_id]);` (WishlistController.php:171-176).
        // A non-customer token yields null, and the validator then refuses — fail-closed.
        'app/Http/Controllers/WishlistController.php' =>
            'the posted id is replaced by the session id via $request->merge() before use',
    ];

    private function sources(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app'));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** Comments removed, so no assertion here can be satisfied by the prose explaining it. */
    private function code(string $path): string
    {
        $s = (string) file_get_contents($path);
        $s = preg_replace('#/\*.*?\*/#s', '', $s);

        return (string) preg_replace('#//[^\n]*#', '', $s);
    }

    public function test_the_exempt_files_still_exist_and_still_match(): void
    {
        // A premise. If one of these is renamed or rewritten, the exemption must be re-read
        // rather than silently covering nothing.
        foreach (self::EXEMPT as $rel => $why) {
            $path = dirname(__DIR__, 3) . '/' . $rel;
            $this->assertFileExists($path, $rel . ' is exempt but no longer exists');
            $this->assertStringContainsString('$request->customer_id', $this->code($path),
                $rel . ' is exempt for "' . $why . '" but no longer reads $request->customer_id. '
                . 'Re-read it and drop the exemption if it is no longer needed.');
        }
    }

    public function test_no_order_reference_is_built_from_request_input(): void
    {
        $offenders = [];

        foreach ($this->sources() as $path) {
            $rel = ltrim(str_replace(dirname(__DIR__, 3), '', $path), '/');
            if (isset(self::EXEMPT[$rel])) {
                continue;
            }
            $code = $this->code($path);
            if (! preg_match_all('/\$product_order_id\s*=\s*\$request->customer_id/', $code, $m)) {
                continue;
            }
            $offenders[] = $rel . ' (' . count($m[0]) . ' site' . (count($m[0]) === 1 ? '' : 's') . ')';
        }

        $this->assertSame([], $offenders,
            "An order REFERENCE is built from \$request->customer_id:\n  "
            . implode("\n  ", $offenders)
            . "\n\n`orders.product_order_id` is a string column, so a posted value is carried whole"
            . "\ninto the reference the merchant's order list renders. Use auth('customers')->id()"
            . "\non the authenticated branch. See F46 in the ledger.");
    }

    public function test_no_order_ledger_row_is_attributed_from_request_input(): void
    {
        $offenders = [];

        foreach ($this->sources() as $path) {
            $rel = ltrim(str_replace(dirname(__DIR__, 3), '', $path), '/');
            if (isset(self::EXEMPT[$rel])) {
                continue;
            }
            $code = $this->code($path);
            // `$order->customer_id = ... $request->customer_id ...` in any arrangement
            if (! preg_match_all('/\$order(?:s)?->customer_id\s*=[^;\n]*\$request->customer_id/', $code, $m)) {
                continue;
            }
            $offenders[] = $rel . ' (' . count($m[0]) . ')';
        }

        $this->assertSame([], $offenders,
            "An ORDER is attributed to \$request->customer_id:\n  " . implode("\n  ", $offenders)
            . "\n\n`orders.customer_id` is unsignedBigInteger, but config/database.php sets"
            . "\n'strict' => false, so a non-numeric value coerces to 0 instead of being refused."
            . "\nUse auth('customers')->id() on the authenticated branch. See F46.");
    }

    public function test_every_authenticated_checkout_branch_reads_the_session(): void
    {
        // The positive form of the same rule: wherever the block appears, the line inside the
        // `auth('customers')->check()` guard must take the id from the session. This catches a
        // copy that avoided both patterns above by assigning through a differently named local.
        $sites = 0;
        $bad = [];

        foreach ($this->sources() as $path) {
            $rel = ltrim(str_replace(dirname(__DIR__, 3), '', $path), '/');
            if (isset(self::EXEMPT[$rel])) {
                continue;
            }
            $code = $this->code($path);
            if (! preg_match_all(
                "/if \(auth\('customers'\)->check\(\)\) \{(?P<body>.{0,400}?)\}/s",
                $code, $matches, PREG_SET_ORDER
            )) {
                continue;
            }
            foreach ($matches as $match) {
                if (! str_contains($match['body'], '$product_order_id')) {
                    continue;   // not the checkout block
                }
                $sites++;
                /* THE INVARIANT IS "NOT REQUEST INPUT", not "calls auth()->id() literally".
                 * The first version demanded the literal call and flagged
                 * Api/ApiController.php, which is correct code: it uses `$customer_id`, a local
                 * resolved from the authenticated customer far above (`$customer_id =
                 * $customer->id`, the P1-SEC-23/24 fix). Demanding one spelling of a correct
                 * thing is how a ratchet gets its expectations rubber-stamped. */
                if (str_contains($match['body'], '$request->customer_id')) {
                    $bad[] = $rel;
                }
            }
        }

        // A premise, so this cannot pass by finding nothing.
        $this->assertGreaterThanOrEqual(11, $sites,
            'the checkout block was found ' . $sites . ' times; it was 11 when F46 was fixed, so '
            . 'either the regex no longer matches it or the blocks have moved. Re-read them.');
        $this->assertSame([], array_values(array_unique($bad)),
            'a checkout block guarded by auth(\'customers\')->check() does not read the session');
    }
}
