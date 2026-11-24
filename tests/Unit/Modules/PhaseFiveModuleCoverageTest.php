<?php

namespace Tests\Unit\Modules;

use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * WHICH WORKDO MODULES THE SUITE CAN REACH AT ALL, re-measured against the working tree.
 *
 * `docs/development/phase-5/PHASE-05-MODULE-INVENTORY.md` §3 states that six of the twelve modules
 * Phase 05 depends on are never loaded in any test run. That is a claim about the filesystem, so it
 * is asserted rather than written down once and left to rot. Both directions: a module that gains a
 * fixture and a module that loses one each fail this test, which is the signal to update §3.
 *
 * WHY THE CENSUS IS WRITTEN THIS WAY. No WorkDo module loads in a test run unless that test creates
 * the module's `add_on_managers` row, so "is this module reachable" is exactly "does some test
 * create its row". Measuring that went wrong twice before it went right, and both traps are guarded
 * here:
 *
 *   1. A CENSUS THAT ASSUMES ONE CALL SHAPE. The first version looked only for
 *      `DB::table('add_on_managers')->insert(` and answered "2 of 70". `AddOnManager::create`
 *      appears THIRTY-TWO times in the suite and the raw-query form twice, so the Eloquent path is
 *      the rule and the one being measured was the exception. The real answer is 15 of 70 -- a
 *      seven-fold under-report that would have put six falsely-uncovered modules in the document.
 *      `ENABLERS` therefore lists every shape, and `test_the_census_sees_every_enabling_call_shape`
 *      drives one synthetic example of each.
 *
 *   2. PROSE ABOUT THE THING BEING MEASURED. An earlier attempt harvested quoted words near the
 *      table name and reported `POST`, `SUMMARY`, `Base` and `Landing` as modules. This one reads
 *      only the `'module' => '...'` key inside the call's own argument list, and reads it from
 *      comment-stripped source, so a module named in a docblock is not coverage.
 *      `test_a_module_named_only_in_a_comment_is_not_coverage` pins that.
 *
 * WHAT THIS TEST DOES NOT CLAIM. A created row means the module LOADS. It does not mean its
 * handlers are exercised -- `Donation`'s row exists only so an unrelated serialization test can
 * boot, and that test makes one HTTP call. Reach is the inventory's last column and is not asserted
 * here, because an HTTP-call count is a proxy a refactor would move without changing coverage.
 */
final class PhaseFiveModuleCoverageTest extends TestCase
{
    use StripsSourceComments;

    /** The twelve modules Phase 05 depends on. Inventory §3. */
    private const PHASE_FIVE_MODULES = [
        'LynomiaChat', 'Marketing', 'LotteryPrizes', 'Campaigns', 'Donation', 'RewardClubPoint',
        'SpinToWinReward', 'ProductAffiliate', 'PDFVouchers', 'CouponEmail', 'CouponList', 'Subscribe',
    ];

    /**
     * The six the suite cannot reach, exactly as the inventory declares them.
     *
     * DELETE a name here when a fixture is added for it, and correct §3 in the same change.
     */
    private const UNREACHED = [
        'CouponEmail', 'CouponList', 'PDFVouchers', 'ProductAffiliate', 'SpinToWinReward', 'Subscribe',
    ];

    /** Every way a test can create an `add_on_managers` row. Trap 1 above. */
    private const ENABLERS = [
        "add_on_managers'\\s*\\)\\s*->\\s*(?:insert|updateOrInsert)\\s*\\(",
        'AddOnManager::\\s*(?:create|updateOrCreate|firstOrCreate)\\s*\\(',
    ];

    public function test_premise_every_named_phase_05_module_is_a_real_module_directory(): void
    {
        foreach (self::PHASE_FIVE_MODULES as $module) {
            $this->assertDirectoryExists(
                base_path_of("packages/workdo/$module"),
                "PHASE-05-MODULE-INVENTORY.md names `$module`, but no such module directory exists. "
                .'Either the module was renamed upstream or the inventory is wrong; fix both together.'
            );
        }
    }

    public function test_the_census_sees_every_enabling_call_shape(): void
    {
        $cases = [
            'raw insert'      => "DB::table('add_on_managers')->insert(['module' => 'Alpha', 'is_enable' => 1]);",
            'raw upsert'      => "DB::table('add_on_managers')->updateOrInsert(['module' => 'Beta']);",
            'eloquent create' => "AddOnManager::create(['name' => 'G', 'module' => 'Gamma', 'is_enable' => 1]);",
            'updateOrCreate'  => "AddOnManager::updateOrCreate(['module' => 'Delta'], ['is_enable' => 1]);",
            'firstOrCreate'   => "AddOnManager::firstOrCreate(['module' => 'Epsilon']);",
        ];

        foreach ($cases as $label => $source) {
            $found = $this->modulesEnabledIn($source);
            $this->assertCount(1, $found, "the census missed the $label shape entirely");
        }

        // All five at once, so a later shape cannot mask an earlier one.
        $this->assertSame(
            ['Alpha', 'Beta', 'Delta', 'Epsilon', 'Gamma'],
            $this->modulesEnabledIn(implode("\n", $cases)),
            'the census does not find every shape when they appear together'
        );
    }

