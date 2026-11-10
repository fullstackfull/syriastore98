<?php

namespace Tests\Unit\Security;

use App\Services\UserDeletionSafety;
use Tests\TestCase;

/**
 * P0-DELETE — the guard's dependency list must cover what production actually cascades.
 * =================================================================================================
 *
 * `UserDeletionSafety::CASCADE_DEPENDENCIES` is PINNED rather than discovered at runtime, because
 * a guard that reflects over the live schema silently narrows itself on any database missing a
 * table — and in this project the migrations are not the schema. The cost of pinning is that the
 * list can go stale, and this is what stops it.
 *
 * It reads the committed production dump's SCHEMA SECTION ONLY — the trailing
 * `ALTER TABLE ... ADD CONSTRAINT` block — and asserts in BOTH directions:
 *
 *   * every `ON DELETE CASCADE` foreign key to `users(id)` in production is in the list, so a new
 *     cascade cannot quietly escape the guard;
 *   * every entry in the list is a real production foreign key, so the list cannot drift into
 *     fiction and give false confidence.
 *
 * NO DATA ROWS ARE READ. The parse is restricted to `ADD CONSTRAINT` lines.
 *
 * If the dump is absent — a checkout without it, or after the P1-SEC-40 remediation removes it —
 * this test SKIPS rather than passing vacuously, and says so.
 */
class UserDeletionSafetyCoverageTest extends TestCase
{
    private const DUMP = 'storesyria_ecommercego.sql';

    /**
     * @return array<string, string> table => column, for every CASCADE FK to users(id)
     */
    private function productionCascadesToUsers(): array
    {
        $path = base_path(self::DUMP);

        if (! is_file($path)) {
            $this->markTestSkipped(
                'The production schema dump ' . self::DUMP . ' is not in this checkout, so the '
                . 'guard\'s dependency list cannot be checked against production. This test '
                . 'SKIPS rather than passing, because a pinned list with nothing to compare it '
                . 'against is exactly the stale list it exists to catch.'
            );
        }

        $found = [];
        $handle = fopen($path, 'r');
        $table = null;

        while (($line = fgets($handle)) !== false) {
            // Track which table the current ALTER block belongs to.
            if (preg_match('/^ALTER TABLE `([a-z0-9_]+)`/i', $line, $m) === 1) {
                $table = $m[1];
                continue;
            }

            if ($table === null || stripos($line, 'ADD CONSTRAINT') === false) {
                continue;
            }

            $matched = preg_match(
                '/FOREIGN KEY \(`([a-z0-9_]+)`\) REFERENCES `users` \(`id`\)(.*)$/i',
                $line,
                $m
            );

            if ($matched !== 1) {
                continue;
            }

            // Only CASCADE edges destroy rows. A SET NULL or RESTRICT edge is not this guard's
            // business, and counting one would make the list wider than the risk.
            if (stripos($m[2], 'ON DELETE CASCADE') === false) {
                continue;
            }

            $found[$table] = $m[1];
        }

        fclose($handle);

        return $found;
    }

    public function test_the_dump_really_does_declare_cascades_to_users(): void
    {
        $production = $this->productionCascadesToUsers();

        // The premise. If the parse found nothing, every assertion below would pass trivially and
        // the ratchet would be a decoration.
        $this->assertGreaterThanOrEqual(
            10,
            count($production),
            'The parse found ' . count($production) . ' CASCADE foreign keys to users(id) in the '
            . 'dump, which is too few to be right — 15 were measured by hand. The parser has '
            . 'stopped matching and every other assertion in this file is now vacuous.'
        );
    }

    public function test_every_production_cascade_to_users_is_covered_by_the_guard(): void
    {
        $production = $this->productionCascadesToUsers();
        $pinned = UserDeletionSafety::CASCADE_DEPENDENCIES;

        $uncovered = array_diff_key($production, $pinned);

        $this->assertSame(
            [],
            $uncovered,
            'These tables cascade off a user delete in PRODUCTION and are not in '
            . 'UserDeletionSafety::CASCADE_DEPENDENCIES, so deleting a user would destroy their '
            . "rows and the guard would not notice:\n  "
            . implode("\n  ", array_map(
                fn ($col, $t) => "$t.$col",
                $uncovered,
                array_keys($uncovered)
            ))
        );
    }

    public function test_the_guard_names_the_same_column_production_does(): void
    {
        $production = $this->productionCascadesToUsers();
        $pinned = UserDeletionSafety::CASCADE_DEPENDENCIES;

        foreach ($production as $table => $column) {
            if (! array_key_exists($table, $pinned)) {
                continue; // reported by the test above
            }

            $this->assertSame(
                $column,
                $pinned[$table],
                "The guard checks `$table`.`{$pinned[$table]}` but production cascades on "
                . "`$table`.`$column`. The guard is querying the wrong column, so it would count "
                . 'zero dependencies and allow the delete.'
            );
        }
    }

    public function test_the_guard_does_not_claim_a_dependency_production_does_not_have(): void
    {
        $production = $this->productionCascadesToUsers();
        $pinned = UserDeletionSafety::CASCADE_DEPENDENCIES;

        $invented = array_diff_key($pinned, $production);

        $this->assertSame(
            [],
            $invented,
            'These entries are in the guard but are NOT CASCADE foreign keys to users(id) in '
            . "production. A list that drifts into fiction gives false confidence:\n  "
            . implode("\n  ", array_keys($invented))
        );
    }
}
