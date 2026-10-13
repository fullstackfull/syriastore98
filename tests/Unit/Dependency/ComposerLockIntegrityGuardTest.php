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
}