    public function test_a_module_named_only_in_a_comment_is_not_coverage(): void
    {
        // Trap 2. The name appears, and the table name appears, but no row is created.
        $prose = "// AddOnManager::create(['module' => 'Marketing']) is what a fixture would do.\n"
            ."/* add_on_managers would need a 'module' => 'Campaigns' row here. */\n";

        $this->assertSame(
            [],
            $this->modulesEnabledIn($this->withoutComments($prose)),
            'a module named in a comment was counted as coverage'
        );
    }

    public function test_the_unreached_phase_05_modules_are_exactly_the_six_the_inventory_declares(): void
    {
        $enabled = $this->modulesEnabledAcrossTheSuite();

        // The census must be capable of a positive answer, or an empty result would pass vacuously.
        $this->assertContains(
            'Marketing',
            $enabled,
            'the census found no fixture for Marketing, which MarketingContactOwnershipTest:52 creates. '
            .'The census is broken, so its negative answers prove nothing.'
        );

        $unreached = array_values(array_diff(self::PHASE_FIVE_MODULES, $enabled));
        sort($unreached);

        $declared = self::UNREACHED;
        sort($declared);

        $this->assertSame(
            $declared,
            $unreached,
            "PHASE-05-MODULE-INVENTORY.md §3 is now wrong.\n"
            .'  declared unreached: '.implode(', ', $declared)."\n"
            .'  actually unreached: '.implode(', ', $unreached)."\n"
            .'Gained a fixture? Delete the name from UNREACHED and from §3. Lost one? Say so in §3.'
        );
    }

    public function test_the_inventory_document_names_each_unreached_module(): void
    {
        $doc = (string) file_get_contents(
            base_path_of('docs/development/phase-5/PHASE-05-MODULE-INVENTORY.md')
        );

        foreach (self::UNREACHED as $module) {
            $this->assertStringContainsString(
                $module,
                $doc,
                "UNREACHED lists `$module` but the inventory does not mention it, so the document and "
                .'the assertion have drifted apart.'
            );
        }
    }

    /** Every module enabled by any file under tests/, from comment-stripped source. */
    private function modulesEnabledAcrossTheSuite(): array
    {
        $found = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path_of('tests'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = 'tests'.explode('/tests', $file->getPathname(), 2)[1];

            // This very file names the modules in prose and in synthetic fixtures; excluding it is
            // not a convenience, it is the difference between measuring the suite and measuring the
            // assertion. The comment stripper handles the docblocks; the synthetic cases above are
            // live code and would otherwise register Alpha..Epsilon.
            if (str_ends_with($relative, 'PhaseFiveModuleCoverageTest.php')) {
                continue;
            }

            $found = array_merge($found, $this->modulesEnabledIn($this->sourceWithoutComments($relative)));
        }

        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    /** The pure census: module names whose row this source creates. */
    private function modulesEnabledIn(string $source): array
    {
        $found = [];

        foreach (self::ENABLERS as $opener) {
            if (! preg_match_all('/'.$opener.'/', $source, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($m[0] as [$match, $offset]) {
                $argsFrom = strrpos(substr($source, 0, $offset + strlen($match)), '(');

                if ($argsFrom === false) {
                    continue;
                }

                $depth = 0;
                $end = $argsFrom;

                for ($i = $argsFrom, $len = strlen($source); $i < $len; $i++) {
                    if ($source[$i] === '(') {
                        $depth++;
                    } elseif ($source[$i] === ')') {
                        $depth--;

                        if ($depth === 0) {
                            $end = $i;
                            break;
                        }
                    }
                }

                $args = substr($source, $argsFrom, $end - $argsFrom);

                if (preg_match_all("/'module'\\s*=>\\s*'([A-Za-z]+)'/", $args, $names)) {
                    $found = array_merge($found, $names[1]);
                }
            }
        }

        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    private function withoutComments(string $source): string
    {
        $out = '';

        foreach (token_get_all("<?php\n".$source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}

/** The repository root, without booting the framework: this is a plain PHPUnit TestCase. */
function base_path_of(string $relative): string
{
    return dirname(__DIR__, 3).'/'.$relative;
}
