<?php

namespace Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;

/**
 * CLAUDE.md'S SCOPED-MODEL TABLE, RE-MEASURED AGAINST THE CODE IT DESCRIBES.
 * =================================================================================================
 *
 * WHY THIS EXISTS, AND IT IS NOT A HYPOTHETICAL. On 2026-11-24 an adversarial verifier was handed
 * that table as part of a Phase 06 inventory brief and REFUSED it. Two measured errors:
 *
 *   - CLAUDE.md said in two places that `DeliveryBoy` is NOT scoped. It IS: it sits in
 *     `config/tenancy.php`'s `scoped_models` and registers `StoreScope`, so it genuinely filters.
 *   - The table named only `Customer` and `Cart` as filtering. ELEVEN models do.
 *
 * The error had already propagated: it was copied verbatim into six subagent briefs that same day,
 * because the brief's author trusted the file the project tells everyone to trust. That is the
 * failure mode worth a test -- not the wrong row itself, but a wrong row in the ONE document every
 * session loads.
 *
 * WHICH DIRECTION IS DANGEROUS. This particular error was the SAFE one: it claimed a model was
 * unscoped when it filters, so code written against it adds a redundant predicate. The opposite --
 * believing a model filters when it does not -- is how the S19 cross-tenant stock drain happened,
 * and it is why `Product`'s row is the one CLAUDE.md's prose dwells on. Both directions are
 * asserted here, because a document that is only checked in the comfortable direction is not
 * checked.
 *
 * NO APPLICATION BOOT, DELIBERATELY, so this can live in the fast blocking suite. Measured: the
 * allowlist is a plain array returned by `config/tenancy.php` and is readable with `require`, and
 * `StoreScope` registration happens in the models' own Eloquent `booted()` -- a bare `new Customer`
 * already reports `SoftDeletingScope,StoreScope`. Neither fact needs the container.
 *
 * WHAT IT DOES NOT ASSERT. That the scope is CORRECT, or that a scoped model is safe. Membership
 * plus registration is what CLAUDE.md's table claims and all this file judges. The behavioural
 * questions -- that `StoreScope::isActiveFor()` short-circuits for an unlisted model, that
 * `null_tenant => 'open'` adds no predicate when no tenant resolves -- belong to
 * `StoreScopeContextStackTest` and the Feature tenancy suites, which already hold them.
 */
class ClaudeMdScopedModelClaimTest extends TestCase
{
    /** The table's header, used to find it without depending on a line number. */
    private const TABLE_HEADER = '| Model | `StoreScope` registered | In `scoped_models` | Actually filters |';

    /** @return list<array{models: list<string>, registered: bool, allowlisted: bool, filters: bool}> */
    private function documentedRows(): array
    {
        $claudeMd = file_get_contents(dirname(__DIR__, 3) . '/CLAUDE.md');

        $this->assertNotFalse($claudeMd, 'CLAUDE.md could not be read.');

        $at = strpos($claudeMd, self::TABLE_HEADER);

        $this->assertNotFalse($at,
            'CLAUDE.md no longer contains the scoped-model table with the header this test keys '
            . 'on. If the table was deliberately restructured, re-point this test at the new shape '
            . 'rather than deleting it: the table is wrong twice in its history and the second '
            . 'time it reached six subagent briefs before anyone checked.');

        $rows = [];

        foreach (explode("\n", substr($claudeMd, $at)) as $i => $line) {
            if ($i < 2) {
                continue; // the header and the |---| separator
            }

            if (! str_starts_with(trim($line), '|')) {
                break; // the table has ended
            }

            $cells = array_map('trim', array_slice(explode('|', trim($line, "| \t")), 0));

            $this->assertCount(4, $cells,
                "A row of CLAUDE.md's scoped-model table does not have four cells: {$line}");

            preg_match_all('/`([^`]+)`/', $cells[0], $names);

            $this->assertNotEmpty($names[1],
                "A row of the table names no model in backticks: {$line}");

            $rows[] = [
                'models' => $names[1],
                'registered' => $this->isYes($cells[1]),
                'allowlisted' => $this->isYes($cells[2]),
                'filters' => $this->isYes($cells[3]),
            ];
        }

        $this->assertGreaterThanOrEqual(5, count($rows),
            'Fewer table rows were parsed than the table has ever had, so the parser has drifted '
            . 'from the markup and the assertions below are judging almost nothing.');

        return $rows;
    }

