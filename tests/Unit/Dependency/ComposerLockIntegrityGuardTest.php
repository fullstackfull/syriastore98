<?php

namespace Tests\Unit\Dependency;

use PHPUnit\Framework\TestCase;

/**
 * THE GATE OVER THE PROJECT'S LARGEST VERIFICATION HOLE.
 *
 * Every test number this project has reported -- including the ones in this file's own
 * suite -- was produced by a vendor/ tree that does not match composer.lock. Measured on
 * p1e/agent5-qa at de8646b7 with .github/scripts/guard-composer-lock-integrity.sh:
 *
 *     239 locked, 235 installed, 85 at the locked version, 148 at a DIFFERENT version,
 *       6 locked but not installed, 2 installed but absent from the lock,
 *       4 crossing a MAJOR version.
 *
 * The framework under test is Laravel 11.57.0; the lock pins 11.47.0. The runner is
 * phpunit 11.5.57; the lock pins 11.4.4.
 *
 * WHAT THIS TEST DOES, AND WHY IT IS NOT THE OBVIOUS TEST. The obvious test would assert
 * that vendor/ matches composer.lock. That test would be RED here and in every agent
 * worktree, because vendor/ is a shared hardlinked tree that must not be reinstalled --
 * and a permanently red test teaches people to ignore it. Worse, it would be red for a
 * reason no one in this container can fix.
 *
 * So this test covers THE GUARD instead of the tree. It feeds the guard synthetic
 * lock/installed pairs whose correct verdict is known, and asserts the verdict. That is
 * what makes the guard trustworthy when CI points it at a vendor/ tree that WAS just
 * installed from the lock, which is the one place a mismatch is a real defect and the one
 * place it runs blocking.
 *
 * THE TEETH ARE EXPLICIT. test_a_single_patch_release_of_drift_is_detected uses a pair
 * differing by exactly one patch release on one package (1.2.3 vs 1.2.4). If anyone ever
 * loosens the comparison to a fuzzy or semver-range match, that test fails. A fuzzy match
 * is precisely what would have hidden the four major-version crossings above, because
 * three of them are satisfied by the requirers' own constraints.
 *
 * @see \Tests\Unit\Database\GateFSchemaContainmentTest for the other guard covered this way.
 */
