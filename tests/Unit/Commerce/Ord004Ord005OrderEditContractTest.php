<?php

namespace Tests\Unit\Commerce;

use PHPUnit\Framework\TestCase;

/**
 * `ORD-004` AND `ORD-005` -- WHAT AN ORDER EDIT MAY AND MAY NOT TOUCH.
 * ===================================================================
 *
 * Two decisions that only make sense together, verbatim from
 * `docs/development/master-plan/DECISION-ID-REGISTER.md`:
 *
 *     ORD-004  منع تعديل منتجات الطلب وكمياته بعد إنشائه.
 *              Prevent editing an order's products and quantities after creation (see EX-006)
 *
 *     ORD-005  السماح بتعديل بيانات التوصيل والملاحظات ضمن الصلاحيات والحالة.
 *              Allow editing delivery data and notes, within permissions and status
 *
 * One forbids, one permits, and between them they define the whole editable surface of an order.
 * Neither appears in `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` or `MASTER-PLAN-GAP-REGISTER.md`,
 * so neither had ever been measured against the code.
 *
 * -------------------------------------------------------------------------------------------------
 * THE EXISTING MEASUREMENT OF `EX-006` IS VACUOUS, and that is why this file exists.
 *
 * `DECISION-ID-REGISTER.md` §3 reconciles `EX-006` -- the exclusion `ORD-004` restates -- like this:
 *
 *     "measured in code: no controller or API handler in `app/Http/Controllers/` mutates
 *      `order_products`"
 *
 * **There is no `order_products` table.** No migration creates one in `database/migrations/` or in
 * any of the 70 vendored packages, and the string appears nowhere in `app/`. An order's lines live
 * in `orders.product_json`, a JSON text column. So that measurement would have passed whatever the
 * code did -- it is the shape CLAUDE.md §3 warns about, an assertion that cannot fail.
 *
 * Measured against the column that really holds the lines, the answer is more interesting than the
 * vacuous one, and it splits in two.
 *
 * -------------------------------------------------------------------------------------------------
 * `ORD-004`, PRODUCT IDENTITY: HOLDS ABSOLUTELY.
 *
 * Nineteen files in `app/` write `orders.product_json`. **Not one of them assigns `product_id` or
 * `variant_id` onto a stored order line.** Every identity assignment in those files is one of three
 * other things, each read and declared in {@see self::LINE_KEY_WRITERS}: building the cart response,
 * building the `Order::product_return()` REQUEST payload (which NAMES a line, it does not edit one),
 * or shaping an API response. So what was ordered cannot be changed into something else.
 *
 * The route surface agrees and is asserted separately: `OrderController` defines neither `edit` nor
 * `update` among its 26 public methods, and `Route::resource('order', ...)` excludes both.
 *
 * -------------------------------------------------------------------------------------------------
 * `ORD-004`, QUANTITY: DOES NOT HOLD AS WRITTEN, AND THE OWNER HAS TO READ THE DECISION AGAIN.
 *
 * A stored order line's `qty` IS mutated after creation, at exactly two sites, both in the refund
 * workflow and in opposite directions:
 *
 *   - `AccountProfileController` -- the storefront refund REQUEST subtracts the refunded units
 *     from the line (`$product['qty'] = $product['qty'] - $units[$key]`) and writes `product_json`;
 *   - `OrderRefundController` -- cancelling that request adds them back
 *     (`$product['qty'] = $product['qty'] + $quantitie`), bounded by the line's own `refunded_qty`.
 *
 * Whether that is a VIOLATION depends on which reading of `ORD-004` the owner meant, and the
 * repository cannot answer it:
 *
 *   (a) "no SURFACE may let anyone edit products and quantities" -- SATISFIED. There is no order
 *       edit screen, no edit route and no handler that takes a quantity from request input and puts
 *       it on a line. The two sites above are consequences of an approved returns and refund
 *       workflow, and the quantity they move is derived from recorded refund data.
 *
 *   (b) "the order RECORD must keep stating what was ordered" -- NOT SATISFIED. The refund path
 *       overwrites `qty` IN PLACE. For orders whose lines carry the `refunded_qty` counter the
 *       original is recoverable as `qty + refunded_qty`; `AccountProfileController`'s own docblock
 *       records that the counter is "new data only", so for a legacy order the originally ordered
 *       quantity is **destroyed and unrecoverable**.
 *
 * That is the owner decision this file exists to put precisely. It is NOT resolved here, and no
 * behaviour is changed: altering how a refund records a quantity on existing data is exactly the
 * stock-arithmetic change CLAUDE.md §2 puts in the escalate set.
 *
 * -------------------------------------------------------------------------------------------------
 * `ORD-005`: ONE OF ITS THREE CLAUSES IS BUILT.
 *
 *   "notes"           PARTIAL. The admin API's `POST /orders/update-status` accepts a `note` and
 *                     writes it to `orders.delivery_comment`. That is the ONLY post-creation note
 *                     write in the tree. On the merchant web the notes are rows in `order_notes`
 *                     and the resource is `only(['store', 'destroy'])`; the admin API likewise has
 *                     `notes/create` and `notes/delete`. So a note can be added and removed on
 *                     both surfaces and EDITED on neither.
 *   "delivery data"   MISSING. Nothing edits an order's recipient, address or phone after creation.
 *                     `OrderBillingDetail` is written once at creation and otherwise only deleted,
 *                     with the order.
 *   "within permissions"  HOLDS, and is asserted below.
 *   "within status"   NOT ENFORCED. The note write carries no `delivered_status` predicate, so a
 *                     note can be changed on a cancelled or returned order.
 */
