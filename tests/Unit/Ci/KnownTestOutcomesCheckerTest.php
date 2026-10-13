<?php

namespace Tests\Unit\Ci;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE GATE'S OWN TESTS.
 * =============================================================================================
 *
 * .github/scripts/assert-known-test-outcomes.php is what makes the Feature suite a BLOCKING CI
 * gate despite eight accepted failures and seventeen accepted skips. If it is wrong in the
 * permissive direction, the project is back to `continue-on-error: true` with extra steps -- a
 * gate-shaped thing that passes whatever happens. So it is exercised here against synthetic
 * JUnit logs with known verdicts, the same way
 * tests/Unit/Dependency/ComposerLockIntegrityGuardTest.php exercises the lock guard.
 *
 * WHAT IS PROVEN, each case being a way the gate could have been vacuous:
 *   - a log matching the manifest passes;
 *   - ONE extra failure fails it (the regression case it exists for);
 *   - ONE declared failure that starts passing fails it (the case a count CANNOT catch, and the
 *     reason this is a set comparison);
 *   - an extra skip fails it (skipping to get green is forbidden);
 *   - a declared skip that stops skipping fails it;
 *   - an error fails it even when failures and skips match exactly;
 *   - a truncated log -- too few testcases -- fails it instead of passing vacuously;
 *   - a skip whose reason no longer names the recorded blocker fails it;
 *   - data-set suffixes are part of the identity, so two data sets of one method are judged
 *     separately.
 *
 * It also pins the REAL manifest against the REAL script: the committed
 * .github/known-test-outcomes.json must be valid, must declare exactly 8 failures and 17 skips,
 * and a log built from it must pass. That is what stops the manifest and the checker drifting
 * apart without anything noticing.
 */
class KnownTestOutcomesCheckerTest extends TestCase
{
    private string $workspace;

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function script(): string
    {
        return $this->repoRoot() . '/.github/scripts/assert-known-test-outcomes.php';
    }

    private function realManifestPath(): string
    {
        return $this->repoRoot() . '/.github/known-test-outcomes.json';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir() . '/known-outcomes-' . bin2hex(random_bytes(6));

        if (! mkdir($this->workspace, 0777, true) && ! is_dir($this->workspace)) {
            $this->fail('Could not create a scratch directory for the fixtures.');
        }
    }

    protected function tearDown(): void
    {
        foreach ((glob($this->workspace . '/*') ?: []) as $file) {
            @unlink($file);
        }

        @rmdir($this->workspace);

        parent::tearDown();
    }

    /**
     * Build a JUnit log. $cases maps "Class::name" to one of:
     *   null                      -> a passing test
     *   ['failure' => 'message']
     *   ['skipped' => 'message']
     *   ['error'   => 'message']
     * $padding adds that many passing filler cases so the minimum_testcases floor is satisfied
     * for the cases that are not about the floor.
     */
    private function junit(array $cases, int $padding = 0): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<testsuites>\n";
        $xml .= "  <testsuite name=\"Feature\">\n";

        foreach ($cases as $id => $outcome) {
            [$class, $name] = explode('::', $id, 2);

            $xml .= sprintf(
                "    <testcase name=\"%s\" class=\"%s\" assertions=\"1\" time=\"0.1\"",
                htmlspecialchars($name, ENT_QUOTES | ENT_XML1),
                htmlspecialchars($class, ENT_QUOTES | ENT_XML1)
            );

            if ($outcome === null) {
                $xml .= "/>\n";

                continue;
            }

            $tag     = array_key_first($outcome);
            $message = (string) $outcome[$tag];

            $xml .= sprintf(
                ">\n      <%s>%s</%s>\n    </testcase>\n",
                $tag,
                htmlspecialchars($message, ENT_QUOTES | ENT_XML1),
                $tag
            );
        }

        for ($i = 0; $i < $padding; $i++) {
            $xml .= sprintf(
                "    <testcase name=\"test_filler_%d\" class=\"Tests\\Feature\\Filler\" assertions=\"1\" time=\"0.01\"/>\n",
                $i
            );
        }

        $xml .= "  </testsuite>\n</testsuites>\n";

        $path = $this->workspace . '/junit-' . bin2hex(random_bytes(4)) . '.xml';
        file_put_contents($path, $xml);

