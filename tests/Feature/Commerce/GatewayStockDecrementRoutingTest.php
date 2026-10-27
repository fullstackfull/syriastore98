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
    /** The four D-18 authorises, and only those four. */
    private const AUTHORISED = [
        'KhaltiPaymnetController',
        'PaiementProPaymentController',
        'CinetPayController',
        'EasebuzzController',
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
        'AuthorizeNetPaymnetController',
        'PayUPaymentController',
        'Api/ApiController',
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
        foreach (self::AUTHORISED as $controller) {
            $source = $this->source($controller);

            $this->assertStringContainsString(
                'use \App\Http\Controllers\Concerns\AppliesCartStockChanges;',
                $source,
                "$controller no longer uses the shared stock trait, so its decrements are its own "
                . 'again — read-modify-write, unaccounted, and invisible to any movement record '
                . 'E13 later writes from the service.');

            $this->assertSame(3, substr_count($source, '$this->decrementStockOrFail($appliedStockChanges,'),
                "$controller must decrement at exactly three sites (the stock-managed variant, the "
                . 'parent product behind an unmanaged variant, and the no-variant leg). A different '
                . 'number means a site was added or lost.');

            /*
             * AND EVERY ONE MUST PASS `false`, which is not a style point. With `true` the trait
             * gives the stock back ITSELF and returns a refusal payload -- and these twelve call
             * sites discard the return value, correctly, because `null` is the only reachable
             * return while the flag is false. Flip one to `true` and the trait restores the stock
             * at AppliesCartStockChanges:121 while the handler, ignoring the refusal it was
             * handed, carries on to `new Order()`: an order written against stock that was just
             * returned. The count above stops before the arguments, so it would not notice.
             */
            $this->assertSame(3, substr_count($source, ', $qtyy, false);'),
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

    public function test_each_authorised_handler_gives_back_what_it_took_on_every_in_loop_exit(): void
    {
        foreach (self::AUTHORISED as $controller) {
            $source = $this->source($controller);

            $this->assertSame(1, substr_count($source, '$appliedStockChanges = [];'),
                "$controller must declare the accumulator exactly once, outside the loop. Twice "
                . 'means a nested declaration silently discards what the outer loop recorded.');

            $this->assertSame(3, substr_count($source, '$this->restoreStockDecrements($appliedStockChanges);'),
                "$controller must give the applied decrements back at each of its three in-loop "
                . 'error exits. Fewer means an exit that still consumes stock and writes no order.');
        }
    }

    /**
     * The accumulator must be declared BEFORE the loop and every use must be inside it — an
     * accumulator declared in the wrong scope is an undefined variable at the exits, which PHP
     * does not catch at lint time and which no happy-path test would reach.
     */
    public function test_the_accumulator_is_in_scope_at_every_use(): void
    {
        foreach (self::AUTHORISED as $controller) {
            $lines = explode("\n", $this->source($controller));

            $start = null;
            foreach ($lines as $i => $line) {
                if (str_contains($line, 'foreach ($products as $key => $product)')) {
                    $start = $i;
                    break;
                }
            }

            $this->assertNotNull($start, "$controller: the product loop was not found");

            $depth = 0;
            $end = null;
            for ($i = $start; $i < count($lines); $i++) {
                $depth += substr_count($lines[$i], '{') - substr_count($lines[$i], '}');
                if ($depth === 0 && $i > $start) {
                    $end = $i;
                    break;
                }
            }

            $this->assertNotNull($end, "$controller: the product loop never closes");

            $declaredAt = null;
            foreach ($lines as $i => $line) {
                if (str_contains($line, '$appliedStockChanges = [];')) {
                    $declaredAt = $i;
                    break;
                }
            }

            $this->assertNotNull($declaredAt, "$controller: no accumulator");
            $this->assertLessThan($start, $declaredAt,
                "$controller declares the accumulator inside the loop, so every iteration resets "
                . 'it and the exits give back only the current line.');

            foreach ($lines as $i => $line) {
                if (str_contains($line, '$this->decrementStockOrFail($appliedStockChanges,')
                    || str_contains($line, '$this->restoreStockDecrements($appliedStockChanges);')) {
                    $this->assertGreaterThan($start, $i,
                        "$controller uses the accumulator before the loop at line " . ($i + 1));
                    $this->assertLessThan($end, $i,
                        "$controller uses the accumulator after the loop at line " . ($i + 1)
                        . ', where it may be an undefined variable on a path that skipped the loop.');
                }
            }
        }
    }

    /**
     * THE SCOPE LIMIT, asserted so it cannot drift. D-18 names four handlers. Routing the others
     * is D-21 and needs its own decision — two of them are API paths the Flutter apps depend on.
     */
    public function test_the_unauthorised_writers_are_left_alone(): void
    {
        foreach (self::NOT_AUTHORISED as $controller) {
            $source = $this->source($controller);

            $this->assertStringContainsString('$remain_stock = ', $this->code($controller),
                "$controller has been routed through the shared service. D-18 authorises Khalti, "
                . 'PaiementPro, CinetPay and Easebuzz by name and not this one; enrolling it is '
                . 'D-21, and two of the writers it covers are API paths the Flutter apps depend '
                . 'on. If the owner has answered D-21, move it to AUTHORISED rather than '
                . 'deleting this assertion.');
        }
    }
}
