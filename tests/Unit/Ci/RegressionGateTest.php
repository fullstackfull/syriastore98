<?php

namespace Tests\Unit\Ci;

use PHPUnit\Framework\TestCase;

/**
 * The gate is only worth having if it is itself trustworthy, so it is tested the way the
 * repository already tests its other CI checker (tests/Unit/Ci/KnownTestOutcomesCheckerTest).
 *
 * The case that matters most is the one a count-based comparison gets wrong: a branch that
 * resolves one failure and introduces another has an unchanged total and must still fail.
 */
class RegressionGateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/regression-gate-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @param array<string,bool> $cases id => passed */
    private function junit(string $name, array $cases): string
    {
        $xml = "<?xml version=\"1.0\"?>\n<testsuites><testsuite name=\"s\">";

        foreach ($cases as $id => $passed) {
            [$class, $method] = explode('::', $id, 2);
            $xml .= "<testcase class=\"{$class}\" name=\"{$method}\">";
            $xml .= $passed ? '' : '<failure type="x">boom</failure>';
            $xml .= '</testcase>';
        }

        $xml .= '</testsuite></testsuites>';
        $path = "{$this->dir}/{$name}.xml";
        file_put_contents($path, $xml);

        return $path;
    }

    /** @return array{0:int,1:string,2:array} */
    private function invoke(string $base, string $head, array $opts = []): array
    {
        $json = "{$this->dir}/out.json";
        $cmd = sprintf(
            'php %s %s %s --json=%s --manifest=%s 2>&1',
            escapeshellarg(base_path_fallback('.github/scripts/regression-gate.php')),
            escapeshellarg($base),
            escapeshellarg($head),
            escapeshellarg($json),
            escapeshellarg($opts['manifest'] ?? '/dev/null')
        );

        if (isset($opts['official-exit'])) {
            $cmd = str_replace(' 2>&1', ' --official-exit=' . (int) $opts['official-exit'] . ' 2>&1', $cmd);
        }

        exec($cmd, $out, $code);

        return [$code, implode("\n", $out), is_file($json) ? json_decode(file_get_contents($json), true) : []];
    }

    public function test_a_clean_branch_passes(): void
    {
        $base = $this->junit('base', ['A\T::a' => true, 'A\T::b' => true]);
        $head = $this->junit('head', ['A\T::a' => true, 'A\T::b' => true]);

        [$code, , $json] = $this->invoke($base, $head);

        $this->assertSame(0, $code);
        $this->assertSame('no_new_failures', $json['verdict']);
    }

    public function test_an_inherited_failure_does_not_fail_the_branch(): void
    {
        $base = $this->junit('base', ['A\T::a' => false, 'A\T::b' => true]);
        $head = $this->junit('head', ['A\T::a' => false, 'A\T::b' => true]);

        [$code, $out, $json] = $this->invoke($base, $head);

        $this->assertSame(0, $code);
        $this->assertSame(['A\T::a'], $json['inherited_unaccepted_drift']);
        $this->assertStringContainsString('UNRESOLVED BASELINE DRIFT', $out);
    }

    public function test_a_new_failure_fails_the_branch(): void
    {
        $base = $this->junit('base', ['A\T::a' => true]);
        $head = $this->junit('head', ['A\T::a' => false]);

        [$code, $out, $json] = $this->invoke($base, $head);

        $this->assertSame(1, $code);
        $this->assertSame(['A\T::a'], $json['new_failures']);
        $this->assertStringContainsString('NEW FAILURE', $out);
    }

    /**
     * THE CASE A COUNT COMPARISON GETS WRONG. One resolved, one introduced: the total is
     * identical and the branch is still a regression.
     */
    public function test_one_fixed_and_one_broken_still_fails(): void
    {
        $base = $this->junit('base', ['A\T::a' => false, 'A\T::b' => true]);
        $head = $this->junit('head', ['A\T::a' => true, 'A\T::b' => false]);

        [$code, , $json] = $this->invoke($base, $head);

        $this->assertSame(1, $code, 'equal failure counts masked a regression');
        $this->assertSame(['A\T::b'], $json['new_failures']);
        $this->assertSame(['A\T::a'], $json['resolved_failures']);
    }

    public function test_a_manifest_accepted_failure_is_reported_separately_from_drift(): void
    {
        $manifest = "{$this->dir}/m.json";
        file_put_contents($manifest, json_encode(['expected_failures' => ['A\T::a']]));

        $base = $this->junit('base', ['A\T::a' => false, 'A\T::b' => false]);
        $head = $this->junit('head', ['A\T::a' => false, 'A\T::b' => false]);

        [$code, , $json] = $this->invoke($base, $head, ['manifest' => $manifest]);

        $this->assertSame(0, $code);
        $this->assertSame(['A\T::a'], $json['inherited_accepted_failures']);
        $this->assertSame(['A\T::b'], $json['inherited_unaccepted_drift']);
    }

    /**
     * The real manifest keys `expected_failures` BY TEST ID, with the accepted root cause as the
     * value. Iterating the values instead of the keys classified every manifest-accepted failure
     * as unaccepted drift -- silently inverting the one distinction this gate exists to draw.
     * Caught by running the gate against the real manifest and seeing 0 accepted / 13 drift
     * where the truth was 8 / 5.
     */
    public function test_it_reads_the_manifests_real_object_keyed_shape(): void
    {
        $manifest = "{$this->dir}/m2.json";
        file_put_contents($manifest, json_encode([
            'expected_failures' => ['A\T::a' => ['cause' => 'known and accepted']],
        ]));

        $base = $this->junit('base', ['A\T::a' => false, 'A\T::b' => false]);
        $head = $this->junit('head', ['A\T::a' => false, 'A\T::b' => false]);

        [$code, , $json] = $this->invoke($base, $head, ['manifest' => $manifest]);

        $this->assertSame(0, $code);
        $this->assertSame(['A\T::a'], $json['inherited_accepted_failures']);
        $this->assertSame(['A\T::b'], $json['inherited_unaccepted_drift']);
    }

    public function test_it_reports_the_official_gate_as_blocked_and_refuses_to_imply_green(): void
    {
        $base = $this->junit('base', ['A\T::a' => false]);
        $head = $this->junit('head', ['A\T::a' => false]);

        [$code, $out, $json] = $this->invoke($base, $head, ['official-exit' => 1]);

        $this->assertSame(0, $code, 'no new failures, so this gate passes');
        $this->assertSame('BLOCKED', $json['official_gate']['status']);
        $this->assertStringContainsString('CI IS NOT GREEN', $out);
        $this->assertStringContainsString('OVERALL: NOT GREEN', $out);
    }

    public function test_an_empty_or_truncated_run_is_refused_rather_than_called_clean(): void
    {
        $empty = "{$this->dir}/empty.xml";
        file_put_contents($empty, '<?xml version="1.0"?><testsuites></testsuites>');
        $head = $this->junit('head', ['A\T::a' => true]);

        [$code, $out] = $this->invoke($empty, $head);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('did not happen', $out);
    }
}

if (! function_exists('Tests\Unit\Ci\base_path_fallback')) {
    function base_path_fallback(string $rel): string
    {
        return dirname(__DIR__, 3) . '/' . $rel;
    }
}
