<?php

namespace Tests\Feature\Tenancy;

use Tests\TestCase;

/**
 * GAP-253 -- RAW SQL WRITTEN AS A QUERY-BUILDER METHOD IS IN NO INVENTORY, AND THAT IS 113 SITES.
 * =================================================================================================
 *
 * THE ROW NAMED TWO STATEMENTS. `SaasRevenueFigures` holds two `selectRaw` calls that
 * `RawQueryTenancyInventoryTest` does not count, and that file's own comment says so. The reason
 * generalises, and the generalisation is worth more than the two statements, so it is measured here.
 *
 * WHY THEY ARE INVISIBLE -- ESTABLISHED AT THE CENSUS, NOT TAKEN FROM THE COMMENT. Three candidate
 * causes were checked and two are innocent:
 *
 *   - NOT the file enumeration. `RawQueryTenancyInventoryTest::phpFiles()` is a fully recursive
 *     `RecursiveIteratorIterator` over `app` and `packages`, so `app/Services` is scanned.
 *   - NOT the comment stripping. Its `codeLines()` drops lines that BEGIN with a comment marker,
 *     which can only make it count more, never fewer.
 *   - IT IS THE PATTERN, and by construction:
 *
 *         RAW_ENTRY_POINT_PATTERN = '/\\?DB::(table|select|insert|update|delete|statement|unprepared)\s*\(/'
 *
 *     That matches the `DB` FACADE's entry points. Raw SQL written as a method ON A BUILDER --
 *     `->selectRaw()`, `->whereRaw()`, `->orderByRaw()`, `->havingRaw()`, `->groupByRaw()`,
 *     `->fromRaw()` -- is never preceded by `DB::`, so it cannot match. Note also that `raw` is
 *     ABSENT from that alternation, so `DB::raw()` is outside it too.
 *
 * THE INSTRUMENT IS VALIDATED AGAINST THE LIVE CENSUS IN BOTH DIRECTIONS, which is the only reason
 * the blind-spot figures below can be trusted: counting the same pattern over the same enumeration
 * reproduces `['app' => 229, 'packages' => 45]` EXACTLY -- the figures that test asserts. (Its
 * method NAME says "one hundred and sixty eight" and a comment in it says 112; both are stale, and
 * its docblock already records that the name is an identity the CI gate matches on and that "the
 * assertions carry the real figures". So the assertion is the oracle, and this agrees with it.)
 *
 * COMMENTS ARE STRIPPED WITH `token_get_all()`, NOT BY LINE PREFIX. CLAUDE.md records eight
 * occasions when a census was satisfied -- or broken -- by prose about the thing it measures, and
 * this file would be a prime candidate: it is surrounded by comments containing the literal strings
 * it searches for. A token-based strip removes a trailing comment on a live code line too, which a
 * prefix test cannot.
 *
 * WHAT THIS TEST IS AND IS NOT. It is a VISIBILITY ratchet: it counts the sites the existing
 * inventory cannot see, so the number cannot grow unnoticed. It is NOT a tenancy classification --
 * it does not say which of the 113 need a `store_id` predicate, and it must not be read as saying
 * they are safe. Classifying them is the follow-up GAP-253 implies, and it is a real piece of work:
 * `RawQueryTenancyInventoryTest` carries per-table totals (198 and 86) that a classification pass
 * would have to rebuild. Adding only `SaasRevenueFigures`' two rows to that inventory would have
 * been worse than leaving them out, because a total of 2 out of 113 implies the other 111 are
 * accounted for.
 *
 * ON THE TWO STATEMENTS THEMSELVES, the row's own disposition stands and is re-checked here:
 * `plan_orders` and `users` are in neither `scoped_models`, so `StoreScope` short-circuits and adds
 * no predicate for ANY principal -- the reach is identical whether the statement is raw or
 * Eloquent. And the figure is the PLATFORM's own revenue across every tenant, so a tenant predicate
 * would make it wrong. What was missing was the entry, and this is the entry.
 */
class BuilderRawQueryVisibilityTest extends TestCase
{
    /** The live census's own pattern, copied so this test measures what that test measures. */
    private const RAW_ENTRY_POINT_PATTERN = '/\\\\?DB::(table|select|insert|update|delete|statement|unprepared)\s*\(/';

    /** Raw SQL expressed as a method on a query builder. */
    private const BUILDER_RAW_PATTERN = '/->\s*(selectRaw|whereRaw|orWhereRaw|havingRaw|orHavingRaw|orderByRaw|groupByRaw|fromRaw|joinRaw|whereIntegerInRaw)\s*\(/';

    private const DB_RAW_PATTERN = '/\\\\?DB::raw\s*\(/';

    /**
     * @return array<string, array{visible: int, builder: int, dbRaw: int, files: array<string, list<int>>}>
     */
    private function census(): array
    {
        $out = [];

        foreach (['app', 'packages'] as $root) {
            $visible = $builder = $dbRaw = 0;
            $files = [];

            foreach ($this->phpFiles($root) as $relative) {
                $lines = explode("\n", $this->sourceWithoutComments(base_path($relative)));

                foreach ($lines as $index => $line) {
                    $isEntry = (bool) preg_match(self::RAW_ENTRY_POINT_PATTERN, $line);
                    $isBuilder = (bool) preg_match(self::BUILDER_RAW_PATTERN, $line);

                    if ($isEntry) {
                        $visible++;
                    }

                    if ($isBuilder && ! $isEntry) {
                        $builder++;
                        $files[$relative][] = $index + 1;
                    }

                    if (! $isEntry && ! $isBuilder && preg_match(self::DB_RAW_PATTERN, $line)) {
                        $dbRaw++;
                    }
                }
            }

            $out[$root] = ['visible' => $visible, 'builder' => $builder, 'dbRaw' => $dbRaw, 'files' => $files];
        }

        return $out;
    }

