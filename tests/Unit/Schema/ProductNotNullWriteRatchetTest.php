<?php

namespace Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * F59 / F61 -- no bare request value may be written into a NOT NULL products column.
 * =============================================================================================
 *
 * SEVEN INSTANCES OF ONE DEFECT, FOUND ONE AT A TIME. `ProductController` assigns
 * `$product->COLUMN = $request->KEY;` dozens of times. Where the column is NOT NULL and the key
 * can be absent, that is an explicit NULL write, and a column DEFAULT does not rescue it:
 *
 *     UPDATE, non-strict MySQL   the implicit default is stored, with a warning   (production)
 *     UPDATE, strict MySQL       ER_BAD_NULL_ERROR
 *     INSERT, ANY sql_mode       ER_BAD_NULL_ERROR -- a single-row INSERT of NULL always errors
 *     SQLite                     NOT NULL constraint failed                      (CI, dev)
 *
 * and neither `store()` nor `update()` has a try/catch, so the merchant got a bare 500.
 *
 * The seven were found across three packages: `track_stock` by driving the editor (F59), then
 * `stock_status` and `product_stock` by reviewing that fix (F61), then `trending` by reviewing
 * THAT fix, and then `trending` again in store()'s two branches plus `sale_price` twice and
 * `stock_status` in store() -- those last four by the scan this file now performs. Each round
 * found the previous round's siblings, which is the signature of a defect that wants a ratchet
 * rather than another fix.
 *
 * WHY THE VIEW HID THEM. Every one of these columns is posted unconditionally by the create and
 * edit forms -- `trending` and the other checkboxes carry a hidden `0` partner, `stock_status`
 * has `checked` on a radio -- so no browser can reach the branch. The live callers are
 * non-browser clients and hand-made requests, which is why nothing in the suite had ever posted
 * a payload with a key MISSING. `ProductConcurrentEditTest::payload()` even documents that it
 * carries `trending` and `track_stock` *because* omitting them is a 500: the suite had measured
 * the defect, worked around it in a fixture, and moved on.
 *
 * WHAT THIS RATCHETS, and what it deliberately does not. It reads the NOT NULL columns out of
 * the products migration and requires every assignment from request input into one of them to be
 * either guarded on the spot, guarded by a condition on the same request key immediately above
 * it, or validated `required`. It does NOT check the VALUE chosen -- whether an absent
 * `product_stock` should become 0 or keep the stored quantity is a product decision, recorded in
 * `ProductUpdateNullGuardsTest::omittedKeys()`. This file only insists that it is never NULL.
 */
class ProductNotNullWriteRatchetTest extends TestCase
{
    private const CONTROLLER = 'app/Http/Controllers/ProductController.php';
    private const MIGRATION = 'database/migrations/2023_12_26_092822_create_products_table.php';

    private function root(string $path): string
    {
        return dirname(__DIR__, 3) . '/' . $path;
    }

    /** @return array<string,string> column => declared type, for NOT NULL columns only */
    private function notNullColumns(): array
    {
        $mig = file_get_contents($this->root(self::MIGRATION));
        $this->assertNotFalse($mig, 'the products migration is unreadable');

        $cols = [];
        foreach (preg_split('/;\s*\n/', $mig) as $stmt) {
            if (! preg_match('/\$table->(\w+)\(\s*\'(\w+)\'/', $stmt, $m)) {
                continue;
            }
            [, $type, $col] = $m;
            // `nullable()` opts out; `id`/timestamps are not written from request input
            if (str_contains($stmt, '->nullable()') || in_array($col, ['id'], true)) {
                continue;
            }
            $cols[$col] = $type . (str_contains($stmt, '->default(') ? ' NOT NULL DEFAULT' : ' NOT NULL');
        }

        return $cols;
    }

    public function test_the_migration_still_declares_the_columns_this_guards(): void
    {
        $cols = $this->notNullColumns();

        // A premise, not decoration: if the parse silently returned nothing, the scan below
        // would pass by having no columns to check -- the vacuous pass this project keeps
        // meeting. These four are the ones the seven defects landed on.
        $this->assertGreaterThanOrEqual(10, count($cols),
            'the NOT NULL parse collapsed; the scan below would be vacuous');
        foreach (['track_stock', 'stock_status', 'product_stock', 'trending', 'sale_price'] as $col) {
            $this->assertArrayHasKey($col, $cols, $col . ' is no longer parsed as NOT NULL');
        }
    }

    public function test_no_bare_request_value_is_written_into_a_not_null_column(): void
    {
        $notNull = $this->notNullColumns();
        $src = file_get_contents($this->root(self::CONTROLLER));
        $this->assertNotFalse($src, 'the controller is unreadable');

        // Fields the controller validates as `required` cannot be absent, so a bare assignment
        // is sound. Taken from the file rather than hard-coded, so dropping a rule re-arms this.
        preg_match_all("/'(\w+)'\s*=>\s*'required/", $src, $req);
        $required = array_unique($req[1]);

        $lines = explode("\n", $src);
        $offenders = [];

        foreach ($lines as $i => $line) {
            if (! preg_match('/\$(?:P|p)roduct->(\w+)\s*=\s*(\$request->[\w\'"\[\]]+)\s*;/', $line, $m)) {
                continue;
            }
            [, $col, $expr] = $m;
            if (! isset($notNull[$col])) {
                continue;
            }
            if (in_array($col, $required, true)) {
                continue;
            }
            // guarded on the line itself
            if (preg_match('/\?\?|!empty\(|isset\(|\s\?\s/', $line)) {
                continue;
            }
            // or guarded by a condition on the SAME request key just above it -- the shape at
            // store()'s `if (isset($request->track_stock) && $request->track_stock == 1) {`,
            // which a line-only scan reports as a false positive
            $above = implode("\n", array_slice($lines, max(0, $i - 4), min(4, $i)));
            if (preg_match('/if\s*\(.*\$request->' . preg_quote($col, '/') . '\b/s', $above)) {
                continue;
            }

            $offenders[] = sprintf('%s:%d  %s (%s) <- %s', self::CONTROLLER, $i + 1, $col, $notNull[$col], $expr);
        }

        $this->assertSame([], $offenders, "unguarded NULL write into a NOT NULL products column:\n  "
            . implode("\n  ", $offenders)
            . "\n\nGuard it on the line (`?? <default>`, or `?? \$product->{column}` where the stored"
            . "\nvalue should survive), or validate the field as required. See F59/F61 in the ledger.");
    }
}
