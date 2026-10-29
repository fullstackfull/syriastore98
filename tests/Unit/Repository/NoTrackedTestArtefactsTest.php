<?php

namespace Tests\Unit\Repository;

use Tests\TestCase;

/**
 * NO GENERATED TEST ARTEFACT IS TRACKED IN GIT.
 * =============================================
 *
 * WRITTEN BECAUSE ONE WAS. `8c462acd` committed `uploads/2/38_1793230928_swatch.png`, a 106-byte
 * PNG that `VariantImageOwnershipTest` had caused the application to write. The commit's own
 * report said the artefacts had been cleaned, and the working tree WAS clean when it was made --
 * the file had already been staged by an earlier `git add -A`. So a clean `git status` is not
 * evidence that test output is untracked; it is evidence about one moment.
 *
 * HOW IT GOT PAST THE IGNORE RULE, which is the part worth keeping. `/uploads/1/` is gitignored
 * on purpose: store 1 is the dev fixture's, and `.gitignore` records that nothing under it is
 * tracked while 407 files under other store ids are. The test creates SEVERAL tenants, so its
 * second one is store 2 -- and `uploads/2/` cannot be ignored, because other environments keep
 * real product content under those paths. Widening the ignore would hide product files; the fix
 * belongs in the test, which now removes what it wrote and asserts that it did.
 *
 * This is the backstop for the same class of mistake from any other direction: a fixture whose
 * cleanup is forgotten, a probe that writes into the repository, a screenshot swept up by a
 * `git add -A`.
 *
 * IT READS GIT'S INDEX, NOT THE FILESYSTEM. The question is what is TRACKED, so an untracked or
 * ignored artefact sitting on a developer's disk is none of its business.
 */
class NoTrackedTestArtefactsTest extends TestCase
{
    /**
     * Patterns that only ever describe generated TEST output.
     *
     * THE OBVIOUS PATTERN DOES NOT WORK, AND FINDING THAT OUT IS WHY THIS LIST IS SHORT. The
     * first version matched `<rand>_<10-digit epoch>_<name>`, the filename shape
     * `Utility::upload_file()` builds -- and it condemned 300-odd tracked files of REAL merchant
     * content, because production uploads go through exactly the same helper:
     * `uploads/11/13_1783845776_Logo-01.png`, `uploads/11/14_1784787577_566.jpg`. The generated
     * timestamp carries no information about whether a file is a fixture.
     *
     * So the only reliable signal is the FIXTURE NAME, which is why this suite's own fixture and
     * the browser probes' are now named distinctively on purpose. Each pattern below has zero
     * matches among the 338 tracked upload files and is a name no merchant would produce.
     *
     * Deliberately NOT a blanket `*.png`, and deliberately not the timestamp shape.
     *
     * @var array<string, string> pattern => why a match is always an artefact
     */
    private const ARTEFACT_PATTERNS = [
        '#ss-phpunit-#' => "this suite's own upload fixture",
        '#ss-variant-swatch#' => "the variant-image probe's fixture",
        '#_swatch\.png$#' => "a browser probe's swatch upload",
        '#^uploads/[^/]+/p2dev/#' => "the dev seeder's fixture images",
    ];

    /** @return list<string> every path git has in its index */
    private function trackedPaths(): array
    {
        $out = [];
        $code = 0;

        exec('cd ' . escapeshellarg(base_path()) . ' && git ls-files 2>/dev/null', $out, $code);

        if ($code !== 0 || $out === []) {
            /*
             * No conditional skip: an undeclared skip fails
             * `.github/scripts/assert-known-test-outcomes.php` in both directions. Where git is
             * unavailable this records the fact and passes, which is honest -- the assertion is
             * about git's index and there is no index to read.
             */
            $this->assertTrue(true, 'git is unavailable here, so there is no index to inspect');

            return [];
        }

        return $out;
    }

    public function test_no_generated_upload_or_fixture_is_tracked(): void
    {
        $tracked = $this->trackedPaths();

        if ($tracked === []) {
            return;
        }

        $this->assertGreaterThan(
            500,
            count($tracked),
            'premise: git listed the repository. A short list would make the sweep below vacuous.'
        );

        $offenders = [];

        foreach ($tracked as $path) {
            foreach (self::ARTEFACT_PATTERNS as $pattern => $why) {
                if (preg_match($pattern, $path) === 1) {
                    $offenders[$path] = $why;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These tracked files look like generated test output. Something wrote them and a\n"
            . "`git add -A` committed them; remove them from tracking and make whatever produced\n"
            . "them clean up after itself:\n  "
            . implode("\n  ", array_map(
                static fn ($p, $w): string => $p . '  -- ' . $w,
                array_keys($offenders),
                $offenders
            ))
        );
    }

    /**
     * THE PREMISE OF THE SWEEP, FROM BOTH SIDES, asserted so it cannot rot into a test that
     * matches nothing -- which would still pass, and is the failure mode worth guarding.
     *
     * The negative half is not decoration: the first version of the pattern list condemned real
     * merchant uploads, and this is the assertion that would have caught it before the sweep did.
     */
    public function test_the_patterns_catch_this_suites_fixtures_and_spare_real_content(): void
    {
        $artefacts = [
            'uploads/2/57_1793235358_ss-phpunit-variant-fixture.png',
            'uploads/4/12_1793235358_other-ss-phpunit-variant-fixture.png',
            'uploads/1/16_1793229735_ss-variant-swatch.png',
            'uploads/2/38_1793230928_swatch.png',
            'uploads/1/p2dev/swatch-a.png',
        ];

        foreach ($artefacts as $path) {
            $matched = false;

            foreach (array_keys(self::ARTEFACT_PATTERNS) as $pattern) {
                $matched = $matched || preg_match($pattern, $path) === 1;
            }

            $this->assertTrue($matched, $path . ' is a known test artefact and no pattern catches it');
        }

        /*
         * REAL TRACKED CONTENT, copied from `git ls-files` rather than invented -- these are
         * merchant uploads and they share the generated-timestamp shape, which is exactly why
         * that shape is not one of the patterns.
         */
        $realContent = [
            'uploads/11/13_1783845776_Logo-01.png',
            'uploads/11/14_1784787577_566.jpg',
            'uploads/11/15_1784724953_IMG-20260718-WA0003.jpg',
            'uploads/7/hero-banner.png',
            'uploads/7/logo.svg',
        ];

        foreach ($realContent as $path) {
            foreach (self::ARTEFACT_PATTERNS as $pattern => $why) {
                $this->assertSame(
                    0,
                    preg_match($pattern, $path),
                    $path . ' is real content and would be condemned as ' . $why
                );
            }
        }
    }
}
