<?php

namespace Tests\Feature\Lynomia;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every `->after('col')` in a Lynomia migration must name a column the schema actually has.
 *
 * WHY THIS TEST EXISTS, AND WHY IT IS NOT THE SAME TEST THAT WAS ALREADY GREEN. The
 * reconciliation-watermark migration placed its column `->after('lynomia_installation')` -- a
 * column `lynomia_store_links` has never had. SQLite, which every other test runs on, IGNORES
 * column positioning, so the whole suite stayed green while the migration was broken. MariaDB
 * and MySQL do not: `ALTER TABLE ... ADD ... AFTER` a column that does not exist is errno 1054
 * ("Unknown column"), and it took down the first real deploy onto MariaDB 11.4.
 *
 * THE DB CANNOT CATCH THIS HERE, SO THE MIGRATION SOURCE IS CHECKED INSTEAD. We cannot reproduce
 * the MariaDB rejection on the in-memory SQLite this suite is pinned to (TestDatabaseGuard
 * enforces sqlite). What we CAN do is assert the invariant MariaDB would have enforced: after
 * the migrations have run, every column an `after()` clause names is present in the table it
 * targets. A clause naming a column that does not exist is exactly what MariaDB rejects, and is
 * failed here on any database -- which is the whole point, since the failure must be visible on
 * the SQLite the deploy pipeline tests on BEFORE it reaches the MariaDB it deploys on.
 *
 * Comments are stripped before scanning, so this file's own prose about the historical
 * `after('lynomia_installation')` bug (and the migration docblock that explains the fix) is not
 * mistaken for a real clause.
 */
class LynomiaMigrationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATIONS = 'packages/workdo/LynomiaChat/src/database/migrations';

    public function test_every_after_clause_targets_a_column_that_exists(): void
    {
        $references = $this->afterReferences();

        $this->assertNotEmpty(
            $references,
            'no `after()` clauses were found to check -- the scanner or the migrations path is wrong'
        );

        foreach ($references as $ref) {
            [$file, $table, $column] = $ref;

            $this->assertTrue(
                Schema::hasTable($table),
                "{$file}: after() targets table `{$table}`, which the migrated schema does not have"
            );
            $this->assertTrue(
                Schema::hasColumn($table, $column),
                "{$file}: `{$table}` is placed after column `{$column}`, which does not exist -- "
                    . 'this is what MariaDB/MySQL reject with "Unknown column" (errno 1054)'
            );
        }
    }

    /**
     * Every ($file, $table, $column) an `->after('column')` clause names, with $table resolved
     * to the Schema::create()/Schema::table() call it sits inside. Comments are removed first so
     * only real code is scanned.
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    private function afterReferences(): array
    {
        $dir = base_path(self::MIGRATIONS);
        $this->assertDirectoryExists($dir);

        $references = [];

        foreach (glob($dir . '/*.php') as $path) {
            $code = $this->stripComments((string) file_get_contents($path));
            $file = basename($path);

            // One pass over the file: a Schema::create/table call sets the current table, an
            // after() clause records the column against whatever that current table is. Matching
            // in source order is what ties each after() to the right table even in a migration
            // that touches more than one.
            preg_match_all(
                '/Schema::(?:create|table)\(\s*[\'"]([A-Za-z0-9_]+)[\'"]|->after\(\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*\)/',
                $code,
                $matches,
                PREG_SET_ORDER
            );

            $currentTable = null;
            foreach ($matches as $match) {
                if (($match[1] ?? '') !== '') {
                    $currentTable = $match[1];
                    continue;
                }

                $column = $match[2] ?? '';
                $this->assertNotNull(
                    $currentTable,
                    "{$file}: an after() clause was found with no enclosing Schema table call"
                );
                $references[] = [$file, $currentTable, $column];
            }
        }

        return $references;
    }

    /** The source with T_COMMENT and T_DOC_COMMENT removed, so prose is never scanned as code. */
    private function stripComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }
}