class Ord004Ord005OrderEditContractTest extends TestCase
{
    /** Subscript assignment to a line's identity or quantity key. Not `==`, not `=>`. */
    private const LINE_KEY_ASSIGNMENT = "/\[\s*'(qty|quantity|product_id|variant_id)'\s*\]\s*=(?!=|>)/";

    /**
     * EVERY file in `app/` that writes `orders.product_json` AND assigns a line identity or
     * quantity key, with the keys it assigns and what it is really doing.
     *
     * Keyed on the FILE and the KEY NAMES, never on line numbers. CLAUDE.md §3 measured four of
     * five `place_order:<n>` citations stale, each by a different amount, and one workstream's own
     * citations going stale four times inside a single task -- twice by the change that was citing
     * them. A file-and-key declaration cannot drift that way.
     *
     * `mutates_stored_line` is the load-bearing field: TRUE means this site changes a line on an
     * order that already exists, which is what `ORD-004` is about.
     *
     * `assignments` IS THE SAFE SET, AND IT IS WHAT STOPS `identity_keys_off_line` BEING AN
     * UNFALSIFIABLE CLAIM. Restored from `ws/census` on 2026-11-27: the integration merge resolved
     * this file by taking the mainline's side, which had answered the same hazard with the
     * per-key exemption below, and the two mechanisms are NOT equivalent. `keys` is measured per
     * FILE, so once `variant_id` is exempted for `OrderRefundController` the exemption covers
     * every assignment of that key in the file -- including a SECOND one, onto a stored line.
     * MEASURED 2026-11-27 before this field was restored: adding
     * `$product['variant_id'] = 0;` inside `CancelRefundStatus()`'s `foreach ($productJson ...)`
     * loop, whose result is `json_encode`d straight back into `orders.product_json`, left this
     * file GREEN -- 9 passed, 778 assertions. That is a real `ORD-004` identity rewrite the
     * census could not see.
     *
     * So every line-key assignment in a declared file is now written down by its TARGET
     * EXPRESSION and asserted in both directions by
     * {@see self::test_every_line_key_assignment_in_a_declared_file_is_pinned_by_name}. This is
     * CLAUDE.md §3's rule -- when a census hunts for something dangerous, enumerate the SAFE SET
     * instead, because a list of dangerous spellings is always short by the one nobody thought of.
     * `$productRefundData[$pid]['variant_id']` and `$product['qty']` are the same key SHAPE and
     * opposite facts, and the whole difference is in what precedes the key, which
     * `LINE_KEY_ASSIGNMENT` does not capture at all.
     *
     * @var array<string, array{keys: list<string>, assignments: list<string>, what: string,
     *     mutates_stored_line: bool, identity_keys_off_line?: array<string, string>}>
     */
    private const LINE_KEY_WRITERS = [
        'Http/Controllers/Api/ApiController.php' => [
            'keys' => ['product_id', 'qty', 'variant_id'],
            'assignments' => [
                "\$cart_array['product_list'][\$key]['product_id']",
                "\$cart_array['product_list'][\$key]['qty']",
                "\$cart_array['product_list'][\$key]['variant_id']",
                "\$data['product_id']",
                "\$data['variant_id']",
                "\$return['variant_id']",
            ],
            'what' => 'the cart response array; the product_return() request payload, which NAMES '
                . 'a line rather than editing one; and one API response field',
            'mutates_stored_line' => false,
        ],

        'Http/Controllers/OrderController.php' => [
            'keys' => ['product_id', 'variant_id'],
            'assignments' => ["\$data['product_id']", "\$data['variant_id']"],
            'what' => 'the product_return() request payload, web side, behind '
                . "authorize('updateStatus')",
            'mutates_stored_line' => false,
        ],

        'Http/Controllers/PosController.php' => [
            'keys' => ['quantity'],
            'assignments' => ["\$sales['data'][\$key]['quantity']"],
            'what' => 'the POS sales REPORT display array; in-memory, never written back',
            'mutates_stored_line' => false,
        ],

        // ---- the two that really do change a stored line ----

        'Http/Controllers/AccountProfileController.php' => [
            'keys' => ['qty'],
            'assignments' => ["\$product['qty']"],
            'what' => 'the storefront refund REQUEST subtracts refunded units from the line and '
                . 'writes product_json back',
            'mutates_stored_line' => true,
        ],

        'Http/Controllers/OrderRefundController.php' => [
            'keys' => ['qty', 'variant_id'],
            'assignments' => ["\$product['qty']", "\$productRefundData[\$pid]['variant_id']"],
            'what' => 'cancelling a refund request adds the units back, bounded by the line\'s own '
                . 'refunded_qty counter (`qty`). THE `variant_id` ENTRY IS NOT A LINE WRITE, and '
                . 'this file\'s inclusion is the LIMIT OF THIS CENSUS rather than a finding: '
                . 'measured 2026-11-26, the only assignment matching LINE_KEY_ASSIGNMENT for that '
                . 'key is `$productRefundData[$pid][\'variant_id\'] = $variantId`, which writes a '
                . 'VIEW-MODEL array built for the refund screen so it can show a returned line\'s '
                . 'recorded condition (RET-002). It never reaches `orders.product_json`. The other '
                . 'two occurrences of the key in this file are a `Log::warning` context and a '
                . 'docblock, both of which the pattern correctly ignores because they use `=>`. '
                . 'THE CENSUS MATCHES A KEY NAME ANYWHERE IN A FILE THAT WRITES `product_json` '
                . 'SOMEWHERE, so an assignment to any other array carrying one of these four key '
                . 'names reads as a line-identity write. That is a file-scoped instrument reporting '
                . 'a statement-scoped question, and it is why this declaration says what the '
                . 'assignment really targets rather than only naming the key. Tightening the '
                . 'pattern to the arrays that actually become `product_json` would be the real fix '
                . 'and belongs to whoever owns this file.',
            'mutates_stored_line' => true,
            /*
             * A key this file assigns that is NOT on a stored order line, with the variable it
             * really targets. `mutates_stored_line` is per FILE and `keys` is per file, so without
             * this the identity assertion below reads `variant_id` as an ORD-004 violation -- and
             * it was right to refuse the first draft of this declaration, which claimed
             * `mutates_stored_line` for the key as well. The named target is VERIFIED by
             * `test_premise_every_off_line_identity_key_names_a_target_that_exists`, so an
             * exemption cannot be taken on an unchecked assertion.
             */
            'identity_keys_off_line' => ['variant_id' => 'productRefundData'],
        ],
    ];

