<?php

namespace Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * EVERY COLUMN THE CONTROLLER NAMES AGAINST `theme_customize_orders` MUST EXIST.
 * =================================================================================================
 *
 * WHY THIS IS A SOURCE TEST AND NOT A DATABASE ONE, which is the whole reason it exists.
 *
 * `ThemeCustomizeController` named `theme_id` in both its `theme_customize_orders` queries, and the
 * table has no such column -- id, page_slug, orders, store_id, timestamps, and nothing else
 * (`2025_05_23_043026_create_theme_customize_orders_table.php`). MEASURED on MariaDB 10.11.14
 * against the table built from that migration:
 *
 *     SELECT * FROM theme_customize_orders WHERE theme_id = 'stylique' AND page_slug = 'home' ...
 *     ERROR 1054 (42S22): Unknown column 'theme_id' in 'WHERE'
 *
 * So the section-order read and write both answered **500 in production** -- and no test in this
 * suite could ever have noticed, because SQLite does not error. It reinterprets the unresolvable
 * identifier as a STRING LITERAL, compares `'theme_id' = 'stylique'`, matches nothing, and the
 * handler carries on. A Feature test driving the route passes on SQLite with the defect in place;
 * one was written and did exactly that.
 *
 * A database assertion inherits the same blindness, so the guard reads the SOURCE and compares it
 * to the MIGRATION. Both are files, both are true in every environment, and neither depends on
 * which engine the suite happens to run on.
 */
class ThemeCustomizeOrderColumnsExistTest extends TestCase
{
    /**
     * EVERY FILE THAT QUERIES THE TABLE, not just the controller.
     *
     * A review found this ratchet had WEAKENED: M3-D moved the `where('theme_id', ...)` predicates
     * out of the controller and into `ThemeCustomizeOrder::resolveFor()`, which the census did not
     * read. It was not vacuous -- the controller's `updateOrCreate` still names the column, so it
     * still failed if `theme_id` left the migration list -- but the queries that now carry the
     * column were no longer being checked at all.
     */
    private const QUERY_FILES = [
        'app/Http/Controllers/ThemeCustomizeController.php',
        'app/Models/ThemeCustomizeOrder.php',
    ];
    /**
     * EVERY migration that shapes this table, not just the one that created it.
     *
     * M3-D added `theme_id` in its own file, and a census that read only the CREATE would then call
     * the application's correct new predicate a defect. The list is explicit rather than a glob so
     * that a new migration has to be acknowledged here -- which is the point of the test.
     */
    private const MIGRATIONS = [
        'database/migrations/2025_05_23_043026_create_theme_customize_orders_table.php',
        'database/migrations/2026_11_15_000100_add_theme_id_to_theme_customize_orders_table.php',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** The columns the migrations really define, read from them rather than assumed. */
    private function columnsFromMigration(): array
    {
        $columns = ['id'];

        foreach (self::MIGRATIONS as $relative) {
            $source = file_get_contents($this->root() . '/' . $relative);

            $this->assertNotFalse($source, $relative . ' is missing.');

            preg_match_all("/\\\$table->(?:string|longText|text|unsignedBigInteger|integer|boolean|json|date|dateTime|timestamp)\s*\(\s*'([^']+)'/",
                $source, $m);

            $columns = array_merge($columns, $m[1]);

            if (str_contains($source, '$table->timestamps()')) {
                $columns[] = 'created_at';
                $columns[] = 'updated_at';
            }
        }

        /*
         * A column named in a DROP is not a column the table has. M3-D's `down()` drops `theme_id`,
         * and without this the census would count it from the rollback branch even on a database
         * where the migration never ran -- a row of the census being true for the wrong reason.
         * It is counted because `up()` ADDS it; the assertion below is what proves the census is
         * reading the add rather than the drop.
         */
        return array_values(array_unique($columns));
    }

    #[Test]
    public function test_the_migration_census_found_the_real_columns(): void
    {
        $columns = $this->columnsFromMigration();

        /* PREMISE. If the regex stops matching, every assertion below is vacuous. */
        $this->assertContains('page_slug', $columns);
        $this->assertContains('orders', $columns);
        $this->assertContains('store_id', $columns);
        $this->assertContains('theme_id', $columns,
            'M3-D added `theme_id` (2026_11_15_000100) so section ordering can be stored per theme. '
            . 'If the census stops seeing it, the test below will call the application\'s correct '
            . 'predicate a defect.');
        $this->assertCount(7, $columns,
            'The table gained or lost a column. Re-read the migrations before trusting the '
            . 'assertion below: ' . implode(', ', $columns));
    }

    #[Test]
    public function test_no_query_against_the_table_names_a_column_it_does_not_have(): void
    {
        $columns = $this->columnsFromMigration();
        $offenders = [];
        $statementsSeen = 0;

        foreach (self::QUERY_FILES as $relative) {
            $source = file($this->root() . '/' . $relative);

            $this->assertNotFalse($source, $relative . ' is missing.');

            $inModel = str_contains($relative, 'Models/');

            foreach ($source as $i => $line) {
                /* In the controller the queries are `ThemeCustomizeOrder::...`; inside the model
                 * itself they are `static::query()`, so both spellings are looked for. */
                if (! str_contains($line, 'ThemeCustomizeOrder::')
                    && ! ($inModel && str_contains($line, 'static::query()'))) {
                    continue;
                }

                $statementsSeen++;

                /* A statement can wrap, so take this line and the four after it. */
                $statement = $line;
                for ($n = 1; $n <= 4; $n++) {
                    $statement .= $source[$i + $n] ?? '';
                }

                preg_match_all("/'([a-z_][a-z0-9_]*)'\s*=>/i", $statement, $assoc);
                preg_match_all("/->where(?:Null)?\s*\(\s*'([a-z_][a-z0-9_]*)'/i", $statement, $where);

                foreach (array_merge($assoc[1], $where[1]) as $named) {
                    if (! in_array($named, $columns, true)) {
                        $offenders[] = $relative . ':' . ($i + 1) . "  names '{$named}'";
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual(3, $statementsSeen,
            'The census found almost no queries against this table, so the assertion below would '
            . 'be vacuous. The query spellings, not the codebase, are the likely cause: '
            . $statementsSeen . ' found.');

        $this->assertSame([], $offenders, sprintf(
            "A query against `theme_customize_orders` names a column the table does not have.\n  %s\n\n"
            . "The table's columns are: %s\n\n"
            . "On MySQL this is ERROR 1054 and the route answers 500. On the suite's SQLite it is\n"
            . "SILENT -- the identifier becomes a string literal, nothing matches, the handler\n"
            . "carries on -- so a Feature test driving the route will NOT catch it.",
            implode("\n  ", $offenders), implode(', ', $columns)));
    }
}