final class ComposerLockIntegrityGuardTest extends TestCase
{
    private const GUARD = __DIR__ . '/../../../.github/scripts/guard-composer-lock-integrity.sh';

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir() . '/lock-integrity-' . bin2hex(random_bytes(6));
        mkdir($this->sandbox . '/vendor/composer', 0777, true);
    }

    protected function tearDown(): void
    {
        // Depth-first unlink. No shell, so a stray path cannot expand into rm -rf.
        foreach (['/vendor/composer/installed.json', '/composer.lock'] as $f) {
            if (is_file($this->sandbox . $f)) {
                unlink($this->sandbox . $f);
            }
        }
        foreach (['/vendor/composer', '/vendor', ''] as $d) {
            if (is_dir($this->sandbox . $d)) {
                rmdir($this->sandbox . $d);
            }
        }
        parent::tearDown();
    }

    /**
     * @param list<array{0:string,1:string}> $locked
     * @param list<array{0:string,1:string}> $installed
     */
    private function fixture(array $locked, array $installed, bool $writeInstalled = true): void
    {
        $toPkgs = static fn (array $rows): array => array_map(
            static fn (array $r): array => ['name' => $r[0], 'version' => $r[1]],
            $rows
        );

        file_put_contents(
            $this->sandbox . '/composer.lock',
            json_encode(['packages' => $toPkgs($locked), 'packages-dev' => []])
        );

        if ($writeInstalled) {
            file_put_contents(
                $this->sandbox . '/vendor/composer/installed.json',
                json_encode(['packages' => $toPkgs($installed)])
            );
        }
    }

    /** @return array{0:int,1:string} exit code and combined output */
    private function runGuard(string ...$extra): array
    {
        $cmd = escapeshellarg(self::GUARD) . ' ' . escapeshellarg($this->sandbox);
        foreach ($extra as $e) {
            $cmd .= ' ' . escapeshellarg($e);
        }
        $output = [];
        $code = 0;
        exec($cmd . ' 2>&1', $output, $code);

        return [$code, implode("\n", $output)];
    }

    public function test_the_guard_exists_and_is_executable(): void
    {
        $this->assertFileExists(self::GUARD, 'The lock-integrity guard script is missing.');
        $this->assertTrue(
            is_executable(self::GUARD),
            'guard-composer-lock-integrity.sh is not executable, so the CI step that calls it '
            . 'directly would fail with a permission error rather than a verdict.'
        );
    }

    public function test_an_exact_match_passes(): void
    {
        $this->fixture(
            [['acme/one', '1.2.3'], ['acme/two', 'v4.5.6']],
            [['acme/one', '1.2.3'], ['acme/two', 'v4.5.6']]
        );

        [$code, $out] = $this->runGuard();

        $this->assertSame(0, $code, "A tree matching its lock must pass. Output:\n$out");
        $this->assertStringContainsString('matches composer.lock exactly', $out);
    }

    /**
     * THE TEETH. One package, one patch release apart. If this ever stops failing, the
     * comparison has been loosened and the guard no longer guards anything.
     */
    public function test_a_single_patch_release_of_drift_is_detected(): void
    {
        $this->fixture(
            [['acme/one', '1.2.3'], ['acme/two', 'v4.5.6']],
            [['acme/one', '1.2.4'], ['acme/two', 'v4.5.6']]
        );

        [$code, $out] = $this->runGuard();

        $this->assertSame(
            1,
            $code,
            "A single patch release of drift MUST fail the guard; an exact version match is the "
            . "whole point. Output:\n$out"
        );
        $this->assertStringContainsString('1 package(s) drift from composer.lock', $out);
        $this->assertStringContainsString('does not match composer.lock', $out);
    }

    public function test_a_locked_package_that_is_not_installed_is_detected(): void
    {
        $this->fixture(
            [['acme/one', '1.2.3'], ['acme/absent', '2.0.0']],
            [['acme/one', '1.2.3']]
        );

        [$code, $out] = $this->runGuard();

        $this->assertSame(1, $code, "A locked package missing from vendor/ must fail. Output:\n$out");
        $this->assertStringContainsString('LOCKED BUT NOT INSTALLED', $out);
        $this->assertStringContainsString('acme/absent', $out);
    }

    public function test_an_installed_package_absent_from_the_lock_is_detected(): void
    {
        $this->fixture(
            [['acme/one', '1.2.3']],
            [['acme/one', '1.2.3'], ['acme/smuggled', '0.1.0']]
        );

        [$code, $out] = $this->runGuard();

        $this->assertSame(
            1,
            $code,
            "A package present in vendor/ but absent from the lock must fail: it is code running "
            . "in the application that no lock entry accounts for. Output:\n$out"
        );
        $this->assertStringContainsString('INSTALLED BUT NOT IN THE LOCK', $out);
        $this->assertStringContainsString('acme/smuggled', $out);
    }

    public function test_a_major_version_crossing_is_called_out_by_name(): void
    {
        $this->fixture(
            [['acme/crypto', '3.0.42']],
            [['acme/crypto', '4.0.2']]
        );

        [$code, $out] = $this->runGuard();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('crossing a MAJOR version : 1', $out);
        $this->assertStringContainsString(
            'MAJOR-VERSION CROSSINGS',
            $out,
            'A major-version crossing must be reported as such, not buried in a version count. '
            . 'The real tree carries four of them (phpseclib 3->4, firebase/php-jwt 6->7, '
            . 'hamcrest 2->3, sabberworm/php-css-parser 8->9).'
        );
    }

    /**
     * The 'v' prefix is a composer convention, not a version difference. Treating 'v1.2.3'
     * and '1.2.3' as drift would make the guard cry wolf on roughly half the real lock,
     * and a guard that cries wolf gets a continue-on-error added to it within a week.
     */
    public function test_the_comparison_is_the_literal_version_string_including_any_v_prefix(): void
    {
        $this->fixture([['acme/one', 'v1.2.3']], [['acme/one', '1.2.3']]);

        [$code, $out] = $this->runGuard();

        $this->assertSame(
            1,
            $code,
            "Composer writes the version string it resolved; the guard compares what composer "
            . "wrote on both sides, so a prefix difference is a genuine lock/installed "
            . "disagreement and is reported. Output:\n$out"
        );
    }

    public function test_report_mode_measures_the_drift_but_does_not_fail(): void
    {
        $this->fixture(
            [['acme/one', '1.2.3'], ['acme/two', 'v4.5.6']],
            [['acme/one', '1.2.4'], ['acme/two', 'v4.5.6']]
        );

        [$code, $out] = $this->runGuard('--report');

        $this->assertSame(
            0,
            $code,
            "--report mode exists for worktrees whose shared vendor/ must not be reinstalled. "
            . "It must not fail the build. Output:\n$out"
        );
        $this->assertStringContainsString('at a DIFFERENT version   : 1', $out);
        $this->assertStringContainsString('REPORT MODE', $out);
    }

    public function test_report_mode_still_reports_a_clean_tree_as_clean(): void
    {
        $this->fixture([['acme/one', '1.2.3']], [['acme/one', '1.2.3']]);

        [$code, $out] = $this->runGuard('--report');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('matches composer.lock exactly', $out);
        $this->assertStringNotContainsString(
            'REPORT MODE',
            $out,
            'On a clean tree the guard should report success, not explain why it is being lenient.'
        );
    }

    /**
     * An absent installed.json in enforce mode is the dangerous case: it is what a CI job
     * looks like when `composer install` silently did nothing. Exiting 0 there would hand
     * a green tick to a run with no dependencies at all.
     */
    public function test_a_missing_installed_json_fails_enforce_mode(): void
    {
        $this->fixture([['acme/one', '1.2.3']], [], writeInstalled: false);

        [$code, $out] = $this->runGuard();

        $this->assertSame(1, $code, "Enforce mode must fail when vendor/ was never produced. Output:\n$out");
        $this->assertStringContainsString('installed.json not found', $out);
    }

    public function test_a_missing_composer_lock_fails_in_both_modes(): void
    {
        // No fixture at all: neither file exists.
        [$enforceCode] = $this->runGuard();
        [$reportCode]  = $this->runGuard('--report');

        $this->assertSame(1, $enforceCode, 'No composer.lock must fail enforce mode.');
        $this->assertSame(
            1,
            $reportCode,
            'No composer.lock must fail even in report mode: report mode is lenient about a '
            . 'drifting vendor/, not about a missing lock file.'
        );
    }

    // -----------------------------------------------------------------------------------------
    // --allow-missing: THE ONE NARROW TOLERANCE, AND THE FOUR WAYS IT MUST STILL BITE.
    //
    // One locked package's upstream is gone (lahirulhr/laravel-payhere: the pinned zipball 404s
    // from a clean runner), so `composer install` from the lock fails and the REQUIRED test gate
    // had NEVER run a Laravel test. The `tests-degraded` job installs the locked set minus that
    // one entry so the real suites can execute, and then has to measure the tree it produced
    // against the real lock -- which is what --allow-missing is for.
    //
    // A tolerance is the most dangerous thing that can be added to a guard, so the tests below
    // are mostly about what it must NOT absorb. If any of the four "still fails" tests ever goes
    // green, this guard has stopped being a lock-integrity check and become a rubber stamp.
    // -----------------------------------------------------------------------------------------

    public function test_allow_missing_tolerates_exactly_the_named_absent_package(): void
    {
        $this->fixture(
            [['acme/one', '1.2.3'], ['dead/upstream', '2.0.0'], ['acme/two', '4.5.6']],
            [['acme/one', '1.2.3'], ['acme/two', '4.5.6']]
        );

        [$code, $out] = $this->runGuard('--allow-missing=dead/upstream');

        $this->assertSame(0, $code, "Output:\n$out");
        $this->assertStringContainsString('TOLERATED absent', $out);
        $this->assertStringContainsString('dead/upstream', $out);
    }

    /**
     * THE HONESTY CHECK ON THE PASS MESSAGE. A tolerated run must not print "vendor/ matches
     * composer.lock exactly", because it does not: one of the locked packages is not installed.
     * A reader skimming the log is entitled to learn that from the log.
     */
    public function test_a_tolerated_pass_does_not_claim_the_tree_matches_the_lock(): void
    {
        $this->fixture(
            [['acme/one', '1.2.3'], ['dead/upstream', '2.0.0']],
            [['acme/one', '1.2.3']]
        );

        [$code, $out] = $this->runGuard('--allow-missing=dead/upstream');

        $this->assertSame(0, $code, "Output:\n$out");
        $this->assertStringNotContainsString('matches composer.lock exactly', $out);
        $this->assertStringContainsString('This is NOT "vendor/ matches composer.lock"', $out);
    }

    /** STILL FAILS 1 of 4: one dead upstream buys no amnesty for a second. */
    public function test_allow_missing_does_not_tolerate_a_second_absent_package(): void
    {
        $this->fixture(
            [['acme/one', '1.2.3'], ['dead/upstream', '2.0.0'], ['acme/two', '4.5.6']],
            [['acme/one', '1.2.3']]
        );

        [$code, $out] = $this->runGuard('--allow-missing=dead/upstream');

        $this->assertSame(1, $code, "A second missing package must still fail. Output:\n$out");
        $this->assertStringContainsString('acme/two', $out);
    }

    /**
     * STILL FAILS 2 of 4, and the most important one. The tolerance is for ABSENCE only. If the
     * allow-listed package turns up at a DIFFERENT version, that is ordinary drift -- the exact
     * class of defect that hid four major-version crossings on this project -- and the exact
     * version-string match must still catch it.
     */
    public function test_allow_missing_does_not_tolerate_a_version_difference_on_the_named_package(): void
    {
        $this->fixture(
            [['acme/one', '1.2.3'], ['dead/upstream', '2.0.0']],
            [['acme/one', '1.2.3'], ['dead/upstream', '2.0.1']]
        );

        [$code, $out] = $this->runGuard('--allow-missing=dead/upstream');

        $this->assertSame(
            1,
            $code,
            "The tolerance covers absence, NOT a wrong version. A single patch release of drift "
            . "on the tolerated package must still fail. Output:\n$out"
        );
        $this->assertStringContainsString('TOLERANCE UNUSED', $out);
    }

    /** STILL FAILS 3 of 4: nothing may be installed that the lock does not name. */
    public function test_allow_missing_does_not_tolerate_a_package_absent_from_the_lock(): void
    {
        $this->fixture(
            [['acme/one', '1.2.3'], ['dead/upstream', '2.0.0']],
            [['acme/one', '1.2.3'], ['sneaky/extra', '9.9.9']]
        );

        [$code, $out] = $this->runGuard('--allow-missing=dead/upstream');

        $this->assertSame(1, $code, "Output:\n$out");
        $this->assertStringContainsString('sneaky/extra', $out);
    }

    /**
     * STILL FAILS 4 of 4: THE STRICT JOB IS UNAFFECTED. The `tests` job calls this guard with no
     * flag and is what gates the day the upstream returns. The same tree the tolerant mode
     * accepts must still redden the default mode, or adding the tolerance would have quietly
     * weakened the required gate too.
     */
    public function test_the_default_mode_still_fails_on_the_very_package_the_tolerance_names(): void
    {
        $this->fixture(
            [['acme/one', '1.2.3'], ['dead/upstream', '2.0.0']],
            [['acme/one', '1.2.3']]
        );

        [$tolerantCode] = $this->runGuard('--allow-missing=dead/upstream');
        [$strictCode, $strictOut] = $this->runGuard();

        $this->assertSame(0, $tolerantCode);
        $this->assertSame(
            1,
            $strictCode,
            "Default (strict) mode must be untouched by the existence of the tolerance. Output:\n$strictOut"
        );
    }

    /** An absent vendor/ is never tolerable, flag or no flag. */
    public function test_allow_missing_does_not_tolerate_a_missing_installed_json(): void
    {
        $this->fixture([['dead/upstream', '2.0.0']], [], writeInstalled: false);

        [$code, $out] = $this->runGuard('--allow-missing=dead/upstream');

        $this->assertSame(1, $code, "Output:\n$out");
        $this->assertStringContainsString('installed.json not found', $out);
    }

    public function test_an_empty_allow_list_is_refused_rather_than_treated_as_enforce(): void
    {
        $this->fixture([['acme/one', '1.2.3']], [['acme/one', '1.2.3']]);

        [$code, $out] = $this->runGuard('--allow-missing=');

        $this->assertSame(
            1,
            $code,
            "`--allow-missing=` with nothing after it is a mistake, most likely an unset shell "
            . "variable. Treating it as enforce mode would be the friendly behaviour and the wrong "
            . "one: the caller believes it passed a list. Output:\n$out"
        );
    }

    public function test_an_unrecognised_mode_is_refused(): void
    {
        $this->fixture([['acme/one', '1.2.3']], [['acme/one', '1.2.3']]);

        [$code, $out] = $this->runGuard('--allow-everything');

        $this->assertSame(
            1,
            $code,
            "An unknown flag used to fall through to enforce mode, which means a typo in a "
            . "tolerance flag would have produced a silently different verdict. Output:\n$out"
        );
    }
}