    /** @return list<string> every `.php` file under `app/`, relative to it */
    private function appFiles(): array
    {
        $root = dirname(__DIR__, 3) . '/app';

        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = str_replace($root . '/', '', $file->getPathname());
            }
        }

        sort($found);

        return $found;
    }

    /** The file's code with comments replaced by newlines, so prose cannot satisfy a census. */
    private function codeOf(string $relativePath, string $base = 'app/'): string
    {
        $path = dirname(__DIR__, 3) . '/' . $base . $relativePath;
        $this->assertFileExists($path);

        $out = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    $out .= str_repeat("\n", substr_count($token[1], "\n"));
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /** @return bool whether this file writes `orders.product_json` at all */
    private function writesOrderLines(string $code): bool
    {
        return preg_match('/->\s*product_json\s*=[^=>]/', $code) === 1
            || preg_match("/'product_json'\s*=>/", $code) === 1;
    }

    /**
     * THE PREMISE, in both directions. A census that scans nothing, or whose pattern never fires,
     * is indistinguishable from a pass.
     */
    public function test_the_census_reads_the_tree_and_its_pattern_discriminates(): void
    {
        $this->assertGreaterThan(500, count($this->appFiles()));

        $this->assertSame(
            1,
            preg_match(self::LINE_KEY_ASSIGNMENT, "\$product['qty'] = \$product['qty'] - 1;"),
            'The pattern must catch a line quantity being rewritten.'
        );

        foreach ([
            "if (\$product['qty'] == 3) { return; }",
            "\$line = ['qty' => 3];",
            "\$total += \$product['qty'];",
        ] as $innocent) {
            $this->assertSame(
                0,
                preg_match(self::LINE_KEY_ASSIGNMENT, $innocent),
                "A read or an array literal must not match: $innocent"
            );
        }
    }

    /**
     * The table that the register's `EX-006` reconciliation measured does not exist.
     *
     * Asserted so the vacuous measurement cannot be repeated, and so that if somebody DOES
     * introduce a real `order_products` table this file fails and the whole contract above gets
     * re-derived against it rather than silently going stale.
     */
    public function test_the_order_products_table_the_register_measured_does_not_exist(): void
    {
        $root = dirname(__DIR__, 3);

        $migrations = array_merge(
            glob($root . '/database/migrations/*.php') ?: [],
            glob($root . '/packages/workdo/*/src/database/migrations/*.php') ?: [],
            glob($root . '/packages/workdo/*/src/Database/Migrations/*.php') ?: []
        );

        $this->assertGreaterThan(100, count($migrations), 'PREMISE: the migrations must be found.');

        $creators = [];

        foreach ($migrations as $file) {
            if (str_contains(php_strip_whitespace($file), "Schema::create('order_products'")) {
                $creators[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $creators,
            "An `order_products` table now exists. DECISION-ID-REGISTER.md's EX-006 reconciliation "
            . 'measured that table while it did not exist, which is why this file measures '
            . '`orders.product_json` instead. If the lines have genuinely moved to a table, every '
            . 'assertion in this file has to be re-derived against it.'
        );
    }

    /**
     * `ORD-004`, THE ROUTE SURFACE: there is no order edit or update endpoint.
     */
    public function test_there_is_no_order_edit_or_update_handler_or_route(): void
    {
        $controller = $this->codeOf('Http/Controllers/OrderController.php');

        foreach (['edit', 'update'] as $method) {
            $this->assertSame(
                0,
                preg_match('/function\s+' . $method . '\s*\(/', $controller),
                "ORD-004: OrderController::$method() now exists. An order edit endpoint is exactly "
                . 'what ORD-004 and EX-006 forbid.'
            );
        }

        $routes = $this->codeOf('routes/web.php', '');

        $this->assertSame(
            1,
            preg_match(
                "/Route::resource\(\s*'order'\s*,\s*OrderController::class\s*\)\s*->except\(\s*\[\s*'edit'\s*,\s*'update'\s*\]\s*\)/",
                $routes
            ),
            "ORD-004: the `order` resource must keep excluding `edit` and `update`. If the "
            . 'exclusion is gone, Laravel registers both verbs and the prohibition is off.'
        );
    }

    /**
     * `ORD-004`, THE IDENTITY HALF: nothing changes WHICH product an order line is for.
     *
     * The strongest assertion in this file, and the one that holds absolutely.
     */
    /**
     * AN EXEMPTION MUST NAME A TARGET THAT REALLY EXISTS IN THE FILE.
     *
     * `identity_keys_off_line` lets a declaration say "this file assigns `variant_id`, but to
     * `$productRefundData`, not to a stored line". That is the only way the file-scoped census can
     * answer a statement-scoped question -- and an unverified exemption is just a way of switching
     * the assertion off. So each entry is checked against the source: the file must contain an
     * assignment of that key subscripted off the named variable. If the assignment moves or is
     * renamed, the exemption stops being justified and this premise says so.
     */
    public function test_premise_every_off_line_identity_key_names_a_target_that_exists(): void
    {
        $checked = 0;

        foreach (self::LINE_KEY_WRITERS as $file => $declaration) {
            foreach ($declaration['identity_keys_off_line'] ?? [] as $key => $variable) {
                $code = (string) file_get_contents(dirname(__DIR__, 3) . '/app/' . $file);

                $this->assertMatchesRegularExpression(
                    '/\$' . preg_quote($variable, '/') . '\s*\[[^\]]*\]\s*\[\s*\x27'
                        . preg_quote($key, '/') . '\x27\s*\]\s*=(?!=|>)/',
                    $code,
                    sprintf(
                        '%s is exempted for the key \x27%s\x27 on the grounds that it is assigned on '
                        . '$%s rather than on a stored order line -- and no such assignment is in the '
                        . 'file. Either the assignment moved (re-point the exemption to where it is '
                        . 'now) or it is gone (drop the exemption). An exemption whose stated reason '
                        . 'cannot be found is an assertion switched off.',
                        $file,
                        $key,
                        $variable
                    )
                );

                $checked++;
            }
        }

        $this->assertGreaterThan(
            0,
            $checked,
            'No off-line exemption was checked, so this premise measured nothing. If the last '
            . 'exemption was legitimately removed, delete this premise in the same change.'
        );
    }

    public function test_no_stored_order_line_has_its_product_or_variant_identity_rewritten(): void
    {
        $offenders = [];

        foreach (self::LINE_KEY_WRITERS as $file => $declaration) {
            if (! $declaration['mutates_stored_line']) {
                continue;
            }

            $offLine = $declaration['identity_keys_off_line'] ?? [];

            foreach (['product_id', 'variant_id'] as $identityKey) {
                if (! in_array($identityKey, $declaration['keys'], true)) {
                    continue;
                }

                /* Exempt only with a named target, which the premise below verifies. */
                if (isset($offLine[$identityKey])) {
                    continue;
                }

                $offenders[] = "$file assigns '$identityKey' on a stored order line";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'ORD-004 forbids editing an order\'s PRODUCTS after creation. A site that rewrites a '
            . "stored line's product or variant id turns an order into a record of something that "
            . "was never ordered:\n" . implode("\n", $offenders)
        );
    }

    /**
     * `ORD-004`, THE QUANTITY HALF: exactly two sites mutate a stored line's quantity, and both
     * are the refund workflow.
     *
     * A THIRD fails this test, which is the point -- a new quantity writer is the change that
     * would turn reading (a) of `ORD-004` from satisfied into violated, and it must not arrive
     * without somebody reading this docblock.
     */
    public function test_exactly_the_refund_workflow_mutates_a_stored_order_lines_quantity(): void
    {
        $mutators = array_keys(array_filter(
            self::LINE_KEY_WRITERS,
            static fn (array $declaration): bool => $declaration['mutates_stored_line']
        ));

        sort($mutators);

        $this->assertSame(
            [
                'Http/Controllers/AccountProfileController.php',
                'Http/Controllers/OrderRefundController.php',
            ],
            $mutators,
            'ORD-004: the set of sites that change a stored order line has changed. Both known '
            . 'entries are the refund workflow moving units in opposite directions. A third site '
            . 'needs reading against ORD-004 before it is declared.'
        );

        // Both of them touch quantity and NOTHING else about the line.
        foreach ($mutators as $file) {
            /*
             * Keys the declaration has accounted for as NOT being on a stored line are subtracted
             * first, and only with a target that
             * `test_premise_every_off_line_identity_key_names_a_target_that_exists` has verified
             * against the source. What remains is what this file really writes on a line, so a
             * genuine second line key still fails here -- which is the whole point of the
             * assertion and the reason the exemption is keyed per KEY rather than per file.
             */
            $onLine = array_values(array_diff(
                self::LINE_KEY_WRITERS[$file]['keys'],
                array_keys(self::LINE_KEY_WRITERS[$file]['identity_keys_off_line'] ?? [])
            ));

            $this->assertSame(
                ['qty'],
                $onLine,
                "$file must change a line's quantity and nothing else about it."
            );
        }
    }

    /**
     * THE RATCHET: the measured set of line-key writers among the order-line writers is exactly the
     * declared one, keys included.
     *
     * Both directions -- an undeclared file AND a declared file whose key set has changed. A stale
     * declaration is how an allowlist stops meaning anything.
     */
    public function test_the_line_key_writers_are_exactly_the_declared_set(): void
    {
        $measured = [];

        foreach ($this->appFiles() as $relative) {
            $code = $this->codeOf($relative);

            if (! $this->writesOrderLines($code)) {
                continue;
            }

            if (preg_match_all(self::LINE_KEY_ASSIGNMENT, $code, $matches) === 0) {
                continue;
            }

            $keys = array_values(array_unique($matches[1]));
            sort($keys);

            $measured[$relative] = $keys;
        }

        ksort($measured);

        $declared = [];
        foreach (self::LINE_KEY_WRITERS as $file => $declaration) {
            $keys = $declaration['keys'];
            sort($keys);
            $declared[$file] = $keys;
        }
        ksort($declared);

        $this->assertSame(
            $declared,
            $measured,
            'The files that write orders.product_json AND assign a line identity or quantity key '
            . 'no longer match the declared set. Read each difference against ORD-004 before '
            . 'declaring it: a new quantity or identity writer may be a violated decision rather '
            . 'than a missing allowlist entry.'
        );
    }

    /**
     * WHAT MAKES `identity_keys_off_line` FALSIFIABLE: every line-key assignment in a declared file
     * is pinned by its TARGET EXPRESSION, measured from source.
     *
     * RESTORED FROM `ws/census` ON 2026-11-27, and it is not a duplicate of the premise above.
     * That premise asks whether the exempted key is assigned on the named variable SOMEWHERE in
     * the file, and the answer stays yes however many OTHER assignments of the same key arrive.
     * So the exemption is granted per FILE and per KEY while the hazard is per STATEMENT. Measured
     * before this test was restored: `$product['variant_id'] = 0;` added inside
     * `OrderRefundController::CancelRefundStatus()`'s `foreach ($productJson as $key => &$product)`
     * loop -- a real `ORD-004` identity rewrite on a stored line, `json_encode`d back into
     * `orders.product_json` eleven lines later -- left this file reporting 9 passed, 778
     * assertions. With this test it is a failure that names the new assignment.
     *
     * The claim `identity_keys_off_line` makes ("this assignment is on a view array, not on a
     * stored line") is exactly the kind no regex can decide. So this test does not try to. It
     * enumerates the SAFE SET instead, per CLAUDE.md §3: a census that hunts for something
     * dangerous is always short by the spelling nobody thought of, so the assignments that exist
     * today are written down by name and ANY new one fails here, whatever it targets and whether or
     * not it reaches `product_json`. The reader then has to decide which field it belongs in.
     *
     * Asserted in both directions, so a declaration cannot outlive its assignment either.
     */
    public function test_every_line_key_assignment_in_a_declared_file_is_pinned_by_name(): void
    {
        /* The target expression as well as the key, which LINE_KEY_ASSIGNMENT does not capture:
           `$productRefundData[$pid]['variant_id']` and `$product['qty']` are the same key shape and
           opposite facts, and the difference is entirely in what precedes the key. */
        $withTarget = "/(\\$[A-Za-z_][A-Za-z0-9_]*(?:\s*\[[^\]]*\])*)\s*"
            . "\[\s*'(qty|quantity|product_id|variant_id)'\s*\]\s*=(?!=|>)/";

        $this->assertSame(
            1,
            preg_match($withTarget, "\$productJson[\$key]['variant_id'] = 1;"),
            'The target-aware pattern must catch a stored-line identity assignment, or this test '
            . 'measures nothing.'
        );

        foreach (self::LINE_KEY_WRITERS as $file => $declaration) {
            preg_match_all($withTarget, $this->codeOf($file), $hits, PREG_SET_ORDER);

            $measured = [];

            foreach ($hits as $hit) {
                // Whitespace squeezed out of the target, so a reformat cannot invalidate a
                // declaration -- the same reason the sibling censuses normalise before matching.
                $measured[preg_replace('/\s+/', '', $hit[1]) . "['" . $hit[2] . "']"] = true;
            }

            $measured = array_keys($measured);
            sort($measured);

            $declared = $declaration['assignments'];
            sort($declared);

            $this->assertSame(
                $declared,
                $measured,
                "$file gained or lost a line-key assignment. Read it before declaring it: if it "
                . 'writes a key onto a line of an order that already EXISTS, then ORD-004 has '
                . 'something to say about it and the file\'s `keys`, `mutates_stored_line` and '
                . '`identity_keys_off_line` must be re-read together -- an identity key assigned on '
                . 'a stored line may NOT be carried by an existing off-line exemption, which is '
                . 'granted per key and per file rather than per statement. If it only shapes a '
                . 'request payload, an API response or a screen, it belongs in `assignments` alone '
                . '-- and say which, because that distinction is the whole load this declaration '
                . 'carries.'
            );
        }
    }

    /**
     * AND THE NARROWING CANNOT BE WIDER THAN THE MEASUREMENT IT NARROWS.
     *
     * `ws/census` asserted this of its own `stored_line_keys`; the same guard belongs on the
     * mainline's `identity_keys_off_line`, which is the field that actually switches the identity
     * assertion off. An exemption for a key the detector never found in the file is an exemption
     * for nothing -- and a file declared not to mutate a stored line cannot need one at all,
     * because the identity test skips it before the exemption is ever read.
     */
    public function test_no_off_line_exemption_is_wider_than_the_keys_measured_in_the_file(): void
    {
        $checked = 0;

        foreach (self::LINE_KEY_WRITERS as $file => $declaration) {
            $offLine = $declaration['identity_keys_off_line'] ?? [];

            $this->assertSame(
                [],
                array_values(array_diff(array_keys($offLine), $declaration['keys'])),
                "$file exempts a key that is not among the keys measured in the file. A key no "
                . 'detector found cannot be one this file assigns, so the exemption hides nothing '
                . 'and one of the two declarations is wrong.'
            );

            if (! $declaration['mutates_stored_line']) {
                $this->assertSame(
                    [],
                    $offLine,
                    "$file is declared not to mutate a stored line, so the identity test never "
                    . 'reaches its exemptions. An exemption here is either dead or the '
                    . '`mutates_stored_line` beside it is wrong.'
                );
            }

            $checked += count($offLine);
        }

        $this->assertGreaterThan(
            0,
            $checked,
            'No exemption was examined, so this guard measured nothing. If the last exemption was '
            . 'legitimately removed, delete this test and its sibling premise in the same change.'
        );
    }

    /**
     * `ORD-005`, THE PERMISSIONS CLAUSE: the one handler that permits a post-creation note edit is
     * gated, and gated the way this codebase has to gate things.
     *
     * `Gate::allows('updateStatus', $order)` reaches `OrderPolicy::updateStatus`, which requires
     * `Manage Order` and tests platform-operator status FIRST -- mandatory here, because
     * CLAUDE.md §1 records that `isAbleTo()` REFUSES the operator, who holds no roles.
     */
    public function test_the_note_edit_is_behind_a_permission_gate(): void
    {
        $code = $this->codeOf('Http/Controllers/Api/DashboardController.php');

        $this->assertSame(
            1,
            preg_match("/->\s*allows\(\s*'updateStatus'\s*,\s*\\\$order\s*\)/", $code),
            "ORD-005 permits editing notes WITHIN PERMISSIONS. updateStatus() -- the only handler "
            . 'that writes a note onto an existing order -- must stay behind the updateStatus gate.'
        );

        // It is the note-writing handler: the `note` input really does reach `delivery_comment`.
        $this->assertSame(
            1,
            preg_match("/->\s*delivery_comment\s*=\s*trim\(/", $code),
            'ORD-005: the admin API must still be the surface that edits an order note.'
        );
    }

    /**
     * `ORD-005`, WHAT IS NOT BUILT: a note can be added and removed, never edited, and nothing
     * edits an order's delivery DATA at all.
     *
     * Asserted as a ratchet on a known gap. If one of these becomes available, this test fails and
     * whoever built it has to update `ORD-005`'s disposition instead of leaving the register saying
     * the clause is unimplemented.
     */
    public function test_the_unbuilt_halves_of_ord005_are_still_unbuilt(): void
    {
        $web = $this->codeOf('routes/web.php', '');

        $this->assertSame(
            1,
            preg_match(
                "/Route::resource\(\s*'order-note'\s*,\s*OrderNoteController::class\s*\)\s*->only\(\s*\[\s*'store'\s*,\s*'destroy'\s*\]\s*\)/",
                $web
            ),
            'ORD-005 permits EDITING notes. The web resource is store+destroy only, so a note can '
            . 'be added and removed but not edited. If an `update` has been added, ORD-005 moved '
            . 'forward and its disposition needs updating.'
        );

        $noteController = $this->codeOf('Http/Controllers/OrderNoteController.php');

        foreach (['edit', 'update'] as $method) {
            $this->assertSame(
                0,
                preg_match('/function\s+' . $method . '\s*\(/', $noteController),
                "OrderNoteController::$method() now exists -- ORD-005's note-editing clause has "
                . 'been implemented and its disposition must be updated.'
            );
        }

        $api = $this->codeOf('routes/api.php', '');

        $this->assertSame(
            0,
            preg_match("/orders\/notes\/update/", $api),
            "An `orders/notes/update` route now exists -- ORD-005's note-editing clause moved and "
            . 'its disposition must be updated.'
        );
    }
}