        return $path;
    }

    private function manifest(
        array $failures,
        array $skips,
        int $floor = 10,
        bool $requireReasonLog = false
    ): string {
        $path = $this->workspace . '/manifest-' . bin2hex(random_bytes(4)) . '.json';

        file_put_contents($path, json_encode([
            'minimum_testcases'  => $floor,
            'require_reason_log' => $requireReasonLog,
            'expected_failures'  => $failures,
            'expected_skips'     => $skips,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * A TeamCity log carrying skip reasons, in the shape PHPUnit 11 emits: a testStarted line
     * whose locationHint holds the FQCN, then testIgnored with the message. $skips maps
     * "Class::name" to the reason text.
     */
    private function teamcity(array $skips): string
    {
        $escape = static fn (string $v): string => str_replace(
            ['|', "'", "\n", "\r", '[', ']'],
            ['||', "|'", '|n', '|r', '|[', '|]'],
            $v
        );

        $lines = [];

        foreach ($skips as $id => $reason) {
            [$class, $name] = explode('::', $id, 2);

            $lines[] = sprintf(
                "##teamcity[testStarted name='%s' locationHint='php_qn:///tmp/X.php::\\%s::%s' flowId='1']",
                $escape($name),
                $class,
                $escape($name)
            );
            $lines[] = sprintf(
                "##teamcity[testIgnored name='%s' message='%s' duration='1' flowId='1']",
                $escape($name),
                $escape((string) $reason)
            );
            $lines[] = sprintf("##teamcity[testFinished name='%s' duration='1' flowId='1']", $escape($name));
        }

        $path = $this->workspace . '/teamcity-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }

    /** @return array{0:int,1:string} exit code and combined output */
    private function invoke(string $junit, string $manifest, ?string $teamcity = null): array
    {
        $command = sprintf(
            '%s %s %s %s%s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->script()),
            escapeshellarg($junit),
            escapeshellarg($manifest),
            $teamcity === null ? '' : ' ' . escapeshellarg($teamcity)
        );

        $output = [];
        $code   = 0;
        exec($command, $output, $code);

        return [$code, implode("\n", $output)];
    }

    // -----------------------------------------------------------------------------------------
    // THE BASELINE: a log that matches passes.
    // -----------------------------------------------------------------------------------------
    public function test_a_log_matching_the_manifest_passes(): void
    {
        $manifest = $this->manifest(
            ['Tests\Feature\A::test_one' => ['cause' => 'known defect at Foo.php:1']],
            ['Tests\Feature\B::test_two' => ['blocked_on' => 'store_wallets']]
        );

        $junit = $this->junit([
            'Tests\Feature\A::test_one' => ['failure' => 'boom'],
            'Tests\Feature\B::test_two' => ['skipped' => 'BLOCKED: store_wallets has no migration'],
            'Tests\Feature\C::test_ok'  => null,
        ], padding: 20);

        [$code, $output] = $this->invoke($junit, $manifest);

        $this->assertSame(0, $code, "A matching log must pass. Output:\n" . $output);
        $this->assertStringContainsString('matches the manifest exactly', $output);
    }

    // -----------------------------------------------------------------------------------------
    // THE REGRESSION CASE.
    // -----------------------------------------------------------------------------------------
    public function test_one_undeclared_failure_fails_the_gate(): void
    {
        $manifest = $this->manifest(
            ['Tests\Feature\A::test_one' => ['cause' => 'known']],
            []
        );

        $junit = $this->junit([
            'Tests\Feature\A::test_one' => ['failure' => 'boom'],
            'Tests\Feature\Z::test_new' => ['failure' => 'a brand new regression'],
        ], padding: 20);

        [$code, $output] = $this->invoke($junit, $manifest);

        $this->assertSame(1, $code, "An undeclared failure must fail the gate. Output:\n" . $output);
        $this->assertStringContainsString('NEW FAILURE', $output);
        $this->assertStringContainsString('Tests\Feature\Z::test_new', $output);
        // And the known one must NOT be reported as new.
        $this->assertStringNotContainsString('NEW FAILURE, not in the manifest: Tests\Feature\A::test_one', $output);
    }

    /**
     * THE CASE A COUNT CANNOT CATCH, and the reason the gate compares sets.
     *
     * One declared failure is fixed and one new failure appears. The TOTAL IS STILL ONE, so a
     * "fail unless failures == N" gate would pass this and let a regression through behind a
     * repair. The set comparison reports both halves.
     */
    public function test_a_fixed_known_failure_swapped_for_a_new_one_is_caught_although_the_count_is_unchanged(): void
    {
        $manifest = $this->manifest(
            ['Tests\Feature\A::test_one' => ['cause' => 'known defect at Foo.php:1']],
            []
        );

        $junit = $this->junit([
            'Tests\Feature\A::test_one' => null,                                 // fixed
            'Tests\Feature\Z::test_new' => ['failure' => 'new regression'],      // appeared
        ], padding: 20);

        [$code, $output] = $this->invoke($junit, $manifest);

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('DECLARED FAILURE NO LONGER FAILS', $output);
        $this->assertStringContainsString('Tests\Feature\A::test_one', $output);
        $this->assertStringContainsString('NEW FAILURE', $output);
        $this->assertStringContainsString('Tests\Feature\Z::test_new', $output);
    }

    public function test_a_known_failure_that_starts_passing_fails_the_gate_on_its_own(): void
    {
        $manifest = $this->manifest(
            ['Tests\Feature\A::test_one' => ['cause' => 'known defect at Foo.php:1']],
            []
        );

        $junit = $this->junit(['Tests\Feature\A::test_one' => null], padding: 20);

        [$code, $output] = $this->invoke($junit, $manifest);

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('DECLARED FAILURE NO LONGER FAILS', $output);
    }

    // -----------------------------------------------------------------------------------------
    // SKIPS.
    // -----------------------------------------------------------------------------------------
    public function test_an_undeclared_skip_fails_the_gate(): void
    {
        $manifest = $this->manifest([], []);

        $junit = $this->junit([
            'Tests\Feature\S::test_skipped' => ['skipped' => 'not today'],
        ], padding: 20);

        [$code, $output] = $this->invoke($junit, $manifest);

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('NEW SKIP', $output);
        $this->assertStringContainsString('A skipped test is NOT a passed test', $output);
    }

    public function test_a_declared_skip_that_stops_skipping_fails_the_gate(): void
    {
        $manifest = $this->manifest(
            [],
            ['Tests\Feature\S::test_skipped' => ['blocked_on' => 'store_wallets']]
        );

        $junit = $this->junit(['Tests\Feature\S::test_skipped' => null], padding: 20);

        [$code, $output] = $this->invoke($junit, $manifest);

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('DECLARED SKIP NO LONGER SKIPS', $output);
    }

    public function test_a_skip_whose_reason_no_longer_names_the_recorded_blocker_fails_the_gate(): void
    {
        $manifest = $this->manifest(
            [],
            ['Tests\Feature\S::test_skipped' => ['blocked_on' => 'store_wallets']]
        );

        // Still skipped, under the same name. A set comparison on names alone would accept this
        // and the project would be tolerating something new without any count moving.
        $junit = $this->junit([
            'Tests\Feature\S::test_skipped' => ['skipped' => ''],
        ], padding: 20);

        $teamcity = $this->teamcity([
            'Tests\Feature\S::test_skipped' => 'flaky on CI, revisit later',
        ]);

        [$code, $output] = $this->invoke($junit, $manifest, $teamcity);

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('SKIP REASON CHANGED', $output);
        $this->assertStringContainsString('store_wallets', $output);
    }

    public function test_a_skip_whose_reason_still_names_the_recorded_blocker_passes(): void
    {
        $manifest = $this->manifest(
            [],
            ['Tests\Feature\S::test_skipped' => ['blocked_on' => 'store_wallets']]
        );

        $junit = $this->junit(['Tests\Feature\S::test_skipped' => ['skipped' => '']], padding: 20);

        $teamcity = $this->teamcity([
            'Tests\Feature\S::test_skipped' => 'BLOCKED: `store_wallets` is declared by no migration',
        ]);

        [$code, $output] = $this->invoke($junit, $manifest, $teamcity);

        $this->assertSame(0, $code, "Output:\n" . $output);
        $this->assertStringContainsString('skip reasons parsed  : 1', $output);
    }

    /**
     * THE REASON CHECK MUST NOT BE SILENTLY DISABLE-ABLE.
     *
     * The reasons live in a SECOND log, so forgetting --log-teamcity in the workflow would take
     * half the gate offline while CI stayed green. With require_reason_log set, that is a hard
     * failure.
     */
    public function test_a_manifest_requiring_the_reason_log_fails_when_it_is_not_supplied(): void
    {
        $manifest = $this->manifest(
            [],
            ['Tests\Feature\S::test_skipped' => ['blocked_on' => 'store_wallets']],
            requireReasonLog: true
        );

        $junit = $this->junit(['Tests\Feature\S::test_skipped' => ['skipped' => '']], padding: 20);

        [$code, $output] = $this->invoke($junit, $manifest);

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('require_reason_log', $output);
    }

    public function test_a_missing_teamcity_log_file_fails(): void
    {
        $manifest = $this->manifest([], [], requireReasonLog: true);

        $junit = $this->junit([], padding: 20);

        [$code, $output] = $this->invoke($junit, $manifest, $this->workspace . '/no-such-teamcity.txt');

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('TeamCity log not found', $output);
    }

    /**
     * A TeamCity log the parser cannot read at all must not pass as "all reasons verified".
     * This is the vacuity trap the reason check could most easily fall into: zero reasons parsed
     * means zero mismatches found.
     */
    public function test_an_unparseable_teamcity_log_fails_rather_than_verifying_nothing(): void
    {
        $manifest = $this->manifest(
            [],
            ['Tests\Feature\S::test_skipped' => ['blocked_on' => 'store_wallets']],
            requireReasonLog: true
        );

        $junit = $this->junit(['Tests\Feature\S::test_skipped' => ['skipped' => '']], padding: 20);

        $path = $this->workspace . '/garbage-teamcity.txt';
        file_put_contents($path, "this is not a teamcity log at all\nnor is this line\n");

        [$code, $output] = $this->invoke($junit, $manifest, $path);

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('Parsed zero skips', $output);
    }

    // -----------------------------------------------------------------------------------------
    // ERRORS AND VACUITY.
    // -----------------------------------------------------------------------------------------
    public function test_an_error_fails_the_gate_even_when_failures_and_skips_match(): void
    {
        $manifest = $this->manifest(
            ['Tests\Feature\A::test_one' => ['cause' => 'known']],
            ['Tests\Feature\B::test_two' => ['blocked_on' => 'store_wallets']]
        );

        $junit = $this->junit([
            'Tests\Feature\A::test_one' => ['failure' => 'boom'],
            'Tests\Feature\B::test_two' => ['skipped' => 'store_wallets has no migration'],
            'Tests\Feature\E::test_err' => ['error' => 'Class "Foo" not found'],
        ], padding: 20);

        [$code, $output] = $this->invoke($junit, $manifest);

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('ERRORED', $output);
        $this->assertStringContainsString('zero-errors baseline', $output);
    }

    public function test_a_truncated_log_fails_instead_of_passing_vacuously(): void
    {
        // Nothing failed and nothing skipped, so every set comparison is satisfied. Without the
        // floor this would be a GREEN gate over a run that never happened.
        $manifest = $this->manifest([], [], floor: 500);

        $junit = $this->junit(['Tests\Feature\A::test_one' => null], padding: 2);

        [$code, $output] = $this->invoke($junit, $manifest);

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('testcases are in the JUnit log', $output);
        $this->assertStringContainsString('Refusing to pass', $output);
    }

    public function test_a_missing_log_fails(): void
    {
        $manifest = $this->manifest([], []);

        [$code, $output] = $this->invoke($this->workspace . '/does-not-exist.xml', $manifest);

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('JUnit log not found', $output);
    }

    public function test_an_unparseable_log_fails(): void
    {
        $manifest = $this->manifest([], []);

        $path = $this->workspace . '/truncated.xml';
        file_put_contents($path, "<?xml version=\"1.0\"?>\n<testsuites><testsuite><testcas");

        [$code, $output] = $this->invoke($path, $manifest);

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('could not be parsed as XML', $output);
    }

    public function test_a_missing_manifest_fails(): void
    {
        $junit = $this->junit([], padding: 20);

        [$code, $output] = $this->invoke($junit, $this->workspace . '/no-manifest.json');

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('Manifest not found', $output);
    }

    // -----------------------------------------------------------------------------------------
    // DATA SETS ARE PART OF THE IDENTITY.
    // -----------------------------------------------------------------------------------------
    public function test_two_data_sets_of_one_method_are_judged_separately(): void
    {
        $manifest = $this->manifest(
            [],
            [
                'Tests\Feature\T::test_rows with data set "wallets"' => ['blocked_on' => 'store_wallets'],
            ]
        );

        $junit = $this->junit([
            'Tests\Feature\T::test_rows with data set "wallets"' => ['skipped' => 'store_wallets has no migration'],
            // The SAME method, a different data set, newly skipped and not declared.
            'Tests\Feature\T::test_rows with data set "orders"'   => ['skipped' => 'cannot be bothered'],
        ], padding: 20);

        [$code, $output] = $this->invoke($junit, $manifest);

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('with data set "orders"', $output);
        $this->assertStringNotContainsString('NEW SKIP, not in the manifest: Tests\Feature\T::test_rows with data set "wallets"', $output);
    }

    public function test_nested_testsuites_are_walked(): void
    {
        // PHPUnit nests <testsuite> per directory and per class. A checker that only looked one
        // level deep would see nothing in a real log and pass vacuously.
        $path = $this->workspace . '/nested.xml';
        $filler = '';
        for ($i = 0; $i < 20; $i++) {
            $filler .= sprintf('<testcase name="f%d" class="Tests\F" assertions="1" time="0.01"/>', $i);
        }

        file_put_contents($path, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="Feature">
    <testsuite name="Tests\\Feature\\Deep">
      <testsuite name="Tests\\Feature\\Deep\\DeeperTest">
        <testcase name="test_buried" class="Tests\\Feature\\Deep\\DeeperTest" assertions="1" time="0.1">
          <failure>buried failure</failure>
        </testcase>
      </testsuite>
    </testsuite>
    {$filler}
  </testsuite>
</testsuites>
XML);

        [$code, $output] = $this->invoke($path, $this->manifest([], []));

        $this->assertSame(1, $code, "A failure nested three levels down must still be seen. Output:\n" . $output);
        $this->assertStringContainsString('Tests\Feature\Deep\DeeperTest::test_buried', $output);
    }

    // -----------------------------------------------------------------------------------------
    // THE COMMITTED MANIFEST ITSELF.
    // -----------------------------------------------------------------------------------------
    public function test_the_committed_manifest_is_valid_and_declares_the_recorded_counts(): void
    {
        $this->assertFileExists($this->realManifestPath());

        $manifest = json_decode((string) file_get_contents($this->realManifestPath()), true);

        $this->assertIsArray($manifest, 'The committed manifest is not valid JSON: ' . json_last_error_msg());

        $this->assertCount(
            8,
            $manifest['expected_failures'],
            'The committed manifest no longer declares exactly 8 failures. The MEASURED Feature '
            . 'baseline is 8 failures / 0 errors / 17 skips. If that changed for a good reason, '
            . 'change this number in the same commit and account for the delta test by test.'
        );

        $this->assertCount(
            17,
            $manifest['expected_skips'],
            'The committed manifest no longer declares exactly 17 skips.'
        );

        // Every entry must carry its evidence. A bare name in this file would be an accepted
        // failure with no recorded reason, which is how a parking lot starts.
        foreach ($manifest['expected_failures'] as $id => $entry) {
            $this->assertIsArray($entry, "Failure entry {$id} must be an object.");
            $this->assertArrayHasKey('cause', $entry, "Failure entry {$id} has no 'cause'.");
            $this->assertArrayHasKey('owner', $entry, "Failure entry {$id} has no 'owner'.");
            $this->assertNotSame('', trim((string) $entry['cause']), "Failure entry {$id} has an empty cause.");
        }

        foreach ($manifest['expected_skips'] as $id => $entry) {
            $this->assertIsArray($entry, "Skip entry {$id} must be an object.");
            $this->assertArrayHasKey('blocked_on', $entry, "Skip entry {$id} has no 'blocked_on'.");
            $this->assertNotSame('', trim((string) $entry['blocked_on']), "Skip entry {$id} names no blocker.");
        }
    }

    public function test_the_committed_manifest_requires_the_reason_log(): void
    {
        $manifest = json_decode((string) file_get_contents($this->realManifestPath()), true);

        $this->assertTrue(
            (bool) ($manifest['require_reason_log'] ?? false),
            'The committed manifest no longer requires the TeamCity reason log, so the CI gate '
            . 'would check only WHICH tests skip and not WHY -- and would say so in a notice '
            . 'nobody reads instead of failing. Set require_reason_log back to true.'
        );
    }

    public function test_the_real_script_accepts_a_log_built_from_the_real_manifest(): void
    {
        $manifest = json_decode((string) file_get_contents($this->realManifestPath()), true);

        $cases    = [];
        $reasons  = [];

        foreach (array_keys($manifest['expected_failures']) as $id) {
            $cases[$id] = ['failure' => 'synthesised failure for the gate self-test'];
        }

        foreach ($manifest['expected_skips'] as $id => $entry) {
            $cases[$id] = ['skipped' => ''];
            // Include the recorded blocker so the reason check is exercised against the real
            // tokens, not a placeholder.
            $reasons[$id] = 'BLOCKED: ' . $entry['blocked_on'] . ' is declared by no migration';
        }

        $junit = $this->junit($cases, padding: (int) $manifest['minimum_testcases']);

        [$code, $output] = $this->invoke(
            $junit,
            $this->realManifestPath(),
            $this->teamcity($reasons)
        );

        $this->assertSame(
            0,
            $code,
            "The committed manifest and the committed script disagree, which would make the CI "
            . "gate fail for a reason unrelated to the suite. Output:\n" . $output
        );
    }

    /**
     * And the real manifest must reject a log where one declared skip has been retargeted. Run
     * over every skip entry so no recorded blocker token is a dead letter.
     */
    #[DataProvider('committedSkipIds')]
    public function test_the_real_manifest_rejects_a_retargeted_skip(string $retargeted): void
    {
        $manifest = json_decode((string) file_get_contents($this->realManifestPath()), true);

        $cases   = [];
        $reasons = [];

        foreach (array_keys($manifest['expected_failures']) as $id) {
            $cases[$id] = ['failure' => 'synthesised'];
        }

        foreach ($manifest['expected_skips'] as $id => $entry) {
            $cases[$id]   = ['skipped' => ''];
            $reasons[$id] = $id === $retargeted
                ? 'temporarily disabled, will look at it later'
                : 'BLOCKED: ' . $entry['blocked_on'];
        }

        $junit = $this->junit($cases, padding: (int) $manifest['minimum_testcases']);

        [$code, $output] = $this->invoke($junit, $this->realManifestPath(), $this->teamcity($reasons));

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('SKIP REASON CHANGED', $output);
        $this->assertStringContainsString($retargeted, $output);
    }

    public static function committedSkipIds(): array
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/.github/known-test-outcomes.json'),
            true
        );

        $sets = [];

        foreach (array_keys($manifest['expected_skips'] ?? []) as $id) {
            $sets[$id] = [$id];
        }

        return $sets;
    }

    /**
     * And the complement: the real manifest must REJECT a log that is missing any single one of
     * its declared failures. Run across every entry so no single row can be a dead letter.
     */
    #[DataProvider('committedFailureIds')]
    public function test_the_real_manifest_rejects_a_log_missing_one_declared_failure(string $missing): void
    {
        $manifest = json_decode((string) file_get_contents($this->realManifestPath()), true);

        $cases   = [];
        $reasons = [];

        foreach (array_keys($manifest['expected_failures']) as $id) {
            $cases[$id] = $id === $missing ? null : ['failure' => 'synthesised'];
        }

        // Every skip is correct and its reason names the recorded blocker, so the ONLY thing
        // wrong with this log is the one missing failure -- which is what makes the assertion
        // below attributable rather than merely true.
        foreach ($manifest['expected_skips'] as $id => $entry) {
            $cases[$id]   = ['skipped' => ''];
            $reasons[$id] = 'BLOCKED: ' . $entry['blocked_on'];
        }

        $junit = $this->junit($cases, padding: (int) $manifest['minimum_testcases']);

        [$code, $output] = $this->invoke($junit, $this->realManifestPath(), $this->teamcity($reasons));

        $this->assertSame(1, $code, "Output:\n" . $output);
        $this->assertStringContainsString('DECLARED FAILURE NO LONGER FAILS', $output);
        $this->assertStringContainsString($missing, $output);
        $this->assertStringNotContainsString('SKIP REASON CHANGED', $output);
        $this->assertStringNotContainsString('NEW FAILURE', $output);
    }

    public static function committedFailureIds(): array
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/.github/known-test-outcomes.json'),
            true
        );

        $sets = [];

        foreach (array_keys($manifest['expected_failures'] ?? []) as $id) {
            $sets[$id] = [$id];
        }

        return $sets;
    }
}
