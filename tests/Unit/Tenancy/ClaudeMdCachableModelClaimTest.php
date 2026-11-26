<?php

namespace Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;

/**
 * CLAUDE.md's `Cachable` CENSUS IS RE-MEASURED EVERY RUN, IN BOTH DIRECTIONS.
 *
 * That paragraph tells every reader to **assume cached** and to trust its list of exceptions, and
 * the whole point of it is that a raw write (`DB::table('x')->update()`) leaves Eloquent reads —
 * route-model binding included — serving the pre-write row, which under `CACHE_STORE=array` lives
 * for the rest of the test. CLAUDE.md records three measured false readings from exactly that.
 *
 * SO A WRONG EXCEPTION LIST IS NOT A TYPO, IT IS A TRAP. A reader who believes a model is cached
 * when it is not will add a `flushCache()` that does nothing; a reader who believes it is NOT
 * cached when it is will skip one and get a stale row. The second is the dangerous direction and
 * it reads as a passing test.
 *
 * MEASURED 2026-11-26: the paragraph said **79 of 95** with **16** exceptions. The tree holds
 * **106** model files, **78** using the trait and **28** not — wrong by 11 files and 12
 * exceptions, and every one of the 12 undocumented exceptions is a model a reader would have
 * assumed cached: the marketing, segment, theme-versioning and analytics-schedule models.
 * Nothing asserted it, so the Unit suite was green with the numbers wrong.
 *
 * THE TRAIT IS DETECTED IN THE CLASS BODY, NOT BY THE IMPORT. `use GeneaLabs\...\Cachable;` at
 * the top of a file is an import and proves nothing; the trait is only in effect when the class
 * body says `use Cachable;`. Splitting on the first `{` is what distinguishes them, and a census
 * keyed on the import would report several models as cached that are not.
 */
class ClaudeMdCachableModelClaimTest extends TestCase
{
    private const CLAIM = 'CLAUDE.md';

    /** @return array{0: list<string>, 1: list<string>} [cachable, notCachable] */
    private function census(): array
    {
        $dir = dirname(__DIR__, 3) . '/app/Models';
        $cachable = $plain = [];

        foreach (glob($dir . '/*.php') ?: [] as $path) {
            $source = (string) file_get_contents($path);
            $body = str_contains($source, '{') ? substr($source, strpos($source, '{') + 1) : $source;
            $name = basename($path, '.php');

            if (preg_match('/^\s*use\s+[^;]*\bCachable\b/m', $body) === 1) {
                $cachable[] = $name;
            } else {
                $plain[] = $name;
            }
        }

        sort($cachable);
        sort($plain);

        return [$cachable, $plain];
    }

    private function claim(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . self::CLAIM);
    }

    public function test_the_premise_that_this_census_reads_the_model_directory(): void
    {
        [$cachable, $plain] = $this->census();

        $this->assertGreaterThan(
            80,
            count($cachable) + count($plain),
            'the census found almost no models, so every assertion below would be vacuous. The '
            . 'directory, not the codebase, is the likely cause.'
        );
        $this->assertNotSame([], $cachable, 'premise: some models really do use the trait');
        $this->assertNotSame([], $plain, 'premise: some models really do not');
    }

    public function test_claude_md_states_the_measured_totals(): void
    {
        [$cachable, $plain] = $this->census();
        $claim = $this->claim();

        $this->assertStringContainsString(
            sprintf('%d of the %d models', count($cachable), count($cachable) + count($plain)),
            $claim,
            sprintf(
                'CLAUDE.md no longer states the measured totals. The tree holds %d model files, '
                . '%d using `Cachable` and %d not. Re-derive the sentence rather than adjusting '
                . 'it by the difference — that is how it drifted to "79 of 95" while the tree '
                . 'held 106.',
                count($cachable) + count($plain),
                count($cachable),
                count($plain)
            )
        );
    }

    public function test_claude_md_names_every_model_that_is_not_cachable(): void
    {
        [, $plain] = $this->census();
        $claim = $this->claim();

        /* The paragraph compresses families with brace notation -- `Store{Device,Notification}`.
         * EXPAND THE CLAIM, do not probe it. An earlier draft of this test carried a hardcoded
         * family list (`['Store', 'DeliveryBoy', 'Zid', 'Marketing', 'Theme']`) and substring
         * probes; `SupportAccess{Approval,Grant}` was then correctly written into CLAUDE.md and
         * this test still reported both models missing, because a new family needs the list
         * edited. That is CLAUDE.md's own rule broken inside its own ratchet: a hardcoded list of
         * the shapes you thought of is always short by the next one. Expanding every brace group
         * found in the claim needs no maintenance and matches full names EXACTLY, so a chance
         * substring cannot satisfy it either. */
        $named = [];

        foreach (preg_split('/`/', $claim) as $token) {
            if (preg_match('/^([A-Za-z]+)\{([A-Za-z,]+)\}$/', $token, $m)) {
                foreach (explode(',', $m[2]) as $tail) {
                    $named[] = $m[1] . $tail;
                }
                continue;
            }
            if (preg_match('/^[A-Z][A-Za-z]*$/', $token)) {
                $named[] = $token;
            }
        }

        $missing = [];

        foreach ($plain as $model) {
            if (! in_array($model, $named, true)) {
                $missing[] = $model;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "CLAUDE.md tells readers to ASSUME CACHED and trust its exception list, so a model "
            . "that is not `Cachable` and is not named there is a model somebody will flush that "
            . "needs no flushing — or worse, will trust a stale read from. Add these:\n  "
            . implode("\n  ", $missing)
        );
    }

    public function test_claude_md_names_no_model_that_is_actually_cachable(): void
    {
        [$cachable] = $this->census();
        $claim = $this->claim();

        /* The other direction, which is the dangerous one. A model listed as an exception but
         * actually cached invites a reader to skip `flushCache()` after a raw write and trust a
         * row the cache is still serving — CLAUDE.md's three measured false readings. */
        $exceptionsBlock = '';
        /* BOUNDED TO THE LIST, AND THE FIRST VERSION WAS NOT. It captured to the end of the
         * paragraph and swept up the very next sentence, which says
         * "Write through Eloquent (it flushes) or `(new User)->flushCache()`" — so it reported
         * `User` as wrongly listed when `User` is not in the list at all. That is this project's
         * recurring census failure in miniature: a pattern scoped wider than the thing it
         * measures. The list ends at the sentence that follows it, so the capture stops there. */
        if (preg_match('/assume cached; the \*\*\d+\*\* exceptions are (.*?)\. \*\*This read/s', $claim, $m) === 1) {
            $exceptionsBlock = $m[1];
        }

        $this->assertNotSame('', $exceptionsBlock, 'premise: the exception list is still findable');

        $wronglyListed = array_values(array_filter(
            $cachable,
            fn ($model) => str_contains($exceptionsBlock, '`' . $model . '`')
        ));

        $this->assertSame(
            [],
            $wronglyListed,
            "CLAUDE.md lists these as NOT `Cachable`, and they are. That is the direction that "
            . "costs a false reading: a reader skips a flush and trusts a row the cache is still "
            . "serving.\n  " . implode("\n  ", $wronglyListed)
        );
    }
}
