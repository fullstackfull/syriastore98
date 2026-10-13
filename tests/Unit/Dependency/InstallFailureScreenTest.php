<?php

namespace Tests\Unit\Dependency;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE GATE OVER THE ONE TOLERATED DEPENDENCY FAILURE.
 *
 * WHY THIS MATTERS MORE THAN IT LOOKS. `composer install` from this repository's lock cannot
 * succeed on any machine: lahirulhr/laravel-payhere pins a dist zipball at a commit that no
 * longer exists upstream (HTTP 404 from a clean ubuntu-latest runner). Because the REQUIRED
 * `tests` job does a real install before it runs anything, that single dead package meant the
 * Laravel Unit and Feature suites had NEVER EXECUTED IN CI. One dead payment package bought a
 * merge past every security, tenancy and commerce test in the project.
 *
 * The `tests-degraded` job fixes that by installing the locked set minus that one package. The
 * danger in doing so is obvious and is the reason this test file exists: the natural
 * implementation is `composer install || true`, which tolerates EVERY dependency failure
 * forever. The day a second package dies, or the lock is edited into an uninstallable state,
 * `|| true` says nothing -- which is the same failure mode as the `continue-on-error` that used
 * to sit on the Feature suite and hid eight failures behind a green tick.
 *
 * So .github/scripts/screen-install-failure.php stands between the failed install and the
 * degraded one, and answers: WHICH packages failed? The job proceeds only if that set is exactly
 * the set declared dead in the workflow. This file feeds that script real composer output shapes
 * with known verdicts.
 *
 * THE TEETH ARE THE NEGATIVE CASES, not the positive one:
 *   * a second failing package must NOT be absorbed into the existing tolerance;
 *   * a non-zero exit with no attributable package -- a resolver failure, a platform
 *     requirement, an empty log -- must NOT pass, because a tolerance that fires on an
 *     unattributable failure tolerates everything;
 *   * the tolerated package NOT failing must not pass either, because then the recorded cause
 *     is wrong and nobody has noticed the upstream came back.
 *
 * @see \Tests\Unit\Dependency\DegradedManifestBuilderTest for the exclusion it authorises
 * @see \Tests\Unit\Dependency\ComposerLockIntegrityGuardTest for the measurement afterwards
 */
final class InstallFailureScreenTest extends TestCase
{
    private const SCREEN = __DIR__ . '/../../../.github/scripts/screen-install-failure.php';

    private const DEAD = 'lahirulhr/laravel-payhere';

