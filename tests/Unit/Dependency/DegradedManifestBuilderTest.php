<?php

namespace Tests\Unit\Dependency;

use PHPUnit\Framework\TestCase;

/**
 * THE BUILDER THAT MUST NOT TOUCH composer.json OR composer.lock.
 *
 * WHAT IT IS FOR. One locked package's upstream is gone, so `composer install` from
 * composer.lock fails and the REQUIRED test gate has never run a Laravel test. The
 * `tests-degraded` job installs the locked set MINUS that one package so the real suites can
 * execute. .github/scripts/make-degraded-manifest.php derives the side manifest composer is
 * pointed at, and the entire premise of the arrangement is that the COMMITTED manifests are
 * read-only inputs:
 *
 *   * they belong to the dependency agent, so editing them here collides; and
 *   * dropping the package from the real manifests is the FORBIDDEN remedy on its own merits --
 *     five files `use Lahirulhr\PayHere\PayHere`, so a lock edit alone converts a recoverable
 *     upstream outage into a fatal at class load while reading, in the diff, as a resolved
 *     dependency problem.
 *
 * So the properties under test here are not "does it filter JSON". They are:
 *   * composer.json and composer.lock are byte-identical afterwards;
 *   * it REFUSES an output basename that would overwrite them;
 *   * it REFUSES a package the lock does not contain, rather than silently doing nothing while
 *     appearing to work;
 *   * it REFUSES a package another locked package depends on, so the gap between what runs and
 *     what is pinned stays exactly the names given and cannot cascade;
 *   * it removes WHOLE entries and rewrites no version and no constraint.
 *
 * @see \Tests\Unit\Dependency\InstallFailureScreenTest for what authorises the exclusion
 * @see \Tests\Unit\Dependency\ComposerLockIntegrityGuardTest for what measures the result
 */
final class DegradedManifestBuilderTest extends TestCase
{
    private const BUILDER = __DIR__ . '/../../../.github/scripts/make-degraded-manifest.php';

    private const DEAD = 'lahirulhr/laravel-payhere';

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir() . '/degraded-manifest-' . bin2hex(random_bytes(6));
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
     * A miniature of the real pair: the dead package as a leaf in `packages`, a live package
     * that nothing depends on, a package that OTHERS depend on (guzzle, to test the cascade
     * refusal), and a dev package.
     */
    private function fixture(): void
    {
        file_put_contents($this->sandbox . '/composer.json', (string) json_encode([
            'name'    => 'store/syria',
            'type'    => 'project',
            'require' => [
                'php'                     => '^8.2',
                'laravel/framework'       => '^11.0',
                'guzzlehttp/guzzle'       => '^7.8',
                self::DEAD                => '^1.0',
            ],
            'require-dev' => [
                'phpunit/phpunit' => '^11.0',
            ],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ], JSON_PRETTY_PRINT));

        file_put_contents($this->sandbox . '/composer.lock', (string) json_encode([
            'content-hash' => 'deadbeefdeadbeefdeadbeefdeadbeef',
            'packages'     => [
                [
                    'name'    => self::DEAD,
                    'version' => 'v1.0.4',
                    'dist'    => ['url' => 'https://api.github.com/repos/Visanduma/laravel-payhere/zipball/72ce0aa8'],
                    'require' => ['guzzlehttp/guzzle' => '^7.8', 'php' => '^8.1'],
                ],
                [
                    'name'    => 'laravel/framework',
                    'version' => 'v11.47.0',
                    'require' => ['guzzlehttp/guzzle' => '^7.8'],
                ],
                [
                    'name'    => 'guzzlehttp/guzzle',
                    'version' => '7.9.2',
                ],
            ],
            'packages-dev' => [
                [
                    'name'    => 'phpunit/phpunit',
                    'version' => '11.4.4',
                ],
            ],
        ], JSON_PRETTY_PRINT));
    }

    /** @return array{0:int,1:string} exit code and combined output */
    private function build(string $basename, string $packages): array
    {
        $cmd = sprintf(
            'php %s %s %s %s 2>&1',
            escapeshellarg(self::BUILDER),
            escapeshellarg($this->sandbox),
            escapeshellarg($basename),
            escapeshellarg($packages)
        );

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);

