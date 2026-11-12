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
 * It reads production's SCHEMA ONLY — `ALTER TABLE ... ADD CONSTRAINT` lines — and asserts in BOTH
 * directions:
 *
 *   * every `ON DELETE CASCADE` foreign key to `users(id)` in production is in the list, so a new
 *     cascade cannot quietly escape the guard;
 *   * every entry in the list is a real production foreign key, so the list cannot drift into
 *     fiction and give false confidence.
 *
 * NO DATA ROWS ARE READ.
 *
 * WHERE THE SCHEMA COMES FROM, AND WHY IT MOVED (P1-SEC-40, 2026-11-11). This used to read the
 * committed dump, `storesyria_ecommercego.sql`, and to SKIP when it was absent — deliberately, so
 * that a pinned list with nothing to compare against could not pass vacuously. Then P1-SEC-40
 * untracked the dump, and the skip became the normal case: four cases of real coverage would have
 * gone quiet in every checkout and in CI, with a green suite and no failure to notice it. A
 * security remediation must not silently delete security coverage.
 *
 * So the 15 relevant constraint lines now live in a committed fixture,
 * `tests/Support/Security/fixtures/production-users-cascades.txt`, verified at extraction to carry
 * no hash, email, IP address or data row. The fixture is the source, so the four cases run
 * everywhere. A fifth case cross-checks the fixture against the dump wherever the dump is still
 * present, so the fixture cannot drift from production unnoticed; that one case — and only that
 * one — skips when the dump is absent.
 */
class UserDeletionSafetyCoverageTest extends TestCase
{
    private const DUMP = 'storesyria_ecommercego.sql';

    private const FIXTURE = 'tests/Support/Security/fixtures/production-users-cascades.txt';

    /**
     * @return array<string, string> table => column, for every CASCADE FK to users(id)
     */
    private function productionCascadesToUsers(): array
    {
        $path = base_path(self::FIXTURE);

        if (! is_file($path)) {
            $this->markTestSkipped(
                'The constraint fixture ' . self::FIXTURE . ' is missing, so the guard\'s '
                . 'dependency list cannot be checked against production. This test SKIPS rather '
                . 'than passing, because a pinned list with nothing to compare it against is '
                . 'exactly the stale list it exists to catch. The fixture is committed — if it '
                . 'is gone, restore it rather than deleting this test. Regenerate it from the '
                . 'trailing ALTER TABLE block of the dump; the header in the fixture says how.'
            );
        }

        return $this->parseCascades($path);
    }

    /**
     * @return array<string, string> table => column
     */
    private function parseCascades(string $path): array
    {
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

    public function test_the_fixture_really_does_declare_cascades_to_users(): void
    {
        $production = $this->productionCascadesToUsers();

        // The premise. If the parse found nothing, every assertion below would pass trivially and
        // the ratchet would be a decoration.
        $this->assertGreaterThanOrEqual(
            10,
            count($production),
            'The parse found ' . count($production) . ' CASCADE foreign keys to users(id) in the '
            . 'fixture, which is too few to be right — 15 were measured by hand. The parser has '
            . 'stopped matching and every other assertion in this file is now vacuous.'
        );
    }

    /**
     * The fixture is an extract, so it can drift from what it was extracted from. This is the
     * only case that needs the dump, and the only one that skips without it.
     */
    public function test_the_fixture_still_agrees_with_the_production_dump(): void
    {
        $dump = base_path(self::DUMP);

        if (! is_file($dump)) {
            $this->markTestSkipped(
                'The production dump ' . self::DUMP . ' is not in this checkout, which is the '
                . 'expected state after P1-SEC-40 untracked it — so the fixture cannot be '
                . 'cross-checked against its source here. The other four cases in this file do '
                . 'NOT skip: they read the committed fixture. Run this one in a checkout that '
                . 'still has the dump, or against a restored copy, when regenerating the fixture.'
            );
        }

        $this->assertSame(
            $this->parseCascades($dump),
            $this->productionCascadesToUsers(),
            'The committed fixture ' . self::FIXTURE . ' no longer matches the CASCADE foreign '
            . 'keys in ' . self::DUMP . '. The fixture is a stale extract, so the other four '
            . 'cases in this file are now checking the guard against the wrong schema. '
            . 'Regenerate it from the dump\'s trailing ALTER TABLE block — and re-run the '
            . 'cleanliness census in docs/security/P1-SEC-40-INCIDENT.md section 2 on the result, '
            . 'because the extract must carry no data row.'
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