    /**
     * The exact dist URL composer.lock pins for the dead package. Reproduced from the measured
     * clean-runner failure, because the URL is the ONLY thing composer names in the
     * TransportException form of the error.
     */
    private const DEAD_DIST = 'https://api.github.com/repos/Visanduma/laravel-payhere/zipball/'
        . '72ce0aa87b495517e3b2d66633e588c86d417e61';

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir() . '/install-screen-' . bin2hex(random_bytes(6));
        mkdir($this->sandbox, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sandbox . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->sandbox)) {
            rmdir($this->sandbox);
        }

        parent::tearDown();
    }

    /**
     * A lock with the dead package pinned at its real dist URL, plus two live packages -- one of
     * which is a yoomoney package, because the project's standing account of this blocker used
     * to claim yoomoney was unfetchable too and it is not. Using it as the "second failure" in
     * the negative case keeps that correction visible.
     */
    private function lock(): string
    {
        $path = $this->sandbox . '/composer.lock';

        file_put_contents($path, (string) json_encode([
            'content-hash' => 'deadbeefdeadbeefdeadbeefdeadbeef',
            'packages'     => [
                [
                    'name'    => self::DEAD,
                    'version' => 'v1.0.4',
                    'source'  => ['url' => 'https://github.com/Visanduma/laravel-payhere.git'],
                    'dist'    => ['url' => self::DEAD_DIST],
                ],
                [
                    'name'    => 'yoomoney/yookassa-sdk-php',
                    'version' => '3.6.0',
                    'dist'    => ['url' => 'https://api.github.com/repos/yoomoney/yookassa-sdk-php/zipball/abc123'],
                ],
                [
                    'name'    => 'laravel/framework',
                    'version' => 'v11.47.0',
                    'dist'    => ['url' => 'https://api.github.com/repos/laravel/framework/zipball/f00ba4'],
                ],
            ],
            'packages-dev' => [
                [
                    'name'    => 'phpunit/phpunit',
                    'version' => '11.4.4',
                    'dist'    => ['url' => 'https://api.github.com/repos/sebastianbergmann/phpunit/zipball/aaa111'],
                ],
            ],
        ], JSON_PRETTY_PRINT));

        return $path;
    }

    private function log(string $contents): string
    {
        $path = $this->sandbox . '/install.log';
        file_put_contents($path, $contents);

        return $path;
    }

    /** @return array{0:int,1:string} exit code and combined output */
    private function screen(string $logPath, string $tolerated, ?string $lockPath = null): array
    {
        $cmd = sprintf(
            'php %s %s %s %s 2>&1',
            escapeshellarg(self::SCREEN),
            escapeshellarg($logPath),
            escapeshellarg($lockPath ?? $this->lock()),
            escapeshellarg($tolerated)
        );

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);

        return [$code, implode("\n", $output)];
    }

    public function test_the_screen_exists_and_parses(): void
    {
        $this->assertFileExists(self::SCREEN);

        $lint = [];
        $code = 0;
        exec('php -l ' . escapeshellarg(self::SCREEN) . ' 2>&1', $lint, $code);

        $this->assertSame(0, $code, implode("\n", $lint));
    }

    // -----------------------------------------------------------------------------------------
    // THE ONE CASE THAT MAY PASS.
    // -----------------------------------------------------------------------------------------

    /**
     * The measured failure, verbatim from a clean ubuntu-latest runner. If this stops being
     * recognised the degraded job stops running the suites and CI goes back to protecting
     * nothing, so it is pinned as literal text rather than paraphrased.
     */
    public function test_the_measured_payhere_failure_is_recognised_and_tolerated(): void
    {
        [$code, $output] = $this->screen($this->log(
            "Installing dependencies from lock file (including require-dev)\n"
            . "Verifying lock file contents can be installed on current platform.\n"
            . "Package operations: 239 installs, 0 updates, 0 removals\n"
            . "  - Downloading lahirulhr/laravel-payhere (v1.0.4)\n"
            . 'Failed to download lahirulhr/laravel-payhere from dist: "' . self::DEAD_DIST
            . '" could not be downloaded (HTTP/2 404): {"message":"Not Found","status":"404"}' . "\n"
            . "    Now trying to download from source\n"
            . "  - Source fallback is disabled\n"
        ), self::DEAD);

        $this->assertSame(0, $code, "The known, measured blocker must be recognised. Output:\n" . $output);
        $this->assertStringContainsString(self::DEAD, $output);
    }

    /**
     * THE FORM THAT NAMES NO PACKAGE. When composer rethrows the download failure as a
     * TransportException, the block carries only the URL. A screen that matched on package
     * names alone would report "no package identified" and -- correctly, by its own rules --
     * refuse, taking the suites down with it. The URL is resolved through composer.lock, which
     * is also why a renamed or re-sourced package cannot be folded into the tolerance silently.
     */
    public function test_a_transport_exception_naming_only_a_url_is_attributed_through_the_lock(): void
    {
        [$code, $output] = $this->screen($this->log(
            "Installing dependencies from lock file (including require-dev)\n"
            . "\nIn TransportException.php line 110:\n\n"
            . '  The "' . self::DEAD_DIST . "\" file could not be downloaded (HTTP/2 404)\n"
        ), self::DEAD);

        $this->assertSame(0, $code, "A URL-only failure must be attributed via the lock. Output:\n" . $output);
        $this->assertStringContainsString('URL from composer.lock', $output);
        $this->assertStringContainsString(self::DEAD, $output);
    }

    // -----------------------------------------------------------------------------------------
    // THE TEETH.
    // -----------------------------------------------------------------------------------------

    public function test_a_second_dead_package_is_not_absorbed_into_the_tolerance(): void
    {
        [$code, $output] = $this->screen($this->log(
            'Failed to download ' . self::DEAD . ' from dist: "' . self::DEAD_DIST
            . "\" could not be downloaded (HTTP/2 404)\n"
            . 'Failed to download yoomoney/yookassa-sdk-php from dist: '
            . "\"https://api.github.com/repos/yoomoney/yookassa-sdk-php/zipball/abc123\" "
            . "could not be downloaded (HTTP/2 404)\n"
        ), self::DEAD);

        $this->assertSame(
            1,
            $code,
            "A SECOND failing package must redden the screen. If this ever passes, one dead "
            . "upstream has bought amnesty for every future one. Output:\n" . $output
        );
        $this->assertStringContainsString('UNDECLARED DEPENDENCY FAILURE: yoomoney/yookassa-sdk-php', $output);
    }

    public function test_a_second_dead_package_is_caught_even_when_only_its_url_appears(): void
    {
        [$code, $output] = $this->screen($this->log(
            'Failed to download ' . self::DEAD . ' from dist: "' . self::DEAD_DIST
            . "\" could not be downloaded (HTTP/2 404)\n"
            . "\nIn TransportException.php line 110:\n"
            . '  The "https://api.github.com/repos/laravel/framework/zipball/f00ba4" file could '
            . "not be downloaded (HTTP/2 404)\n"
        ), self::DEAD);

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('UNDECLARED DEPENDENCY FAILURE: laravel/framework', $output);
    }

    public function test_a_dev_dependency_failure_is_not_tolerated_either(): void
    {
        [$code, $output] = $this->screen($this->log(
            'Failed to download ' . self::DEAD . ' from dist: "x" could not be downloaded (HTTP/2 404)' . "\n"
            . 'Failed to download phpunit/phpunit from dist: "y" could not be downloaded (HTTP/2 404)' . "\n"
        ), self::DEAD);

        $this->assertSame(
            1,
            $code,
            "packages-dev is where the TEST RUNNER lives. A silently absent phpunit would make "
            . "every suite below unreachable, so it is read from the lock too. Output:\n" . $output
        );
        $this->assertStringContainsString('UNDECLARED DEPENDENCY FAILURE: phpunit/phpunit', $output);
    }

    /**
     * @param string $log a non-zero composer exit whose cause is NOT a named package
     */
    #[DataProvider('unattributableFailures')]
    public function test_a_failure_that_names_no_locked_package_is_refused(string $label, string $log): void
    {
        [$code, $output] = $this->screen($this->log($log), self::DEAD);

        $this->assertSame(
            1,
            $code,
            "{$label} must NOT be tolerated: a tolerance that fires on an unattributable failure "
            . "tolerates everything, which is exactly what `composer install || true` does. "
            . "Output:\n" . $output
        );
        $this->assertStringContainsString('NO locked package could be identified', $output);
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function unattributableFailures(): array
    {
        return [
            'a resolver failure' => [
                'A resolver failure',
                "Installing dependencies from lock file (including require-dev)\n"
                . "Your lock file does not contain a compatible set of packages. Please run composer update.\n"
                . "  Problem 1\n"
                . "    - Root composer.json requires php ^8.4 but your php version (8.3.6) does not satisfy that requirement.\n",
            ],
            'an unmet platform requirement' => [
                'An unmet platform requirement',
                "Your requirements could not be resolved to an installable set of packages.\n"
                . "  - ext-sodium is missing from your system.\n",
            ],
            'a disk fault' => [
                'A disk fault',
                "Installing dependencies from lock file\n"
                . "fwrite(): write of 8192 bytes failed with errno=28 No space left on device\n",
            ],
        ];
    }

    public function test_an_empty_log_is_refused(): void
    {
        [$code, $output] = $this->screen($this->log(''), self::DEAD);

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('is empty', $output);
    }

    public function test_a_missing_log_is_refused(): void
    {
        [$code, $output] = $this->screen($this->sandbox . '/does-not-exist.log', self::DEAD);

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('not found', $output);
    }

    /**
     * GOOD NEWS IS STILL A FAILED PREMISE. If the install broke on something else while the
     * tolerated package downloaded fine, the recorded cause is wrong -- either the upstream
     * returned (delete the tolerance) or the failure moved. Passing here would let the degraded
     * job exclude a package that is perfectly fetchable while the real problem goes unexamined.
     */
    public function test_the_tolerated_package_not_failing_is_refused(): void
    {
        [$code, $output] = $this->screen($this->log(
            'Failed to download laravel/framework from dist: "https://api.github.com/repos/laravel/'
            . "framework/zipball/f00ba4\" could not be downloaded (HTTP/2 500)\n"
        ), self::DEAD);

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('UNDECLARED DEPENDENCY FAILURE: laravel/framework', $output);
    }

    public function test_the_tolerated_package_absent_from_the_failure_set_is_named_as_such(): void
    {
        // phpunit/phpunit fails; the tolerated list names BOTH payhere (which did not fail) and
        // nothing else, so the screen must report both halves of the mismatch.
        [$code, $output] = $this->screen($this->log(
            "Failed to download phpunit/phpunit from dist: \"y\" could not be downloaded (HTTP/2 404)\n"
        ), self::DEAD);

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('TOLERATED PACKAGE DID NOT FAIL: ' . self::DEAD, $output);
    }

    public function test_an_empty_tolerated_set_is_refused(): void
    {
        [$code, $output] = $this->screen($this->log('Failed to download x/y from dist'), '');

        $this->assertSame(
            1,
            $code,
            "With nothing declared there is nothing to permit, and the caller should let the "
            . "install failure stand. Output:\n" . $output
        );
    }

    public function test_a_missing_lock_is_refused_rather_than_degrading_to_name_matching(): void
    {
        [$code, $output] = $this->screen(
            $this->log('Failed to download ' . self::DEAD . ' from dist: "x" could not be downloaded'),
            self::DEAD,
            $this->sandbox . '/no-such.lock'
        );

        $this->assertSame(
            1,
            $code,
            "Without the lock, a URL-only TransportException cannot be attributed to any package, "
            . "so the screen would pass or fail for reasons unrelated to the truth. Output:\n" . $output
        );
        $this->assertStringContainsString('composer.lock not found', $output);
    }

    /**
     * ORDINARY PROGRESS CHATTER IS NOT A FAILURE. `- Downloading x/y (v1.0)` appears for all 239
     * packages on every run. If those lines were read as failures the screen would implicate the
     * entire lock and refuse every time, and the obvious "fix" would be to loosen it until it
     * passed.
     */
    public function test_progress_lines_are_not_read_as_failures(): void
    {
        [$code, $output] = $this->screen($this->log(
            "  - Downloading laravel/framework (v11.47.0)\n"
            . "  - Downloading yoomoney/yookassa-sdk-php (3.6.0)\n"
            . "  - Installing phpunit/phpunit (11.4.4)\n"
            . 'Failed to download ' . self::DEAD . ' from dist: "' . self::DEAD_DIST
            . "\" could not be downloaded (HTTP/2 404)\n"
        ), self::DEAD);

        $this->assertSame(0, $code, "Output:\n" . $output);
        $this->assertStringNotContainsString('laravel/framework', explode('The failure lines', $output)[0]);
    }
}