        return [$code, implode("\n", $output)];
    }

    private function hashes(): array
    {
        return [
            hash_file('sha256', $this->sandbox . '/composer.json'),
            hash_file('sha256', $this->sandbox . '/composer.lock'),
        ];
    }

    public function test_the_builder_exists_and_parses(): void
    {
        $this->assertFileExists(self::BUILDER);

        $lint = [];
        $code = 0;
        exec('php -l ' . escapeshellarg(self::BUILDER) . ' 2>&1', $lint, $code);

        $this->assertSame(0, $code, implode("\n", $lint));
    }

    // -----------------------------------------------------------------------------------------
    // THE PREMISE.
    // -----------------------------------------------------------------------------------------

    public function test_the_committed_manifests_are_byte_identical_afterwards(): void
    {
        $this->fixture();
        $before = $this->hashes();

        [$code, $output] = $this->build('composer-ci-degraded', self::DEAD);

        $this->assertSame(0, $code, "Output:\n" . $output);
        $this->assertSame(
            $before,
            $this->hashes(),
            'composer.json or composer.lock changed. The whole point of a DERIVED manifest is '
            . 'that the committed pair is never edited by a build; if this fails, the job is '
            . 'making an unreviewed dependency change on a runner.'
        );
    }

    public function test_an_output_basename_of_composer_is_refused(): void
    {
        $this->fixture();
        $before = $this->hashes();

        [$code, $output] = $this->build('composer', self::DEAD);

        $this->assertSame(
            1,
            $code,
            "Writing to the basename 'composer' would overwrite composer.json and composer.lock. "
            . "A typo, or someone 'simplifying' the workflow, must not be able to do that. "
            . "Output:\n" . $output
        );
        $this->assertSame($before, $this->hashes(), 'The refusal still wrote to the committed pair.');
    }

    /** A basename with a path separator could escape the repository root entirely. */
    public function test_a_basename_containing_a_path_separator_is_refused(): void
    {
        $this->fixture();

        foreach (['../evil', 'sub/dir', 'a\\b'] as $basename) {
            [$code, $output] = $this->build($basename, self::DEAD);
            $this->assertSame(1, $code, "Basename '{$basename}' must be refused. Output:\n" . $output);
        }
    }

    // -----------------------------------------------------------------------------------------
    // WHAT THE DERIVED PAIR MUST CONTAIN.
    // -----------------------------------------------------------------------------------------

    public function test_exactly_the_named_package_is_removed_from_the_lock(): void
    {
        $this->fixture();

        [$code, $output] = $this->build('out', self::DEAD);
        $this->assertSame(0, $code, "Output:\n" . $output);

        $original = json_decode((string) file_get_contents($this->sandbox . '/composer.lock'), true);
        $derived  = json_decode((string) file_get_contents($this->sandbox . '/out.lock'), true);

        $names = static fn (array $lock): array => array_map(
            static fn (array $p): string => $p['name'],
            array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? [])
        );

        $this->assertSame(
            [self::DEAD],
            array_values(array_diff($names($original), $names($derived))),
            'The derived lock must differ from the committed lock by exactly the named entry.'
        );
        $this->assertSame(
            [],
            array_values(array_diff($names($derived), $names($original))),
            'The derived lock must never ADD a package.'
        );
    }

    /**
     * NO VERSION MAY BE REWRITTEN. The derived lock is the committed lock minus whole entries;
     * it is not a re-resolution. If anything here ever "upgrades to a version that works", the
     * suite would be measured against a dependency set nobody chose -- which is the exact defect
     * guard-composer-lock-integrity.sh exists to catch.
     */
    public function test_no_surviving_entry_is_altered_in_any_way(): void
    {
        $this->fixture();

        [$code] = $this->build('out', self::DEAD);
        $this->assertSame(0, $code);

        $original = json_decode((string) file_get_contents($this->sandbox . '/composer.lock'), true);
        $derived  = json_decode((string) file_get_contents($this->sandbox . '/out.lock'), true);

        $byName = static function (array $lock): array {
            $out = [];
            foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $p) {
                $out[$p['name']] = $p;
            }

            return $out;
        };

        $originalByName = $byName($original);

        foreach ($byName($derived) as $name => $entry) {
            $this->assertSame(
                $originalByName[$name],
                $entry,
                "Entry {$name} was MODIFIED. Only whole-entry removal is permitted."
            );
        }

        $this->assertSame(
            $original['content-hash'],
            $derived['content-hash'],
            'The lock content-hash must be carried over untouched; recomputing it would make the '
            . 'derived lock look freshly resolved when it is not.'
        );
    }

    /**
     * The root require must go too. `composer install` solves the locked repository against the
     * root requirements even when it is not updating, so a derived lock without the package but a
     * derived json that still requires it fails with "Your lock file does not contain a
     * compatible set of packages" -- and the degraded job would be dead for a second reason.
     */
    public function test_the_root_requirement_is_dropped_and_nothing_else_in_the_json_changes(): void
    {
        $this->fixture();

        [$code, $output] = $this->build('out', self::DEAD);
        $this->assertSame(0, $code, "Output:\n" . $output);

        $original = json_decode((string) file_get_contents($this->sandbox . '/composer.json'), true);
        $derived  = json_decode((string) file_get_contents($this->sandbox . '/out.json'), true);

        $this->assertArrayNotHasKey(self::DEAD, $derived['require']);
        $this->assertSame(
            array_diff_key($original['require'], [self::DEAD => true]),
            $derived['require'],
            'Only the named requirement may be dropped; no other constraint may move.'
        );

        unset($original['require'], $derived['require']);
        $this->assertSame(
            $original,
            $derived,
            'Everything else in composer.json -- autoload paths above all -- must be carried over '
            . 'verbatim, or the derived install produces a differently wired application.'
        );
    }

    // -----------------------------------------------------------------------------------------
    // THE REFUSALS.
    // -----------------------------------------------------------------------------------------

    public function test_a_package_absent_from_the_lock_is_refused(): void
    {
        $this->fixture();

        [$code, $output] = $this->build('out', 'nope/not-locked');

        $this->assertSame(
            1,
            $code,
            "A stale or misspelled exclusion must not be a silent no-op: the real package would "
            . "be installed while the workflow claimed it was excluded. Output:\n" . $output
        );
        $this->assertStringContainsString('NOT IN THE LOCK: nope/not-locked', $output);
        $this->assertFileDoesNotExist($this->sandbox . '/out.lock', 'A refusal must write nothing.');
    }

    /**
     * THE CASCADE REFUSAL. guzzle is required by laravel/framework in the fixture. Excluding it
     * would not produce "the locked set minus one entry" -- it would produce a set where a
     * surviving package's own requirements are unmet, so the measured gap between what ran and
     * what is pinned would be larger than the names given and the suites could fail for reasons
     * nobody declared.
     */
    public function test_a_package_another_locked_package_requires_is_refused(): void
    {
        $this->fixture();

        [$code, $output] = $this->build('out', 'guzzlehttp/guzzle');

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('STILL REQUIRED BY A LOCKED PACKAGE', $output);
        $this->assertStringContainsString('laravel/framework requires guzzlehttp/guzzle', $output);
        $this->assertFileDoesNotExist($this->sandbox . '/out.lock');
    }

    /**
     * The dead package itself REQUIRES guzzle, and that must not block its own exclusion -- only
     * being required BY a surviving package does. Without this the whole mechanism would refuse
     * to exclude anything with dependencies, which is almost everything.
     */
    public function test_the_excluded_packages_own_requirements_do_not_block_its_exclusion(): void
    {
        $this->fixture();

        [$code, $output] = $this->build('out', self::DEAD);

        $this->assertSame(
            0,
            $code,
            "The dead package requires guzzle. That is irrelevant: it is going away, so its own "
            . "requirements go with it. Output:\n" . $output
        );
    }

    public function test_an_empty_package_list_is_refused(): void
    {
        $this->fixture();

        [$code, $output] = $this->build('out', '');

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertFileDoesNotExist($this->sandbox . '/out.lock');
    }

    public function test_the_output_states_plainly_that_this_is_not_a_fix(): void
    {
        $this->fixture();

        [$code, $output] = $this->build('out', self::DEAD);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('NOT A DEPENDENCY FIX', $output);
        $this->assertStringContainsString('WERE NOT MODIFIED', $output);
        $this->assertStringContainsString('must never be reported as, a fix', $output);
    }

    // -----------------------------------------------------------------------------------------
    // AGAINST THE REAL COMMITTED PAIR -- read-only, written into the sandbox.
    // -----------------------------------------------------------------------------------------

    /**
     * The fixture above proves the logic; this proves it against the actual 239-entry lock,
     * which is where the one real exclusion has to work. The committed files are COPIED into the
     * sandbox first, so this test cannot write anything into the repository even if the builder
     * had a defect.
     */
    public function test_the_real_committed_pair_yields_exactly_one_fewer_locked_entry(): void
    {
        $repo = __DIR__ . '/../../..';

        copy($repo . '/composer.json', $this->sandbox . '/composer.json');
        copy($repo . '/composer.lock', $this->sandbox . '/composer.lock');

        $before = $this->hashes();
        $lock   = json_decode((string) file_get_contents($this->sandbox . '/composer.lock'), true);
        $total  = count($lock['packages'] ?? []) + count($lock['packages-dev'] ?? []);

        [$code, $output] = $this->build('composer-ci-degraded', self::DEAD);

        $this->assertSame(
            0,
            $code,
            "The one exclusion the pipeline actually performs must work against the real lock. "
            . "If this fails because " . self::DEAD . " is no longer in composer.lock, the owner "
            . "decision has LANDED: delete the tests-degraded job, the --allow-missing flag, both "
            . "helper scripts and these tests, in one commit. Output:\n" . $output
        );

        $derived = json_decode((string) file_get_contents($this->sandbox . '/composer-ci-degraded.lock'), true);

        $this->assertSame(
            $total - 1,
            count($derived['packages'] ?? []) + count($derived['packages-dev'] ?? []),
            'Exactly one entry, no more and no fewer.'
        );
        $this->assertSame($before, $this->hashes(), 'The copied committed pair was modified.');
    }
}
