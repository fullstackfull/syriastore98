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
     * @var array<string, array{keys: list<string>, what: string, mutates_stored_line: bool}>
     */
    private const LINE_KEY_WRITERS = [
        'Http/Controllers/Api/ApiController.php' => [
            'keys' => ['product_id', 'qty', 'variant_id'],
            'what' => 'the cart response array; the product_return() request payload, which NAMES '
                . 'a line rather than editing one; and one API response field',
            'mutates_stored_line' => false,
        ],

        'Http/Controllers/OrderController.php' => [
            'keys' => ['product_id', 'variant_id'],
            'what' => 'the product_return() request payload, web side, behind '
                . "authorize('updateStatus')",
            'mutates_stored_line' => false,
        ],

        'Http/Controllers/PosController.php' => [
            'keys' => ['quantity'],
            'what' => 'the POS sales REPORT display array; in-memory, never written back',
            'mutates_stored_line' => false,
        ],

        // ---- the two that really do change a stored line ----

        'Http/Controllers/AccountProfileController.php' => [
            'keys' => ['qty'],
            'what' => 'the storefront refund REQUEST subtracts refunded units from the line and '
                . 'writes product_json back',
            'mutates_stored_line' => true,
        ],

        'Http/Controllers/OrderRefundController.php' => [
            'keys' => ['qty'],
            'what' => 'cancelling a refund request adds the units back, bounded by the line\'s own '
                . 'refunded_qty counter',
            'mutates_stored_line' => true,
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
    public function test_no_stored_order_line_has_its_product_or_variant_identity_rewritten(): void
    {
        $offenders = [];

        foreach (self::LINE_KEY_WRITERS as $file => $declaration) {
            if (! $declaration['mutates_stored_line']) {
                continue;
            }

            foreach (['product_id', 'variant_id'] as $identityKey) {
                if (in_array($identityKey, $declaration['keys'], true)) {
                    $offenders[] = "$file assigns '$identityKey' on a stored order line";
                }
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
            $this->assertSame(
                ['qty'],
                self::LINE_KEY_WRITERS[$file]['keys'],
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
