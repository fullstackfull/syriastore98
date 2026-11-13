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
    private const CONTROLLER = 'app/Http/Controllers/ThemeCustomizeController.php';
    private const MIGRATION = 'database/migrations/2025_05_23_043026_create_theme_customize_orders_table.php';

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** The columns the migration really creates, read from the migration rather than assumed. */
    private function columnsFromMigration(): array
    {
        $source = file_get_contents($this->root() . '/' . self::MIGRATION);

        $this->assertNotFalse($source, self::MIGRATION . ' is missing.');

        preg_match_all("/\\\$table->(?:string|longText|text|unsignedBigInteger|integer|boolean|json|date|dateTime|timestamp)\s*\(\s*'([^']+)'/",
            $source, $m);

        $columns = array_merge(['id'], $m[1]);

        if (str_contains($source, '$table->timestamps()')) {
            $columns[] = 'created_at';
            $columns[] = 'updated_at';
        }

        return $columns;
    }

    #[Test]
    public function test_the_migration_census_found_the_real_columns(): void
    {
        $columns = $this->columnsFromMigration();

        /* PREMISE. If the regex stops matching, every assertion below is vacuous. */
        $this->assertContains('page_slug', $columns);
        $this->assertContains('orders', $columns);
        $this->assertContains('store_id', $columns);
        $this->assertCount(6, $columns,
            'The table gained or lost a column. Re-read the migration before trusting the '
            . 'assertion below: ' . implode(', ', $columns));
    }

    #[Test]
    public function test_no_query_against_the_table_names_a_column_it_does_not_have(): void
    {
        $columns = $this->columnsFromMigration();
        $source = file($this->root() . '/' . self::CONTROLLER);

        $this->assertNotFalse($source, self::CONTROLLER . ' is missing.');

        $offenders = [];

        foreach ($source as $i => $line) {
            if (! str_contains($line, 'ThemeCustomizeOrder::')) {
                continue;
            }

            /* The statement can wrap, so take this line and the two after it. */
            $statement = $line . ($source[$i + 1] ?? '') . ($source[$i + 2] ?? '');

            preg_match_all("/'([a-z_][a-z0-9_]*)'\s*=>/i", $statement, $assoc);
            preg_match_all("/->where\s*\(\s*'([a-z_][a-z0-9_]*)'/i", $statement, $where);

            foreach (array_merge($assoc[1], $where[1]) as $named) {
                if (! in_array($named, $columns, true)) {
                    $offenders[] = self::CONTROLLER . ':' . ($i + 1) . "  names '{$named}'";
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "A query against `theme_customize_orders` names a column the table does not have.\n  %s\n\n"
            . "The table's columns are: %s\n\n"
            . "On MySQL this is ERROR 1054 and the route answers 500. On the suite's SQLite it is\n"
            . "SILENT -- the identifier becomes a string literal, nothing matches, the handler\n"
            . "carries on -- so a Feature test driving the route will NOT catch it.",
            implode("\n  ", $offenders), implode(', ', $columns)));
    }
}