    /**
     * `yes`, `**yes**`, `no`, `**NO**` -- the table uses emphasis for the rows that bite -- and
     * `yes (+ SoftDeletingScope)`, because `Customer` carries a second scope worth naming.
     *
     * THE PARENTHETICAL IS ALLOWED, THE VERDICT IS NOT INFERRED FROM IT. Only the leading word
     * decides, and a cell that does not START with yes or no is a parse failure rather than a
     * guess: this test's whole value is that it cannot be satisfied by prose it half-understood.
     */
    private function isYes(string $cell): bool
    {
        $plain = strtolower(trim(str_replace(['*', '`'], '', $cell)));

        $this->assertSame(1, preg_match('/^(yes|no)\b/', $plain, $verdict),
            "A yes/no cell in CLAUDE.md's scoped-model table reads '{$cell}', which does not begin "
            . 'with yes or no. A trailing parenthetical is fine; an unparseable verdict is not.');

        return $verdict[1] === 'yes';
    }

    /** @return list<string> */
    private function allowlist(): array
    {
        $config = require dirname(__DIR__, 3) . '/config/tenancy.php';

        $this->assertArrayHasKey('scoped_models', $config,
            'config/tenancy.php no longer declares scoped_models, which is the allowlist this '
            . 'whole mechanism turns on.');

        return array_values($config['scoped_models']);
    }

    public function test_every_model_the_table_names_is_classified_as_the_code_classifies_it(): void
    {
        $allowlist = $this->allowlist();
        $checked = 0;

        foreach ($this->documentedRows() as $row) {
            foreach ($row['models'] as $short) {
                $class = 'App\\Models\\' . $short;

                $this->assertTrue(class_exists($class),
                    "CLAUDE.md's scoped-model table names `{$short}`, and {$class} does not exist. "
                    . 'A table that names a model the codebase does not have is stale in a way no '
                    . 'reader can detect.');

                $this->assertSame(in_array($class, $allowlist, true), $row['allowlisted'],
                    "CLAUDE.md says `{$short}` is "
                    . ($row['allowlisted'] ? 'IN' : 'NOT in')
                    . " config/tenancy.php's scoped_models, and the config says the opposite. "
                    . 'Membership in that allowlist is what StoreScope::isActiveFor() turns on, so '
                    . 'this row is advice about tenant isolation and it is wrong.');

                $registered = array_map(
                    'class_basename',
                    array_keys((new $class)->getGlobalScopes())
                );

                $this->assertSame(in_array('StoreScope', $registered, true), $row['registered'],
                    "CLAUDE.md says `{$short}` "
                    . ($row['registered'] ? 'registers' : 'does not register')
                    . ' StoreScope, and the model says the opposite. Registered scopes found: '
                    . (implode(', ', $registered) ?: '(none)'));

                $this->assertSame(
                    $row['registered'] && $row['allowlisted'],
                    $row['filters'],
                    "CLAUDE.md's own two columns for `{$short}` do not agree with its third: a "
                    . 'model filters if and only if it BOTH registers the scope AND is in the '
                    . 'allowlist. That is the distinction the table exists to make.'
                );

                $checked++;
            }
        }

        $this->assertGreaterThanOrEqual(10, $checked,
            'Fewer models were checked than the table documents, so the parser is skipping rows.');
    }

    /**
     * THE RATCHET, AND IT IS THE HALF THAT WOULD HAVE CAUGHT THE ORIGINAL ERROR.
     *
     * The assertions above judge only what the table NAMES. The table's first error was an omission
     * -- eight filtering models it never mentioned -- and no per-row check can see that. So the set
     * of models the table marks as allowlisted must EQUAL the allowlist exactly, which makes a
     * model added to `config/tenancy.php` and not to CLAUDE.md a red test rather than a silent
     * divergence.
     */
    public function test_the_table_accounts_for_every_model_in_the_allowlist(): void
    {
        $documented = [];

        foreach ($this->documentedRows() as $row) {
            if (! $row['allowlisted']) {
                continue;
            }

            foreach ($row['models'] as $short) {
                $documented[] = 'App\\Models\\' . $short;
            }
        }

        $allowlist = $this->allowlist();

        sort($documented);
        sort($allowlist);

        $this->assertSame($allowlist, $documented,
            "CLAUDE.md's scoped-model table and config/tenancy.php's scoped_models are not the "
            . 'same set. Missing from CLAUDE.md: '
            . (implode(', ', array_diff($allowlist, $documented)) ?: '(none)')
            . '. Named by CLAUDE.md but not in the config: '
            . (implode(', ', array_diff($documented, $allowlist)) ?: '(none)')
            . '. The table is the advice every session reads about which models isolate tenants; a '
            . 'model missing from it reads as unscoped, which is how this table was wrong before.');
    }
}
