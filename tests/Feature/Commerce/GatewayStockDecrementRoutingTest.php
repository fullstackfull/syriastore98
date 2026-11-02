<?php

namespace Tests\Feature\Commerce;

use Tests\TestCase;

/**
 * D-13 — THE FOUR GATEWAY RETURN HANDLERS DECREMENT THROUGH THE SHARED SERVICE.
 * =============================================================================
 *
 * Owner decision D-18 authorises routing the raw stock decrements in Khalti, PaiementPro,
 * CinetPay and Easebuzz through the existing `ProductStockService` / `AppliesCartStockChanges`
 * rather than each controller subtracting and saving on its own. This pins that.
 *
 * WHAT THE CHANGE ACTUALLY BUYS, stated precisely because one third of it turned out to be
 * weaker than it looks:
 *
 *  1. **The decrement is atomic.** `$remain_stock = $Product->product_stock - $qtyy; save()` is a
 *     read-modify-write: two concurrent returns both read the same figure and the second write
 *     erases the first. The service issues `UPDATE ... SET col = col - ?`, which cannot lose an
 *     update. This is the half that matters most and it cannot be driven here — SQLite `:memory:`
 *     with `CACHE_STORE=array` makes `Cache::lock` process-local, so no Feature test can stage
 *     two overlapping returns.
 *  2. **The decrement is accounted for**, in `$appliedStockChanges`. That is the prerequisite
 *     E13's durable stock evidence depends on: a movement record written from the shared service
 *     is only evidence if every writer goes through it
 *     (`docs/development/phase-2/E13-STOCK-EVIDENCE-ANALYSIS.md`).
 *  3. **A restoration on the three in-loop error exits — WHICH ARE NOT REACHABLE TODAY**, and
 *     that was measured rather than assumed. The exits fire when a cart line's product or variant
 *     does not resolve; but `Cart::cart_list_cookie()` dereferences those same rows earlier in
 *     the request (`Cart.php:531`, `:537`) and raises `Attempt to read property on null` first.
 *     Driving it produced a 500, not the exit. So the restore calls are defensive, recorded as
 *     ledger **F121**, and this file does NOT claim a basket gives its stock back.
 *
 * WHAT THIS FILE CANNOT SEE, named by the independent review of the change. It is source-text
 * only, so a mis-wiring that keeps the same text is invisible to it: `'variant'` where `'product'`
 * was meant, or `$Product` passed where `$datas` was meant. Behavioural cover for the decrement
 * exists on the **no-variant leg only** — `StockManagementSettingTest` drives cinetpay, easebuzz
 * and khalti, and `GatewayReturnTrustBoundaryTest::test_a_legitimate_guest_return_still_writes_its_order`
 * drives paiementpro, which the former's provider omits. The **stock-managed variant** leg and the
 * **unmanaged-variant-parent-product** leg have no behavioural assertion in any of the four, and
 * those are precisely the two where a kind/model swap would be silent. Closing that needs a
 * variant-bearing basket driven through each handler, which is its own piece of work.
 *
 * WHAT IS EXPLICITLY NOT DONE. The decrement still happens before the payment is known to have
 * succeeded. D-13's "close the unverified decrement" half needs E13's durable evidence, which the
 * owner has left BLOCKED, and the `$plan` fault recorded at D-13 is deliberately untouched: with
 * the decrement still reachable, fixing that first would convert a pre-decrement crash into a
 * working unverified decrement, which is the outcome the owner forbade.
 */
class GatewayStockDecrementRoutingTest extends TestCase
{
    /**
     * Routed through the shared trait: the four D-18 named, plus the three D-21 authorised on
     * 2026-10-27. `Api/ApiController` carries SIX sites across two methods rather than three,
     * which is why the per-file count below is derived rather than fixed.
     */
    private const AUTHORISED = [
        'KhaltiPaymnetController' => 3,
        'PaiementProPaymentController' => 3,
        'CinetPayController' => 3,
        'EasebuzzController' => 3,
        'AuthorizeNetPaymnetController' => 3,
        'PayUPaymentController' => 3,
        'Api/ApiController' => 6,
    ];

