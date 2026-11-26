<?php

namespace Tests\Unit\Security;

use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * NO CHECKOUT PATH READS A `DeliveryAddress` BY A CALLER-SUPPLIED ID ALONE.
 * =================================================================================================
 *
 * `DeliveryAddress` carries **no** `StoreScope` and is **not** in `config/tenancy.php`'s
 * `scoped_models` — both halves measured, and both needed, because `getGlobalScopes()` answering
 * non-empty is not the same question as "is it scoped". So every row in the database answers a bare
 * `find()`: another shopper's address in the same store, or another tenant's customer's.
 *
 * THE SAME STATEMENT WAS LIVE IN THREE PLACES AND WAS FIXED IN THREE SEPARATE PACKAGES, which is
 * why this ratchet exists rather than a fourth fix:
 *
 *   `Api\ApiController::apply_coupon`        GAP-235 — the three merged values feed `cart_list()`,
 *                                            which selects tax methods BY JURISDICTION, so the
 *                                            quote reads the foreign row's country/state/city back
 *   `CartController::get_shipping_method`    GAP-243 — byte-for-byte the same statement on the web
 *                                            storefront, found by the review of the API fix going
 *                                            looking for it elsewhere
 *   `CartController::get_tax_data`           GAP-144 — the last copy, and the one that was INERT:
 *                                            its values are unconditionally reassigned before use.
 *                                            Scoped anyway, because an inert read becomes live the
 *                                            moment somebody deletes a reassignment below it
 *
 * A fourth site, also `CartController::get_shipping_method`, assigned a bare `find()` to a variable
 * nothing read — the leftover of the GAP-243 fix, where the scoped version was added and the old
 * line was not deleted. Removed, not scoped: the risk was never that read, it is that the next
 * person to need an address copies the line without a predicate.
 *
 * ---------------------------------------------------------------------------------------------
 * THIS ASSERTION MUST STRIP COMMENTS, AND THAT IS NOT A PRECAUTION — IT IS THE ONLY WAY IT WORKS.
 *
 * Both files **contain the vulnerable statement verbatim, in prose**: `CartController` quotes
 * `DeliveryAddress::find($request->billing_address_id)` inside the GAP-243 docblock explaining what
 * was removed, and `ApiController` does the same for GAP-235. A grep for the bad pattern is
 * therefore satisfied by the comment describing it — and this project has been bitten by that exact
 * shape at least five times, in three syntaxes. It also fails the other way: a census that reads
 * comments can be BROKEN by someone rewording a docblock.
 *
 * So the source goes through `StripsSourceComments::sourceWithoutComments()` first, and a premise
 * case proves the stripping is doing work by asserting the raw file DOES contain what the stripped
 * one does not. Without that premise this test would pass just as happily if the stripper silently
 * returned everything.
 */
final class DeliveryAddressReadsArePredicatedTest extends TestCase
{
    use StripsSourceComments;

    /** Every first-party file that resolves an address on the checkout or quote path. */
    private const CHECKOUT_PATH = [
        'app/Http/Controllers/CartController.php',
        'app/Http/Controllers/Api/ApiController.php',
    ];

    /**
     * THE PREMISE. If `sourceWithoutComments()` ever stops removing comments, the assertion below
     * becomes vacuous in the direction that matters — it would pass on a file whose only occurrence
     * is prose, and keep passing when a real one is added beside it.
     */
    public function test_the_premise_that_the_files_really_do_quote_the_bad_statement_in_prose(): void
    {
        $raw = file_get_contents(base_path('app/Http/Controllers/CartController.php'));

        $this->assertStringContainsString('DeliveryAddress::find($request->billing_address_id)', $raw,
            'premise: this file no longer quotes the vulnerable statement in a comment. If the '
            . 'docblocks were rewritten, pick another premise — but do NOT drop this case, because '
            . 'it is what proves the comment stripping below is load-bearing rather than decorative.');

        $this->assertStringNotContainsString(
            'DeliveryAddress::find($request->billing_address_id)',
            $this->sourceWithoutComments('app/Http/Controllers/CartController.php'),
            'the comment stripper is not removing that quotation, so the assertion below is '
            . 'measuring prose rather than code and would pass on a live defect.'
        );
    }

