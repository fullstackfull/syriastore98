<?php

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * P1-SEC-80 -- the raw columns of every first-party DataTable, each with the reason it may print what it prints.
 * ==============================================================================================================
 *
 * A yajra DataTable ESCAPES every cell except the ones named in `rawColumns()`. A raw column prints whatever string the closure returns (or, with no closure at all, the model's own
 * attribute) straight into the page of the person reading the list. P1-SEC-79 found one such list on the merchant's side; P1-SEC-80 read the other 47 files and found about forty more
 * cells that printed text a shopper, a visitor, a merchant or a staff member had typed (executed in PanelListStoredXssTest and TenantListCellEscapingTest). What this file does is keep the
 * class from growing back:
 *
 *   1. Every raw column that exists is in the manifest below with ONE of five reasons. Adding a raw column fails here until a person has read what it prints and chosen a reason.
 *   2. No raw column is BARE (a raw column with no `editColumn`/`addColumn` prints the model's attribute as stored -- the shape of every defect this found).
 *   3. A column whose reason is "markup built here, every stored value through e()" has an `e(` in its closure. That is a weak pin (an `e(` on one of two values passes), and it is stated as
 *      one: the executed cases in the two Feature files are the evidence for the lists they drive; this is the tripwire for the rest.
 *
 * The reasons: VIEW (a Blade view, printed with `{{ }}`), BADGE (`DsBadge`/`OrderStatusTone`, which escape their label), ESCAPED (built here, `e()` on what it interpolates), SERVER (a date, a
 * number or an integer id the server makes, with no stored text), and a sentence for the few that need one.
 *
 * Source-level on purpose and limited to what source can say: it reads `rawColumns([...])` and the closures after PHP's own tokenizer has removed the comments, so a column named in a
 * comment is not counted and a commented-out `e(` does not satisfy (3).
 */
final class DataTableRawColumnsTest extends TestCase
{
    private const VIEW = 'V';
    private const BADGE = 'B';
    private const ESCAPED = 'E';
    private const SERVER = 'S';

    /** file => [column => [reason, optional sentence]] */
    private const MANIFEST = [
        'app/DataTables/AbandonCartDataTable.php' => ['customer_id' => ['E'], 'email' => ['E'], 'product' => ['V']],
        'app/DataTables/BlogDataTable.php' => ['cover_image_path' => ['E']],
        'app/DataTables/CategoryDataTable.php' => ['image_path' => ['E'], 'status' => ['B'], 'icon_path' => ['E'], 'parent_id' => ['E']],
        'app/DataTables/CouponDataTable.php' => ['discount_amount' => ['E']],
        'app/DataTables/CustomerDataTable.php' => ['first_name' => ['E'], 'last_active' => ['S'], 'regiester_date' => ['S'], 'orders' => ['S', 'a link built from a route and an integer count'], 'total_spend' => ['S'], 'aov' => ['S'], 'status' => ['V']],
        'app/DataTables/DeliveryBoyDataTable.php' => ['avatar' => ['E']],
        'app/DataTables/FlashSaleDataTable.php' => ['status' => ['V']],
        'app/DataTables/OrderDataTable.php' => ['product_order_id' => ['B'], 'order_date' => ['S'], 'customer_id' => ['E'], 'final_price' => ['S', 'the store\'s own money format (an owner-set currency symbol and a number)'], 'payment_type' => ['B'], 'order_source' => ['B'], 'delivered_status' => ['B']],
        'app/DataTables/OrderRefundDataTable.php' => ['order_id' => ['E'], 'refund_status' => ['B']],
        'app/DataTables/PageDataTable.php' => ['page_status' => ['S', 'a switch built here from the row id (an integer)']],
        'app/DataTables/PlanCouponDetailDataTable.php' => ['order_id' => ['E']],
        'app/DataTables/PlanOrderDataTable.php' => ['order_id' => ['E'], 'payment_status' => ['V'], 'receipt' => ['V']],
        'app/DataTables/ProductAttributeDataTable.php' => ['terms' => ['E']],
        'app/DataTables/ProductBrandDataTable.php' => ['status' => ['E'], 'is_popular' => ['E'], 'logo' => ['E']],
        'app/DataTables/ProductDataTable.php' => ['variant_product' => ['S', 'a translated label'], 'average_rating' => ['E'], 'price' => ['S', 'the store\'s own money format (an owner-set currency symbol and a number)'], 'stock_status' => ['S'], 'product_stock' => ['S'], 'cover_image_path' => ['E']],
        'app/DataTables/ProductLabelDataTable.php' => ['status' => ['E']],
        'app/DataTables/ProductQuestionDataTable.php' => ['answers' => ['B']],
        'app/DataTables/RoleDataTable.php' => ['permissions' => ['V']],
        'app/DataTables/SafeHelpdeskData.php' => ['ticket_id' => ['E'], 'name' => ['E'], 'created_by' => ['E'], 'subject' => ['E'], 'category_id' => ['E'], 'status_id' => ['E']],
        'app/DataTables/SalesDownloadableProductDataTable.php' => ['order_id' => ['E'], 'product_name' => ['E'], 'attachment' => ['E']],
        'app/DataTables/StoreDataTable.php' => ['avatar' => ['E'], 'type' => ['E']],
        'app/DataTables/SupportTicketDataTable.php' => ['ticket_id' => ['E'], 'order_id' => ['E'], 'created_at' => ['S'], 'customer_id' => ['E'], 'status' => ['E']],
        // P1-SEC-69 (d). The vendored Campaigns list declared these four raw and filled three of them
        // with `ucfirst($campaigns->users->name)` / `->stores->name` / `->products->name` -- a user's, a
        // store's and a PRODUCT's name, all merchant-written. The bound subclass keeps the vendored
        // structure and the same `rawColumns` SET, and runs the data through `e()`; the status cell is
        // markup built here from an integer, with the module's own label escaped and a missing-key guard.
        'app/DataTables/TenantScopedCampaignsData.php' => ['product' => ['E'], 'status' => ['E', 'a badge built here from the integer status, with the module\'s own label through e() and a guard for a status outside its four'], 'store' => ['E'], 'user' => ['E']],
        'app/DataTables/TestimonialDataTable.php' => ['avatar' => ['E'], 'rating_no' => ['S', 'five icons built from an integer'], 'verified_purchase' => ['B', 'CUST-005. DsBadge for the two assessed states; the NULL state is a literal dash with an e()d title, and no stored text reaches either branch -- the cell is built from a 0/1/NULL column alone']],
        'app/DataTables/UserCouponDataTable.php' => ['product_order_id' => ['E']],
        'app/DataTables/UserDataTable.php' => ['avatar' => ['E'], 'type' => ['B']],
        'app/DataTables/WishlistDataTable.php' => ['customer_id' => ['E'], 'email' => ['E'], 'product' => ['V']],
        'app/Http/Controllers/WoocomCategoryController.php' => ['cover_image' => ['E']],
        'app/Http/Controllers/WoocomCouponController.php' => ['amount' => ['S', 'a number and an icon']],
        'app/Http/Controllers/WoocomCustomerController.php' => ['customer_image' => ['E']],
        'app/Http/Controllers/WoocomProductController.php' => ['cover_image' => ['E']],
    ];

    /**
     * Columns that return a view but are NOT named in `rawColumns()`, so the manifest above does not hold them: yajra prints an Htmlable as it is, so they are raw cells, and each is recorded here
     * with what it prints. A stale entry (the closure no longer returns a view) fails the test that reads this.
     */
    private const HTMLABLE = [
        'app/Http/Controllers/ReportController.php' => ['stock_status' => 'three `DsBadge` views (`reports.{low,out,most}_stock_status`) of a stock state the controller computed: no stored text'],
    ];

    /** every first-party PHP file under app/ (a raw column is declared wherever a DataTable is built, not only in app/DataTables) */
    private static function files(): array
    {
        $files = [];
        $root = dirname(__DIR__, 3) . '/app';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** the source with its comments removed, by the tokenizer and not by a regex */
    private static function code(string $path): string
    {
        $out = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /** @return array<string, list<string>> relative path => its raw columns, `action` aside */
    private static function declared(): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];

        foreach (self::files() as $path) {
            $code = self::code($path);

            if (! preg_match_all('/rawColumns\(\s*\[(.*?)\]\s*\)/s', $code, $calls)) {
                continue;
            }

            $columns = [];
            foreach ($calls[1] as $list) {
                preg_match_all('/[\'"]([A-Za-z0-9_.\-]+)[\'"]/', $list, $names);
                foreach ($names[1] as $name) {
                    if ($name !== 'action') {
                        $columns[$name] = $name;
                    }
                }
            }

            if ($columns !== []) {
                $found[ltrim(str_replace($root, '', $path), '/')] = array_values($columns);
            }
        }

        ksort($found);

        return $found;
    }

    /** the source of the closure that builds a column: from `editColumn('x'`/`addColumn('x'` to its matching `)` */
    private static function closureOf(string $code, string $column): ?string
    {
        if (! preg_match_all('/->(?:editColumn|addColumn)\(\s*[\'"]' . preg_quote($column, '/') . '[\'"]/', $code, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $sources = [];
        foreach ($matches[0] as [$text, $offset]) {
            $open = strpos($code, '(', $offset + 2);
            $depth = 0;
            $quote = null;

            for ($i = $open, $n = strlen($code); $i < $n; $i++) {
                $c = $code[$i];

                if ($quote !== null) {
                    if ($c === '\\') {
                        $i++;
                    } elseif ($c === $quote) {
                        $quote = null;
                    }
                    continue;
                }

                if ($c === '\'' || $c === '"') {
                    $quote = $c;
                } elseif ($c === '(') {
                    $depth++;
                } elseif ($c === ')' && --$depth === 0) {
                    $sources[] = substr($code, $offset, $i - $offset + 1);
                    break;
                }
            }
        }

        return implode("\n", $sources);
    }

    /**
     * The ratchet reads `rawColumns([ 'a', 'b' ])`. A call it cannot read (a variable, `array_merge(...)`, a constant) would be a raw column it never sees, so such a call fails here
     * until it is rewritten in the readable shape, and so does any call that reconfigures what yajra escapes.
     */
    public function test_every_rawcolumns_call_is_one_the_parser_can_read_and_nobody_changes_what_is_escaped(): void
    {
        $root = dirname(__DIR__, 3);
        $calls = 0;

        foreach (self::files() as $path) {
            $code = self::code($path);
            $relative = ltrim(str_replace($root, '', $path), '/');

            $all = preg_match_all('/rawColumns\s*\(/', $code);
            $readable = preg_match_all('/rawColumns\(\s*\[(.*?)\]\s*\)/s', $code);
            $this->assertSame($all, $readable, $relative . ': a rawColumns() call is not a literal list, so a raw column in it would be invisible to this ratchet');
            $this->assertSame(0, preg_match_all('/->\s*(?:escapeColumns|setEscapeColumns)\s*\(/', $code), $relative . ': escapeColumns() changes what yajra escapes; every column it exempts is a raw column the ratchet cannot see');

            $calls += $all;
        }

        $this->assertGreaterThanOrEqual(30, $calls, 'premise: the scan found too few rawColumns() calls to mean anything');
    }

    public function test_the_parse_finds_what_it_is_meant_to_find(): void
    {
        $declared = self::declared();

        $this->assertGreaterThanOrEqual(25, count($declared), 'premise: the parse found too few files, so every check below would pass without reading anything');
        $this->assertGreaterThanOrEqual(60, array_sum(array_map('count', $declared)), 'premise: the parse found too few raw columns');
        $this->assertArrayHasKey('app/DataTables/OrderDataTable.php', $declared, 'premise: the list with the most raw columns must be among those found');
        $this->assertArrayNotHasKey('app/DataTables/NewsletterDataTable.php', $declared, 'premise: a list whose only raw column is `action` has nothing to classify');
    }

    /**
     * yajra does not escape an Htmlable value whatever `rawColumns()` says (`DataProcessor` runs `e()` on it, and `e()` returns an Htmlable's `toHtml()` as it is): a closure that returns a
     * `view(...)` or an `HtmlString` prints markup even from a column that is not named raw. So a column that returns one is a raw column, and has to be declared like one (a reason in the
     * manifest, which this test then also keeps true). `action` is exempt: it is raw in every list and is made of the host's own buttons.
     */
    public function test_a_column_that_returns_a_view_or_an_htmlable_is_declared_raw(): void
    {
        $root = dirname(__DIR__, 3);
        $checked = 0;
        $undeclared = [];
        $seen = [];

        foreach (self::files() as $path) {
            $code = self::code($path);
            $relative = ltrim(str_replace($root, '', $path), '/');

            if (! preg_match_all('/->(?:editColumn|addColumn)\(\s*[\'"]([A-Za-z0-9_.\-]+)[\'"]/', $code, $names)) {
                continue;
            }

            foreach (array_unique($names[1]) as $column) {
                $closure = self::closureOf($code, $column) ?? '';

                if (preg_match('/\bHtmlString\b|\bHtmlable\b|->toHtml\s*\(|\bview\s*\(|->render\s*\(/', $closure) !== 1) {
                    continue;
                }

                $checked++;

                $seen[$relative][$column] = true;

                if ($column !== 'action' && ! isset(self::MANIFEST[$relative][$column]) && ! isset(self::HTMLABLE[$relative][$column])) {
                    $undeclared[] = $relative . ': ' . $column;
                }
            }
        }

        foreach (self::HTMLABLE as $file => $columns) {
            foreach (array_keys($columns) as $column) {
                $this->assertArrayHasKey($column, $seen[$file] ?? [], $file . ': `' . $column . '` no longer returns a view: delete its entry from HTMLABLE');
            }
        }

        $this->assertGreaterThanOrEqual(40, $checked, 'premise: too few closures that return a view were found to mean anything');
        $this->assertSame([], $undeclared, 'these columns return a view or an Htmlable, which yajra prints unescaped even when the column is not named in rawColumns(): declare each as a raw column with its reason');
    }

    public function test_every_raw_column_has_a_reason_and_every_reason_has_a_raw_column(): void
    {
        $manifest = [];
        foreach (self::MANIFEST as $file => $columns) {
            $manifest[$file] = array_keys($columns);
            sort($manifest[$file]);
        }
        ksort($manifest);

        $declared = self::declared();
        foreach ($declared as &$columns) {
            sort($columns);
        }
        unset($columns);

        $this->assertSame($manifest, $declared, 'a raw column was added or removed: read what its cell prints, then record it here with its reason (or take it out of rawColumns() if it is plain text)');
    }

    public function test_no_raw_column_is_bare(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (self::declared() as $file => $columns) {
            $code = self::code($root . '/' . $file);

            foreach ($columns as $column) {
                $this->assertNotNull(self::closureOf($code, $column), $file . ': `' . $column . '` is a raw column with no closure, so it prints the model\'s own attribute as stored');
            }
        }
    }

    public function test_a_cell_that_says_it_escapes_does_call_e(): void
    {
        $root = dirname(__DIR__, 3);
        $checked = 0;

        foreach (self::MANIFEST as $file => $columns) {
            $code = self::code($root . '/' . $file);

            foreach ($columns as $column => $reason) {
                if ($reason[0] !== self::ESCAPED) {
                    continue;
                }

                $closure = self::closureOf($code, $column) ?? '';
                $this->assertMatchesRegularExpression('/\be\(/', $closure, $file . ': `' . $column . '` says every stored value it interpolates goes through e(), and its closure has no e( at all');
                $checked++;
            }
        }

        $this->assertGreaterThanOrEqual(25, $checked, 'premise: too few escaping cells were checked');
    }
}
