<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * DATA-001 — EVERY IMPORT RE-SYNC HANDLER, DISCOVERED BY SHAPE RATHER THAN LISTED BY HAND.
 * =================================================================================================
 *
 * `DATA-001` is "isolate store data at every layer". This is the import layer's re-sync door: a
 * handler that takes a REMOTE id (Shopify's or WooCommerce's), resolves the local row through the
 * mapping table (`shopify_conections` / `woocommerce_conections`: remote id -> `original_id`, per
 * store) and then REWRITES that local row from the remote payload.
 *
 * `ImportMappingOwnership` (P1-INV-13) exists for exactly this door, and its own docblock states
 * the two things that went unchecked: the mapping may not exist (null dereference), and *"the local
 * row it names was never compared with the caller's store"*. `Product`, `Category` and `Coupon`
 * carry no global scope that applies, so a bare `find($original_id)` reaches every tenant's row.
 *
 * =================================================================================================
 *  WHY THIS FILE EXISTS BESIDE `ImportMappingOwnershipTest`, WHICH ALREADY HAS A CENSUS
 * =================================================================================================
 *
 * `ImportMappingOwnershipTest::test_every_re_sync_handler_resolves_its_local_row_through_the_ownership_service`
 * is named "every re-sync handler" and iterates a HARDCODED LIST OF FOUR:
 *
 *     WoocomCouponController, WoocomCustomerController, WoocomCategoryController, ShopifyProductController
 *
 * MEASURED 2026-11-26: there are NINE re-sync methods, and that list names four of them. It omits
 * `WoocomProductController::edit` (guarded, by an inline comparison rather than the service) and
 * — the finding — `ShopifyCategoryController::edit`, `ShopifyCouponController::edit` and
 * `ShopifyCustomerController::edit`, all three of which dereferenced an unchecked mapping and
 * rewrote a row located by a bare `find()`.
 *
 * That is CLAUDE.md §3's rule in a new instance: *"WHEN A CENSUS HUNTS FOR SOMETHING DANGEROUS,
 * ENUMERATE THE SAFE SET INSTEAD. A list of dangerous spellings is always short by the one nobody
 * thought of."* A hardcoded list of handlers is the same mistake one level up — it is short by the
 * handler nobody thought of, and it was short by three. So this census DISCOVERS the door by its
 * shape and then requires a safe mechanism, which makes a tenth handler a failure here rather than
 * a silent omission.
 *
 * The existing test is left exactly as it is. It asserts things this one does not (the ordering of
 * `ShopifyProductController`'s side effects against its refusal, the mapping query's own store
 * predicate, that no update path re-stamps ownership), and two tests asserting overlapping facts
 * about a security boundary is not duplication.
 *
 * =================================================================================================
 *  THE SHAPE, AND THE FOUR SPELLINGS THAT GOT IT WRONG BEFORE IT WAS RIGHT
 * =================================================================================================
 *
 * A method is a RE-SYNC when it READS a mapping's `original_id` and WRITES. Each clause was
 * measured, and each correction removed a false reading:
 *
 *   1. "contains `Conection::where(`" admitted `WoocomProductController::edit`'s sibling spelling
 *      `(clone $woocommerceConectionQuery)->where(...)` not at all — the handler resolves its
 *      mapping off a cloned builder, so a census keyed on the static call MISSED a real door.
 *      Keying on the `original_id` READ instead sees every spelling of the resolve.
 *
 *   2. "`->first()` on the mapping" admitted `ShopifyProductController::show`, which plucks
 *      `shopify_id` — a REMOTE id, used to decide create-or-skip — and writes only `new Product()`
 *      rows carrying `store_id = $storeId`. An import that creates is not a re-sync that rewrites.
 *
 *   3. "`->original_id` appears" admitted every `show()` in all eight controllers, because the
 *      CREATE path writes `$connection->original_id = $data->id;` when it records a new mapping.
 *      The pattern now excludes the assignment position, which is the difference between reading
 *      a mapping and making one.
 *
 *   4. `pluck('original_id')` is a second read spelling and `ShopifyCustomerController` is the only
 *      user of it. A pattern that saw only `->original_id` would have scored that handler safe
 *      while it dereferenced a null and rewrote a row located by a bare `find()`.
 *
 * =================================================================================================
 *  THE TWO SAFE MECHANISMS, ENUMERATED — ANYTHING ELSE IS UNGUARDED BY DEFAULT
 * =================================================================================================
 *
 *   service  `ImportMappingOwnership::owned($mapping, Model::class, $storeId)`, which returns null
 *            for no mapping, no such row, or a row belonging to another store.
 *
 *   inline   an explicit comparison of the RESOLVED ROW's `store_id` with `getCurrentStore()`.
 *            `WoocomProductController::edit` does this, with a logged refusal, and it is as sound
 *            as the service — so requiring the service by name would have failed a correct
 *            handler and taught the next reader to paper over it.
 *
 * =================================================================================================
 *  WHAT THIS TEST CANNOT DO, STATED RATHER THAN IMPLIED
 * =================================================================================================
 *
 * IT IS A SOURCE RATCHET FOR THE SHOPIFY HANDLERS, NOT A DRIVE, and that is forced rather than
 * chosen. Every Shopify re-sync issues a raw cURL to `*.myshopify.com` BEFORE it reaches the guard,
 * so an offline test cannot get past the fetch to the authorization decision — the same reason
 * `ImportMappingOwnershipTest` states for `ShopifyProductController`. The WooCommerce handlers CAN
 * be driven and that test drives them.
 *
 * The SERVICE's own behaviour is driven, here and there: `test_the_service_refuses_a_foreign_row_for_every_model_these_handlers_resolve`
 * drives `Category`, `Coupon` and `Customer` through it with real rows in two stores, so the
 * mechanism the ratchet requires is proved to work for the three models the fix newly routes
 * through it rather than assumed from the two it already covered.
 */
final class ImportResyncOwnershipCompletenessTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * A mapping's `original_id` being READ: `->original_id` not in the assignment position, or
     * plucked. Clauses 3 and 4 above.
     */
    private const READS_MAPPING = '/->original_id\b(?!\s*=[^=])|pluck\(\s*[\'"]original_id[\'"]\s*\)/';

    /** `ImportMappingOwnership::owned(...)` — the first safe mechanism. */
    private const SAFE_SERVICE = 'ImportMappingOwnership::owned(';

    /** An explicit comparison of the resolved row's own `store_id` — the second safe mechanism. */
    private const SAFE_INLINE = '/\$\w+->store_id\s*!==?|store_id\s*!==\s*\(int\)/';

    /**
     * THE NINE RE-SYNC METHODS, AND THE MECHANISM EACH MUST CARRY.
     *
     * Asserted as a SET in both directions by `test_the_discovery_finds_exactly_these_resync_methods`:
     * a new re-sync handler is an undeclared key and fails, and a declared key that stops being a
     * re-sync fails too, so an entry cannot outlive its subject.
     *
     * @var array<string, string>  "File.php::method" => service|inline
     */
    private const RESYNC_METHODS = [
        /* The four the existing hardcoded census already named. */
        'ShopifyProductController.php::edit' => 'service',
        'WoocomCategoryController.php::edit' => 'service',
        'WoocomCouponController.php::edit' => 'service',
        'WoocomCustomerController.php::edit' => 'service',

        /* The one it omitted that was already correct, by the OTHER mechanism. */
        'WoocomProductController.php::edit' => 'inline',

        /* THE THREE IT OMITTED THAT WERE NOT CORRECT, fixed in the same change as this test.
           Each rewrote a row located by a bare `find()` on an unscoped model, after dereferencing
           a mapping that could be null:
             - Category: name, slug and three image paths overwritten on another store's category;
             - Coupon:   coupon_name, coupon_type, discount_amount, coupon_limit, expiry and code
                         overwritten on another store's coupon -- a MONEY RULE, not a label;
             - Customer: `customers` IS in scoped_models, so the scope was the only thing holding
                         this one, which CLAUDE.md says must never be the only check; the null
                         dereference was unconditional. */
        'ShopifyCategoryController.php::edit' => 'service',
        'ShopifyCouponController.php::edit' => 'service',
        'ShopifyCustomerController.php::edit' => 'service',

        /* A CREATE PATH THAT READS A MAPPING, and the weakest member of the family until it was
           fixed in this same change. `WoocomProductController::show` resolves a CATEGORY mapping
           (store-scoped and null-guarded, so it looked safe) and used its `original_id` as the new
           product's `category_id`. It rewrites nobody's row -- so the cross-tenant REWRITE defect
           was absent -- but `woocommerce_conections.original_id` has no foreign key and `Category`
           adds no predicate for any principal, so a mapping this store owns could name another
           store's category and the new product would carry a cross-tenant `category_id`. Now
           routed through the service, which makes `$parent` false and takes the handler's existing
           refusal. The same block appears in `edit()`, and both were changed. */
        'WoocomProductController.php::show' => 'service',
    ];

    /**
     * Every method of every controller, as code with comments stripped.
     *
     * Through PHP's own tokenizer, because CLAUDE.md §3 records eight occasions on which a census
     * was satisfied — or broken — by prose about the thing it measures, and this file's own
     * docblock quotes `ImportMappingOwnership::owned(` and `->original_id` repeatedly.
     *
     * @return array<string, string>  "File.php::method" => the method's code
     */
    private function controllerMethods(): array
    {
        $methods = [];

        foreach (glob(app_path('Http/Controllers/*.php')) ?: [] as $path) {
            $code = '';

            foreach (token_get_all((string) file_get_contents($path)) as $token) {
                if (! is_array($token)) {
                    $code .= $token;

                    continue;
                }

                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    $code .= str_repeat("\n", substr_count($token[1], "\n"));

                    continue;
                }

                $code .= $token[1];
            }

            if (! preg_match_all(
                '/\n    (?:public|protected|private)\s+function\s+(\w+)/',
                $code,
                $found,
                PREG_OFFSET_CAPTURE
            )) {
                continue;
            }

            $count = count($found[1]);

            for ($i = 0; $i < $count; $i++) {
                $from = $found[0][$i][1];
                $to = $found[0][$i + 1][1] ?? strlen($code);

                $methods[basename($path) . '::' . $found[1][$i][0]] = substr($code, $from, $to - $from);
            }
        }

        return $methods;
    }

    /** @return list<string> the keys of every method that reads a mapping's `original_id` and writes */
    private function discoverResyncMethods(): array
    {
        $found = [];

        foreach ($this->controllerMethods() as $key => $body) {
            $readsMapping = (bool) preg_match(self::READS_MAPPING, $body);
            $usesService = str_contains($body, self::SAFE_SERVICE);

            if (! $readsMapping && ! $usesService) {
                continue;
            }

            if (! str_contains($body, '->save()') && ! str_contains($body, '->delete()')) {
                continue;
            }

            $found[] = $key;
        }

        sort($found);

        return $found;
    }

    /**
     * THE PREMISE: the discovery really finds the doors, and the declared set is exactly them.
     *
     * Both directions. An undeclared re-sync method fails (a tenth handler cannot arrive unnoticed)
     * and a declared one that is no longer a re-sync fails (an entry cannot outlive its subject).
     * CLAUDE.md records a register becoming decoration when exemptions outlived their subjects.
     */
    public function test_the_discovery_finds_exactly_these_resync_methods(): void
    {
        $declared = array_keys(self::RESYNC_METHODS);
        sort($declared);

        $this->assertSame($declared, $this->discoverResyncMethods(),
            'the set of import re-sync methods has changed. A NEW one must be added to '
            . 'RESYNC_METHODS with its safe mechanism, and must carry one -- it rewrites a local '
            . 'row located from a remote id through a mapping table, on models that carry no '
            . 'effective tenant scope.');
    }

    /**
     * THE PREMISE THAT THE PATTERN DISCRIMINATES, so a green run is not a pattern that matches
     * everything or nothing.
     *
     * Four shapes, measured on real source: the two read spellings must match, and the two
     * non-re-sync shapes that each produced a false positive must not.
     */
    public function test_the_premise_that_the_pattern_tells_a_read_from_a_write_and_a_pluck_from_a_find(): void
    {
        $this->assertMatchesRegularExpression(self::READS_MAPPING, '$original_id = $upddata->original_id;',
            'the direct read spelling is not seen, so three handlers would score safe while unguarded');

        $this->assertMatchesRegularExpression(self::READS_MAPPING,
            "\$upddata = ShopifyConection::where('store_id', 1)->pluck('original_id')->first();",
            'the pluck spelling is not seen, and it is the only one ShopifyCustomerController uses');

        $this->assertDoesNotMatchRegularExpression(self::READS_MAPPING, '$connection->original_id  = $data->id;',
            'the assignment position matches, so every create path would be reported as a re-sync '
            . '-- measured: that admitted all eight show() methods');

        $this->assertDoesNotMatchRegularExpression(self::READS_MAPPING,
            "\$upddata = ShopifyConection::where('store_id', 1)->pluck('shopify_id')->first();",
            'plucking a REMOTE id matches, so ShopifyProductController::show would be reported as '
            . 'a re-sync while it only creates rows carrying the caller\'s own store_id');
    }

    /**
     * THE FINDING, AND THE FIX: every re-sync method authorises the row it rewrites.
     */
    public function test_every_resync_method_authorises_the_local_row_it_rewrites(): void
    {
        $methods = $this->controllerMethods();

        foreach (self::RESYNC_METHODS as $key => $mechanism) {
            $this->assertArrayHasKey($key, $methods, $key . ' no longer exists.');

            $body = $methods[$key];

            $hasService = str_contains($body, self::SAFE_SERVICE);
            $hasInline = (bool) preg_match(self::SAFE_INLINE, $body);

            $this->assertTrue($hasService || $hasInline,
                $key . ' resolves a local row from an import mapping and writes to it, with '
                . 'NEITHER an ImportMappingOwnership::owned() call nor an explicit comparison of '
                . "the resolved row's store_id. The mapping may be null, and Category, Coupon and "
                . 'Product carry no effective tenant scope, so find($original_id) reaches every '
                . "tenant's row and the write lands on it.");

            $expected = $mechanism === 'service' ? $hasService : $hasInline;

            $this->assertTrue($expected,
                $key . ' is declared as carrying the "' . $mechanism . '" mechanism and does not. '
                . 'Either the handler changed mechanism -- update RESYNC_METHODS and say why -- or '
                . 'the guard was removed.');
        }
    }

    /**
     * NO RE-SYNC METHOD DEREFERENCES A MAPPING IT HAS NOT CHECKED.
     *
     * The second half of P1-INV-13, and separate from ownership: `->first()` returns null for an
     * unmapped remote id, and `$upddata->original_id` on null is a 500 on bad input. The existing
     * test asserts this for its four files with `assertStringNotContainsString('$upddata->original_id;')`
     * — keyed on ONE variable name, which `ShopifyCustomerController`'s `pluck()` spelling and any
     * other variable name both walk straight past. This asks it of every discovered door.
     */
    public function test_no_resync_method_dereferences_an_unchecked_mapping(): void
    {
        $methods = $this->controllerMethods();

        /*
         * THIS TEST WAS `risky` AND ASSERTING NOTHING FOR ONE RUN, and the reason is worth keeping:
         * its first form skipped any method containing `owned(`, and once the fix routed all six
         * Shopify and Woo handlers through the service, EVERY declared method was skipped and the
         * loop asserted zero times. PHPUnit said so; a `continue`-shaped census in a file with no
         * such report would simply have passed forever. CLAUDE.md §3: a census that can be
         * satisfied vacuously passes forever. It now asserts the invariant positively, for every
         * declared method, with no `continue`.
         */
        $checked = 0;

        foreach (self::RESYNC_METHODS as $key => $mechanism) {
            $body = $methods[$key] ?? '';
            $this->assertNotSame('', $body, $key . ' no longer exists.');

            $dereferences = preg_match('/\$(\w+)\s*=\s*\$(\w+)->original_id\s*;/', $body, $match) === 1;

            if (! $dereferences) {
                /*
                 * THE PREFERRED SHAPE: the mapping OBJECT is handed to the service, whose signature
                 * is `owned(?object $mapping, ...)` and whose first branch returns null for
                 * `$mapping === null`. The null dereference is then not guarded against, it is
                 * impossible -- so this method must really pass the mapping rather than merely
                 * mention the service.
                 */
                $this->assertMatchesRegularExpression(
                    '/ImportMappingOwnership::owned\(\s*\$\w+\s*,/',
                    $body,
                    $key . ' neither dereferences a mapping nor passes one to '
                    . 'ImportMappingOwnership::owned(). One of the two must be true, or this '
                    . 'census has lost sight of how the method resolves its row.'
                );

                $checked++;

                continue;
            }

            /*
             * THE TOLERATED SHAPE: a direct read, which is sound only with BOTH a null guard on the
             * mapping and an explicit store comparison on the resolved row. `WoocomProductController::edit`
             * is the one handler in this shape and it carries both.
             *
             * The guard is any conditional ON THE MAPPING VARIABLE, in either polarity. Keyed first
             * on `empty($x)` / `!$x` only, it failed a handler whose guard is the truthy
             * `if($exitsCategory) {` -- a correct guard the pattern could not see, which is the
             * false-accusation direction.
             */
            $mapping = $match[2];
            $before = substr($body, 0, (int) strpos($body, $match[0]));

            $this->assertMatchesRegularExpression(
                '/if\s*\(\s*!?\s*(?:empty\(\s*)?\$' . preg_quote($mapping, '/') . '\b/',
                $before,
                $key . ' reads $' . $mapping . '->original_id with no preceding conditional on $'
                . $mapping . '. An unmapped or foreign remote id makes that a 500 rather than a '
                . 'refusal.'
            );

            $this->assertMatchesRegularExpression(
                self::SAFE_INLINE,
                $body,
                $key . ' dereferences a mapping directly, so the resolved row must carry an '
                . 'explicit store_id comparison -- the null guard alone authorises nothing.'
            );

            $checked++;
        }

        $this->assertSame(count(self::RESYNC_METHODS), $checked,
            'not every declared re-sync method was reached, so this census is partly vacuous.');
    }

    /**
     * THE MECHANISM IS DRIVEN, for the three models the fix newly routes through it.
     *
     * `ImportMappingOwnershipTest` drives `Coupon`, `Customer` and `Category` through the service
     * already. This asserts the same property model by model against rows in two real stores, so
     * "the service refuses a foreign row" is executed for each class name the three fixed handlers
     * pass in, rather than inferred from the handlers that passed in two of them.
     */
    /**
     * A row of `$model` in `$storeId`, with `store_id` set whether or not it is fillable.
     *
     * Written through Eloquent rather than `DB::table()` on purpose: CLAUDE.md records that 79 of
     * the 95 models are `Cachable`, and a raw write leaves Eloquent reads -- which is what
     * `owned()` performs -- serving the pre-write row for the rest of the test.
     */
    private function rowInStore(string $model, array $attributes, $storeId): \Illuminate\Database\Eloquent\Model
    {
        $row = new $model();

        foreach ($attributes as $column => $value) {
            $row->{$column} = $value;
        }

        $row->store_id = $storeId;
        $row->save();

        return $row;
    }

    public function test_the_service_refuses_a_foreign_row_for_every_model_these_handlers_resolve(): void
    {
        [, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();

        $cases = [
            \App\Models\Category::class => ['name' => 'Shoes', 'slug' => 'collections/shoes'],
            \App\Models\Coupon::class => ['coupon_name' => 'TEN', 'coupon_code' => 'TEN10'],
        ];

        foreach ($cases as $model => $attributes) {
            /*
             * `store_id` IS SET AFTER CONSTRUCTION, DELIBERATELY. `Coupon::$fillable` does not
             * list it, so `create($attributes + ['store_id' => ...])` silently DROPPED it and the
             * fixture's own row had a null store -- which `owned()` correctly refused, and this
             * test read as "the service refuses the caller's own row". Measured on the first run.
             * It is the fixture failing for the wrong reason, in the direction that would have
             * been invisible had only the refusal been asserted.
             */
            $foreign = $this->rowInStore($model, $attributes, $theirs->id);
            $own = $this->rowInStore($model, $attributes, $mine->id);

            $mapping = (object) ['original_id' => $foreign->id];

            $this->assertNull(
                \App\Services\ImportMappingOwnership::owned($mapping, $model, $mine->id),
                $model . ': the service returned another store\'s row, so the three handlers now '
                . 'relying on it are not protected.'
            );

            $resolved = \App\Services\ImportMappingOwnership::owned(
                (object) ['original_id' => $own->id],
                $model,
                $mine->id
            );

            $this->assertNotNull($resolved, $model . ': the caller\'s OWN row is refused, which '
                . 'would make the re-sync handlers fail closed on legitimate input -- a test that '
                . 'only asserted the refusal would call that a pass.');
            $this->assertSame((string) $own->id, (string) $resolved->id, $model);
        }
    }
}