    /**
     * THE RATCHET: no bare `DeliveryAddress::find(...)` survives on these paths.
     *
     * `find()` by itself is a LIVENESS check on an id the caller chose, not an identity check. The
     * shape that is correct here is `where('id', $id)->where('customer_id', auth('customers')->id())`
     * — the authenticated principal, never a request value, because a predicate built from
     * caller-supplied input only compares the caller's own two numbers.
     */
    public function test_no_checkout_path_resolves_an_address_by_id_alone(): void
    {
        $offenders = [];

        foreach (self::CHECKOUT_PATH as $file) {
            $code = $this->sourceWithoutComments($file);

            if (preg_match_all('/DeliveryAddress::find\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$match, $offset]) {
                    $offenders[] = sprintf('%s (line %d)', $file, substr_count(substr($code, 0, $offset), "\n") + 1);
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "A checkout path resolves a `DeliveryAddress` by id ALONE: %s.\n\n"
            . "`DeliveryAddress` has no global scope and is not allowlisted in "
            . "config/tenancy.php, so `find()` answers with EVERY row in the database — another "
            . "shopper's address in this store, or another tenant's customer's. The three values "
            . "it yields feed `cart_list()`, which selects the merchant's tax methods by "
            . "JURISDICTION, so a quote reads the foreign row's country, state and city straight "
            . "back out; on the order path `OrderBillingDetail` then renders them to the attacker "
            . "as place names on their own order.\n\n"
            . "Write `DeliveryAddress::where('id', \$id)->where('customer_id', "
            . "auth('customers')->id())->first()` instead, and merge into the existing "
            . "`billing_info` rather than replacing it. This was fixed three times already — "
            . "GAP-235, GAP-243, GAP-144 — each time because the previous fix's reviewer went "
            . "looking for the same statement elsewhere and found it.",
            implode(', ', $offenders)
        ));
    }

    /**
     * EVERY ADDRESS READ ON THESE PATHS CARRIES AN OWNER PREDICATE, not merely an id.
     *
     * THE FIRST DRAFT OF THIS FILE MISSED THIS AND A MUTANT PROVED IT. The ratchet above only
     * looks for `DeliveryAddress::find(`, so rewriting the fixed read as
     * `DeliveryAddress::where('id', $address_id)->first()` — the owner predicate deleted, the
     * method name changed — **passed every case in this file**. The method name was never the
     * defect; the missing `customer_id` was. A ratchet that names the symptom and not the cause is
     * one a later edit walks straight through, which is the same weakness an independent review
     * found in another of today's test files.
     *
     * So each `DeliveryAddress::where('id', ...)` is now required to carry a `customer_id`
     * predicate within the statement that opens it. Matched over comment-stripped source to the
     * statement's terminating `;`, so a predicate three statements away cannot satisfy it.
     */
    public function test_every_address_read_carries_an_owner_predicate(): void
    {
        $unowned = [];

        foreach (self::CHECKOUT_PATH as $file) {
            $code = $this->sourceWithoutComments($file);

            /* Each read, from `DeliveryAddress::where('id'` to the `;` that ends its statement. */
            if (! preg_match_all("/DeliveryAddress::where\(\s*'id'.*?;/s", $code, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($m[0] as [$statement, $offset]) {
                if (! str_contains($statement, 'customer_id')) {
                    $unowned[] = sprintf(
                        '%s (line %d)',
                        $file,
                        substr_count(substr($code, 0, $offset), "\n") + 1
                    );
                }
            }
        }

        $this->assertSame([], $unowned, sprintf(
            "An address read on a checkout path names an id but no OWNER: %s.\n\n"
            . "`where('id', \$id)` alone is the same defect as `find(\$id)` wearing different "
            . "clothes — `DeliveryAddress` has no global scope and is not allowlisted, so the id "
            . "still reaches every customer of every store. The predicate must come from the "
            . "AUTHENTICATED principal (`auth('customers')->id()`), never from a request value: a "
            . "predicate built out of caller-supplied input only compares the caller's own two "
            . "numbers, which is how four separate defects got into this codebase.",
            implode(', ', $unowned)
        ));
    }

    /**
     * AND THE SCOPED READS STILL EXIST, so the ratchets above cannot be satisfied by deleting the
     * feature. Three sites resolve an address on these paths and all three must still do it.
     */
    public function test_the_scoped_reads_are_still_there(): void
    {
        $code = $this->sourceWithoutComments('app/Http/Controllers/CartController.php');

        /*
         * THREE, and the count was wrong at 2 in the first draft of this case -- I had missed the
         * one inside `get_shipping_method`'s shipping-zone branch, which resolves the same id a
         * second time for the zone lookup. The test caught my miscount rather than a code defect,
         * which is the right way round, but it is worth naming all three so the next person does
         * not have to rediscover them:
         *
         *   :668   `get_shipping_method`, the billing-info merge        (GAP-243)
         *   :876   `get_shipping_method`, the shipping-zone branch      (already scoped)
         *   :1119  `get_tax_data`                                       (GAP-144)
         */
        $this->assertSame(
            3,
            preg_match_all("/DeliveryAddress::where\(\s*'id'/", $code),
            'CartController no longer carries exactly the three id-plus-owner address reads this '
            . 'path needs: the billing-info merge and the shipping-zone branch in '
            . '`get_shipping_method`, and the one in `get_tax_data`. If one was legitimately '
            . 'removed, say which and why here — otherwise the ratchet above is passing because '
            . 'the feature is gone, which is the way a gate like this fails silently.'
        );

        $this->assertStringContainsString(
            "DeliveryAddress::where('id', \$request->billing_address_id)",
            $this->sourceWithoutComments('app/Http/Controllers/Api/ApiController.php'),
            'the API coupon leg no longer resolves an address at all; GAP-235 fixed it by adding '
            . 'the owner predicate, not by removing the read'
        );
    }
}