    /**
     * The writers D-18 does NOT cover, which must keep their raw decrements until D-21 is
     * answered. Listed so that "routed everything" cannot be mistaken for progress: enrolling
     * these needs a decision, not a sweep.
     *
     * `Api/ApiController` is here because it is the one that matters most and was missing from an
     * earlier version of this list: it holds SIX raw decrements on the STOREFRONT order writers,
     * so it was ratcheted in neither direction while the docblock below claimed the opposite.
     * `Api/DashboardController` — the Flutter admin app's own writer, ledger F118 — is
     * deliberately NOT listed, because it does not use the `$remain_stock` spelling this
     * assertion keys on; that is the whole point of F118 and a reason not to pretend this list is
     * a census.
     */
    private const NOT_AUTHORISED = [
        // D-21 authorised and converted AuthorizeNet, PayU and Api/ApiController on 2026-10-27.
        // `Api/DashboardController` is converted too but is NOT listed here, because it does not
        // use the trait: it already refused a shortfall and already rolled back, so it needed the
        // service's $requireAvailability argument instead — see its own contract test.
        // Nothing remains unconverted, so this list is empty and the assertion below says so.
    ];

    private function source(string $controller): string
    {
        $path = app_path('Http/Controllers/' . $controller . '.php');

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The same source with comments removed.
     *
     * Needed because the corrected comments in these files QUOTE the expression they replaced --
     * "it was `$remain_stock = $Product->product_stock - $qtyy`" -- which is exactly the string a
     * naive "no raw decrement survives" assertion looks for. This project has been bitten by a
     * comment satisfying a census before (the payment-callback sweep), and `token_get_all()` is
     * the answer it settled on.
     */
    private function code(string $controller): string
    {
        $out = '';

        foreach (token_get_all($this->source($controller)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    public function test_each_authorised_handler_routes_every_decrement_through_the_trait(): void
    {
        foreach (self::AUTHORISED as $controller => $expectedSites) {
            $source = $this->source($controller);

            $this->assertStringContainsString(
                'use \App\Http\Controllers\Concerns\AppliesCartStockChanges;',
                $source,
                "$controller no longer uses the shared stock trait, so its decrements are its own "
                . 'again — read-modify-write, unaccounted, and invisible to any movement record '
                . 'E13 later writes from the service.');

            $this->assertSame($expectedSites, substr_count($source, '$this->decrementStockOrFail($appliedStockChanges,'),
                "$controller must decrement at exactly $expectedSites site(s) — three per product "
                . 'loop: the stock-managed variant, the parent product behind an unmanaged '
                . 'variant, and the no-variant leg. A different number means a site was added or '
                . 'lost.');

            /*
             * AND EVERY ONE MUST PASS `false`, which is not a style point. With `true` the trait
             * gives the stock back ITSELF and returns a refusal payload -- and these twelve call
             * sites discard the return value, correctly, because `null` is the only reachable
             * return while the flag is false. Flip one to `true` and the trait restores the stock
             * at AppliesCartStockChanges:121 while the handler, ignoring the refusal it was
             * handed, carries on to `new Order()`: an order written against stock that was just
             * returned. The count above stops before the arguments, so it would not notice.
             */
            $this->assertSame($expectedSites, substr_count($source, ', $qtyy, false);'),
                "$controller has a decrementStockOrFail() call that does not pass "
                . 'rejectOnShortfall = false. These are post-capture paths: the gateway already '
                . 'has the money, so refusing here abandons a paid basket — and because the call '
                . 'sites discard the return value, the refusal would be silently ignored and the '
                . 'order written anyway, against stock the trait had just given back.');

            $this->assertStringNotContainsString('$remain_stock = ', $this->code($controller),
                "$controller has a raw read-modify-write decrement again. Two concurrent returns "
                . 'both read the same figure and the second write erases the first. (Comments are '
                . 'stripped before this check, because the corrected ones quote the old expression.)');
        }
    }

    /**
     * Every product loop in the file as [firstLine, lastLine], by brace depth.
     *
     * Plural deliberately: `Api/ApiController` carries two order writers with a loop each (plus a
     * third loop that decrements nothing), and an earlier version of this file assumed one — it
     * then reported a perfectly correct use in the second writer as being "after the loop".
     *
     * Takes comment-stripped lines, because a brace inside a comment would shift the depth count
     * and silently move every range after it.
     */
    private function productLoopRanges(array $lines): array
    {
        $ranges = [];

        foreach ($lines as $i => $line) {
            if (! str_contains($line, 'foreach ($products as $key => $product)')) {
                continue;
            }

            $depth = 0;

            for ($j = $i; $j < count($lines); $j++) {
                $depth += substr_count($lines[$j], '{') - substr_count($lines[$j], '}');

                if ($depth === 0 && $j > $i) {
                    $ranges[] = [$i, $j];
                    break;
                }
            }
        }

        return $ranges;
    }

    /**
     * Comment text removed, LINE NUMBERING PRESERVED: each comment token becomes just its own
     * newlines. Needed because the assertion below reads line-by-line and these files carry
     * comments that QUOTE the exits they describe -- AuthorizeNet:727 contains the literal
     * "return $this->error(...)" inside a block comment. Stripping comments without keeping the
     * line count would misalign every brace depth after the first docblock.
     */
    private function codeLines(string $controller): array
    {
        $out = '';

        foreach (token_get_all($this->source($controller)) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? str_repeat("\n", substr_count($token[1], "\n"))
                    : $token[1];
            } else {
                $out .= $token;
            }
        }

        return explode("\n", $out);
    }

    /**
     * EVERY in-loop error exit restores -- counted, not floored.
     *
     * This assertion replaces an `assertGreaterThanOrEqual($loops, ...)` one, and the reason is
     * a defect it failed to catch. The first D-21 pass found each loop's exits with a detector
     * keyed on `return $this->error(`, so it never saw AuthorizeNet's TWO
     * `return redirect()->back()->with('error', ...)` exits at :778 and :818 -- the
     * foreign-product refusal and the missing-variant refusal. Both left the lines already
     * processed decremented, post-capture, with no order row. A floor of one restore per loop
     * was satisfied by the three exits that DID restore, so CI saw nothing; an independent
     * security review of the change found them by counting.
     *
     * So this does not count occurrences, and it does NOT enumerate exit spellings either. A
     * first version of it listed `$this->error(`, `redirect()`, `response()->json(` and
     * `abort()` — and promptly reported EasebuzzController as having no exits at all, because
     * that handler returns `Utility::error(...)`. A fourth spelling defeating the detector is
     * the exact failure being guarded against, so there is no list: it locates each product
     * loop by brace depth and asserts a restore stands immediately before EVERY `return`
     * inside it.
     *
     * That is sound here because a census of all seven handlers found 30 returns inside a
     * stock-taking loop and all 30 are error exits — not one is a value return or an early
     * success. Should a legitimate non-error return ever be added inside one of these loops,
     * this test will fail and the exception belongs HERE, named, rather than in a pattern that
     * quietly stops matching.
     */
    public function test_each_authorised_handler_gives_back_what_it_took_on_every_in_loop_exit(): void
    {
        foreach (self::AUTHORISED as $controller => $expectedSites) {
            $source = $this->source($controller);
            $loops = intdiv($expectedSites, 3);

            $this->assertSame($loops, substr_count($source, '$appliedStockChanges = [];'),
                "$controller must declare one accumulator per product loop, outside that loop. "
                . 'An extra one means a nested declaration silently discards what the outer loop '
                . 'recorded; a missing one is an undefined variable at the exits.');

            $lines = $this->codeLines($controller);
            $checked = 0;

            foreach ($this->productLoopRanges($lines) as [$from, $to]) {
                /* Only the loops that actually take stock. A loop with no decrement has nothing
                   to give back, and `Api/ApiController` has one of those. */
                $takesStock = false;
                for ($i = $from; $i <= $to; $i++) {
                    if (str_contains($lines[$i], 'decrementStockOrFail')) {
                        $takesStock = true;
                        break;
                    }
                }

                if (! $takesStock) {
                    continue;
                }

                for ($i = $from; $i <= $to; $i++) {
                    $line = trim($lines[$i]);

                    if (! str_starts_with($line, 'return')) {
                        continue;
                    }

                    $preceding = '';
                    for ($b = $i - 1; $b >= $from && $b >= $i - 3; $b--) {
                        if (trim($lines[$b]) !== '') {
                            $preceding = trim($lines[$b]);
                            break;
                        }
                    }

                    $this->assertSame('$this->restoreStockDecrements($appliedStockChanges);', $preceding,
                        "$controller line " . ($i + 1) . " exits the product loop without giving "
                        . 'back the stock it already took from the earlier lines of the same '
                        . 'basket: ' . $line . ' — these are post-capture paths, so the shopper '
                        . 'has been charged, no order row is written, and the stock stays gone. '
                        . 'Every in-loop error exit must be preceded by '
                        . 'restoreStockDecrements($appliedStockChanges).');

                    $checked++;
                }
            }

            $this->assertGreaterThan(0, $checked,
                "Found no in-loop returns at all in $controller, which means the loop finder "
                . 'above stopped matching — not that the handler became safe. Re-read it before '
                . 'trusting this test again.');
        }
    }

    /**
     * The accumulator must be declared BEFORE the loop and every use must be inside it — an
     * accumulator declared in the wrong scope is an undefined variable at the exits, which PHP
     * does not catch at lint time and which no happy-path test would reach.
     */
    public function test_the_accumulator_is_in_scope_at_every_use(): void
    {
        foreach (array_keys(self::AUTHORISED) as $controller) {
            $lines = $this->codeLines($controller);
            $ranges = $this->productLoopRanges($lines);

            $this->assertNotEmpty($ranges, "$controller: no product loop was found");

            $declarations = [];

            foreach ($lines as $i => $line) {
                if (str_contains($line, '$appliedStockChanges = [];')) {
                    $declarations[] = $i;
                }
            }

            $this->assertNotEmpty($declarations, "$controller: no accumulator");

            /* Each declaration must sit immediately before a loop, never inside one. Inside, every
               iteration resets it and the exits give back only the current line. */
            foreach ($declarations as $d) {
                $inside = array_filter($ranges, fn (array $r): bool => $r[0] < $d && $d < $r[1]);
                $this->assertSame([], $inside,
                    "$controller declares the accumulator INSIDE a product loop at line "
                    . ($d + 1) . ', so each iteration resets what the exits are meant to give back.');
            }

            foreach ($lines as $i => $line) {
                if (! str_contains($line, '$this->decrementStockOrFail($appliedStockChanges,')
                    && ! str_contains($line, '$this->restoreStockDecrements($appliedStockChanges);')) {
                    continue;
                }

                $enclosing = array_filter($ranges, fn (array $r): bool => $r[0] < $i && $i < $r[1]);

                $this->assertNotSame([], $enclosing,
                    "$controller uses the accumulator at line " . ($i + 1) . ', which is outside '
                    . 'every product loop — on a path that skipped the loop it is an undefined '
                    . 'variable, which PHP does not catch at lint time and no happy-path test '
                    . 'would reach.');

                /* And a declaration must precede it. */
                $before = array_filter($declarations, fn (int $d): bool => $d < $i);
                $this->assertNotSame([], $before,
                    "$controller uses the accumulator at line " . ($i + 1)
                    . ' before any declaration of it.');
            }
        }
    }

    /**
     * THE SCOPE LIMIT, asserted so it cannot drift — and it is now EMPTY, which is the point.
     *
     * D-18 named four handlers and D-21 authorised the rest, so every writer this list was
     * guarding has been converted. The assertion is kept rather than deleted: it states that
     * nothing is knowingly left on a raw read-modify-write, and it fails the moment someone adds
     * a writer here without converting it — or converts one without the owner's authority, which
     * is the direction it originally guarded.
     *
     * `Api/DashboardController` is deliberately absent from both lists. It was converted too, but
     * NOT through this trait: it already refused a shortfall and already rolled back, so what it
     * needed was the service's `$requireAvailability` argument. Its contract is pinned separately
     * by `tests/Feature/Security/AdminApiStockRefusalContractTest.php`, because it is the shipped
     * Flutter admin app's order writer and its refusal shape is a client contract.
     */
    /**
     * D-13, 2026-10-29 — **THE ORDER WRITE IS COMPENSATED, not just the loop.**
     *
     * `test_each_authorised_handler_gives_back_what_it_took_on_every_in_loop_exit` covers the
     * exits INSIDE the loop. What it cannot see is the window after it: the order, its billing
     * detail, its tax rows, its notes and its coupon usage are written with no transaction, and
     * until 2026-10-29 with no catch — measured across all seven first-party gateway writers, the
     * only `catch` blocks in that region being the narrow SMTP ones around mail. An exception
     * there left the inventory gone and, depending where it landed, no order at all.
     *
     * DRIVEN, not only read: `GatewayStockBeforeVerificationTest::
     * test_a_failure_during_the_order_write_gives_the_stock_back` injects a failure at
     * `Order::creating` and watches `product_stock` come back. This is the ratchet that stops the
     * wrapper being removed from any of the four.
     *
     * THE WHOLE BODY IS WRAPPED rather than the order-write region, and the reason is recorded
     * because the obvious version fails: the order write sits inside an `if` whose `else` comes
     * later, so a `try` opened at `$order = new Order()` and closed before the method's final
     * brace is brace-balanced but not STATEMENT-balanced — the inserted `}` closes the `if`, and
     * all four files stop parsing. A method body is balanced by definition.
     */
    public function test_each_gateway_return_handler_compensates_a_failed_order_write(): void
    {
        /*
         * SEVEN NAMES, AND THE SENTENCE THAT USED TO BE HERE WAS FALSE. It read: *"the other three
         * authorised writers reach this property through `OrderWriteTransactionTest`, whose data
         * provider covers them and not these."* `OrderWriteTransactionTest::writers()` has exactly
         * three rows -- `PaymentController::getProductStatus`, `PaymentService::getProductStatus`
         * and `PaymentController::whatsapp`. `AuthorizeNetPaymnetController`,
         * `PayUPaymentController` and the two `Api\ApiController` writers were in neither file.
         * All four decremented stock through `decrementStockOrFail()` and wrote the order
         * afterwards with nothing to undo the loop: the same defect, four more times, behind a
         * comment asserting it was handled. An open hole with a note saying it is closed is worse
         * than an open hole, because a reviewer has no reason to look. Fixed 2026-10-30; the four
         * are wrapped and the list below is now the complete set of D-13 writers.
         *
         * TWO OF THE FOUR ARE ALSO DRIVEN, not only read:
         * `ApiOrderWriterStockCompensationTest` puts a real Sanctum token and a real cart through
         * `POST api/{slug}/place-order` and `order-save` and watches `product_stock` come back.
         * AuthorizeNet needs a live Authorize.Net SDK response and PayU a valid salted hash, so
         * for those two the source ratchet below is the whole of the evidence -- stated here
         * rather than implied, which is the mistake this comment is a correction of.
         *
         * `code()`, NOT `source()` -- and that is the independent review's correction. `source()`
         * is the RAW file, and the corrected comments in these handlers QUOTE the expressions they
         * describe; this very file provides `code()` precisely because of that trap (see its note).
         * Nothing in the four files satisfied the assertions from a comment today, so it was not a
         * live false pass -- it was one explanatory sentence away from being one.
         */
        /*
         * COUNTED, NOT MERELY PRESENT — and the independent review is why. The first version of
         * this block iterated the seven FILES and used `assertStringContainsString` plus
         * single-match `assertMatchesRegularExpression`. `Api/ApiController` holds **two** guarded
         * writers, so deleting `ordersave`'s entire try/catch left `place_order`'s copy satisfying
         * every assertion here and the ratchet green over a reopened hole. A per-file assertion
         * cannot guard a per-METHOD property.
         *
         * The expected count comes from `AUTHORISED` by the same derivation the in-loop test above
         * uses — `intdiv($expectedSites, 3)`, three decrement sites per writer — so a writer added
         * to that map without a guard fails here, rather than the two numbers drifting apart.
         */
        foreach (self::AUTHORISED as $controller => $expectedSites) {
            $source = $this->code($controller);
            $guards = intdiv($expectedSites, 3);

            $this->assertSame(
                $guards,
                substr_count($source, 'catch (\\Throwable $ssStockGuardError) {'),
                "$controller must carry exactly $guards order-write guard(s) — one per authorised"
                . ' writer. A missing one means an exception after that writer\'s stock loop leaves'
                . ' the inventory consumed and, depending where it lands, no order either; an extra'
                . ' one means a nested catch whose scope has not been read.'
            );

            /*
             * THE GATE IS PART OF THE CONTRACT, not an implementation detail -- and the first
             * version of this assertion FORBADE it. It required the restore to be the FIRST
             * statement in the catch, so adding `if (! $ssOrderWritten)` broke the ratchet rather
             * than the ratchet catching the absence of the gate. The review caught that too.
             *
             * Restore only when the order does NOT exist is the invariant the project already
             * holds elsewhere: `PaymentController::getProductStatus` reaches
             * `restoreStockDecrements()` only in company with `DB::rollBack()`, and
             * `OrderWriteTransactionTest` asserts `assertGreaterThan($rollback, $restore)`.
             */
            /*
             * THE GATE NOW ASKS TWO QUESTIONS AND THE RATCHET REQUIRES BOTH. The flag alone was
             * not sufficient: it is assigned by the statement AFTER `$order->save()`, so it is
             * still false while the `created` model event runs, and `OrderObserver::created()`
             * catches `\Exception` but not `\Throwable`. A `TypeError` in
             * `OrderFinancialService::createForOrder()` therefore escaped, propagated out of
             * `save()` and restored the stock behind a COMMITTED order row -- an oversell
             * introduced by D-13's own fix. `$order->exists` closes it, because
             * `Model::performInsert()` sets `exists` before firing `created`. Both limbs are
             * load-bearing and `ApiOrderWriterStockCompensationTest` fails on the removal of
             * either one.
             */
            $this->assertSame(
                $guards,
                preg_match_all(
                    '/\$ssOrderClaimsIt = \(\$ssOrderWritten \?\? false\)\s*\|\|\s*\(isset\(\$order\) && \$order instanceof \\\\App\\\\Models\\\\Order && \$order->exists\);\s*if \(! \$ssOrderClaimsIt\) \{\s*\$this->restoreStockDecrements\(\$appliedStockChanges \?\? \[\]\);/',
                    $source
                ),
                "$controller gives the stock back without asking whether the ORDER was written."
                . ' There is no transaction here, so a throw after `$order->save()` would return'
                . " the shopper's goods to the catalogue while their order stands — the inverse"
                . ' of the defect this closes.'
            );

            $this->assertSame(
                $guards,
                substr_count($source, '$ssOrderWritten = true;'),
                "$controller must record the order write once per authorised writer. Without it the"
                . ' gate above falls back to `$order->exists` alone, which is correct but leaves no'
                . ' record of WHERE the row became authoritative — and with too many, one writer is'
                . ' claiming another\'s write.'
            );

            $this->assertSame(
                $guards,
                preg_match_all(
                    '/\$this->restoreStockDecrements\(\$appliedStockChanges \?\? \[\]\);\s*\}\s*throw \$ssStockGuardError;/',
                    $source
                ),
                "$controller swallows the failure instead of rethrowing it. The compensation adds"
                . ' a restore; it must not change the response the caller sees, which is the same'
                . ' rule OrderWriteTransactionTest states for the other three writers.'
            );
        }
    }

    public function test_the_unauthorised_writers_are_left_alone(): void
    {
        $this->assertSame([], self::NOT_AUTHORISED,
            'a writer has been added to NOT_AUTHORISED. If the owner has authorised converting '
            . 'it, convert it and move it to AUTHORISED; if not, it stays here and this test is '
            . 'the record of that.');

        foreach (self::NOT_AUTHORISED as $controller) {
            $this->assertStringContainsString('$remain_stock = ', $this->code($controller),
                "$controller is listed as unauthorised but has been routed through the shared "
                . 'service anyway.');
        }

        /* The other half of the same claim, and the one that actually bites: no writer this file
           calls authorised may quietly fall back to a raw decrement. */
        foreach (array_keys(self::AUTHORISED) as $controller) {
            $this->assertStringNotContainsString('$remain_stock = ', $this->code($controller),
                "$controller is listed as converted but still carries a raw read-modify-write.");
        }
    }
}