    /**
     * Comments replaced by the newlines they spanned, so line numbers survive.
     *
     * `Tests\Support\StripsSourceComments` exists for PHP sources, and this is the same idea done
     * positionally because the line NUMBERS are part of what is reported.
     */
    private function sourceWithoutComments(string $path): string
    {
        $tokens = token_get_all((string) file_get_contents($path));
        $out = '';

        foreach ($tokens as $token) {
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

    /** @return list<string> relative paths, the same enumeration the live census uses */
    private function phpFiles(string $dir): array
    {
        $base = base_path($dir);

        if (! is_dir($base)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');
            }
        }

        sort($files);

        return $files;
    }

    /**
     * THE PREMISE, AND WITHOUT IT EVERY FIGURE BELOW IS UNANCHORED: this file's reading of the
     * census's own pattern reproduces the census's own asserted totals.
     */
    public function test_premise_this_instrument_reproduces_the_live_censuss_asserted_totals(): void
    {
        $census = $this->census();

        $this->assertSame(
            ['app' => 229, 'packages' => 45],
            ['app' => $census['app']['visible'], 'packages' => $census['packages']['visible']],
            'This file counts `DB::` entry points with the same pattern and the same enumeration as '
            . 'RawQueryTenancyInventoryTest::test_the_raw_entry_point_totals_are_the_measured_one_hundred_and_sixty_eight, '
            . 'whose assertion is `[app => 229, packages => 45]`. A disagreement means one of the '
            . 'two moved, and the blind-spot figures below are measured against nothing until it is '
            . 'resolved. Do NOT adjust this to match: find out which census changed.'
        );
    }

    /**
     * THE BLIND SPOT, RATCHETED. 113 sites of raw SQL that no inventory counts.
     */
    public function test_the_builder_raw_blind_spot_is_exactly_this_many_sites(): void
    {
        $census = $this->census();

        $actual = [
            'app builder-raw' => $census['app']['builder'],
            'app DB::raw' => $census['app']['dbRaw'],
            'packages builder-raw' => $census['packages']['builder'],
            'packages DB::raw' => $census['packages']['dbRaw'],
        ];

        $this->assertSame(
            [
                'app builder-raw' => 69,
                'app DB::raw' => 23,
                'packages builder-raw' => 17,
                'packages DB::raw' => 4,
            ],
            $actual,
            'The count of raw-SQL sites INVISIBLE to RawQueryTenancyInventoryTest has moved. That '
            . 'test counts `DB::` facade entry points only, so raw SQL written as a builder method '
            . 'is outside it by construction. If this went UP, a new raw statement was added where '
            . 'no inventory will ever report it -- classify it and move this figure deliberately. '
            . 'If it went DOWN, say which site was removed or rewritten. Measured: '
            . json_encode($actual)
        );

        $this->assertSame(
            113,
            array_sum($actual),
            'the blind spot total; 274 sites are visible, so the inventory reaches about 71% of the '
            . 'raw SQL in this codebase'
        );
    }

    /**
     * GAP-253's OWN TWO STATEMENTS are inside that total, named, so the row can be closed against
     * something specific rather than against a count.
     */
    public function test_the_two_saas_revenue_statements_are_among_the_invisible_sites(): void
    {
        $census = $this->census();
        $file = 'app/Services/SaasRevenueFigures.php';

        $this->assertArrayHasKey(
            $file,
            $census['app']['files'],
            'GAP-253 names two `selectRaw` statements in ' . $file . ' as uncounted. They are no '
            . 'longer found there at all, so either the file moved or the statements were rewritten '
            . '-- re-point this row rather than deleting it.'
        );

        $this->assertCount(
            2,
            $census['app']['files'][$file],
            'GAP-253 is about exactly two statements -- `planOrderGroups()` on PlanOrder::query() '
            . 'and the `User::query()` site in `subscriptions()`. Found: '
            . implode(', ', $census['app']['files'][$file])
        );
    }

    /**
     * AND THE CAUSE, DRIVEN RATHER THAN DESCRIBED: the census's pattern genuinely cannot match a
     * builder-raw line, and genuinely does match a `DB::table()` one.
     *
     * Without this the explanation in the docblock is an assertion about a regex that nobody ran.
     */
    public function test_the_live_censuss_pattern_cannot_match_a_builder_raw_statement(): void
    {
        $builderLine = "        return \$query->selectRaw('sum(price) as amount')->groupBy('x');";
        $facadeLine = "        \$row = DB::table('orders')->where('id', 1)->first();";
        $dbRawLine = "        \$q->select(DB::raw('count(*) as n'));";

        $this->assertSame(
            0,
            preg_match(self::RAW_ENTRY_POINT_PATTERN, $builderLine),
            'the census pattern now matches a builder-raw statement, so the blind spot this file '
            . 'measures has closed -- which is good news, and means these figures must be rebuilt'
        );

        $this->assertSame(
            0,
            preg_match(self::RAW_ENTRY_POINT_PATTERN, $dbRawLine),
            '`raw` is absent from the pattern\'s alternation, so `DB::raw()` is outside it too'
        );

        $this->assertSame(
            1,
            preg_match(self::RAW_ENTRY_POINT_PATTERN, $facadeLine),
            'the census pattern no longer matches a plain `DB::table()` call, so this test is '
            . 'measuring the wrong thing entirely'
        );
    }
}
