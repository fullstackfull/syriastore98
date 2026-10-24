<?php

namespace Tests\Unit\Ci;

use PHPUnit\Framework\TestCase;

/**
 * BEHAVIOURAL COVER FOR `.github/scripts/flag-sensitive-diff.sh`, WHICH HAD NONE.
 *
 * Phase 00B found this script was the only thing standing between a change to config/, routes/,
 * Middleware/, Policies/, Permission, Role, Billing, Token or Secret and `main`, that it ran in
 * exactly one pull-request-only job, and that nothing tested it. P01-A' moved it into the
 * `static` job so it runs on every event, and gave it a second mode for the push case where
 * there is no pull request body to acknowledge in. A mode switch that nothing exercises is how a
 * blocking gate becomes a warning by accident, so each mode is pinned here.
 *
 * Every test drives the real script against a real throwaway git repository under sys_get_temp_dir().
 * Nothing touches this repository, the network, or any database.
 */
final class SensitiveDiffGateTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../.github/scripts/flag-sensitive-diff.sh';

    private string $repo = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertFileExists(self::SCRIPT, 'The sensitive-diff gate script is missing.');

        $this->repo = (string) tempnam(sys_get_temp_dir(), 'sdg');
        unlink($this->repo);
        mkdir($this->repo, 0700);

        // A repository with one baseline commit. `-c` so a runner with no global git identity
        // still works, and so this never reads the developer's own config.
        $this->git('init -q -b main');
        $this->git('config user.email gate@test.local');
        $this->git('config user.name  Gate Test');
        file_put_contents($this->repo . '/README.md', "baseline\n");
        $this->git('add README.md');
        $this->git('commit -q -m baseline');
    }

    protected function tearDown(): void
    {
        if ($this->repo !== '' && is_dir($this->repo)) {
            exec('rm -rf ' . escapeshellarg($this->repo));
        }

        parent::tearDown();
    }

    private function git(string $args): void
    {
        exec('cd ' . escapeshellarg($this->repo) . ' && git ' . $args . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, "git {$args} failed: " . implode("\n", $out));
    }

    /** @return array{0:int,1:string} exit code and combined output */
    private function runGate(string $baseRef, ?string $ackBody = null, ?string $mode = null): array
    {
        $cmd = 'cd ' . escapeshellarg($this->repo) . ' && ' . escapeshellarg(realpath(self::SCRIPT));
        $cmd .= ' ' . escapeshellarg($baseRef);

        if ($ackBody !== null) {
            $ackFile = $this->repo . '/.ack.txt';
            file_put_contents($ackFile, $ackBody);
            $cmd .= ' ' . escapeshellarg($ackFile);
        } elseif ($mode !== null) {
            $cmd .= ' ""';
        }

        if ($mode !== null) {
            $cmd .= ' ' . escapeshellarg($mode);
        }

        exec($cmd . ' 2>&1', $out, $code);

        return [$code, implode("\n", $out)];
    }

    private function commitFile(string $path, string $contents = "x\n"): void
    {
        $full = $this->repo . '/' . $path;
        @mkdir(dirname($full), 0700, true);
        file_put_contents($full, $contents);
        $this->git('add ' . escapeshellarg($path));
        $this->git('commit -q -m ' . escapeshellarg('touch ' . $path));
    }

    // ---------------------------------------------------------------------------------------

    public function test_a_diff_touching_nothing_sensitive_passes(): void
    {
        $this->commitFile('resources/views/some_page.blade.php');

        [$code, $out] = $this->runGate('HEAD~1');

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('no security-sensitive paths', $out);
    }

    /**
     * The property the whole control exists for. `enforce` is the pull-request mode.
     */
    public function test_enforce_mode_fails_a_sensitive_diff_with_no_acknowledgement(): void
    {
        $this->commitFile('config/services.php');

        [$code, $out] = $this->runGate('HEAD~1', '', 'enforce');

        $this->assertSame(1, $code, "A sensitive diff with no acknowledgement must fail.\n{$out}");
        $this->assertStringContainsString('SECURITY-SENSITIVE DIFF', $out);
        $this->assertStringContainsString('config/services.php', $out);
        $this->assertStringContainsString('::error::', $out);
    }

    public function test_enforce_mode_passes_when_the_checklist_is_acknowledged(): void
    {
        $this->commitFile('config/services.php');

        [$code, $out] = $this->runGate(
            'HEAD~1',
            "Reviewed the surface.\nSECURITY-DIFF-REVIEW: completed\n",
            'enforce'
        );

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('acknowledged', $out);
    }

    /**
     * A two-argument call is the pre-P01-A' invocation. It must still behave exactly as it did,
     * so the move into `static` cannot have changed pull-request behaviour.
     */
    public function test_the_two_argument_call_still_enforces(): void
    {
        $this->commitFile('routes/web.php');

        [$code, $out] = $this->runGate('HEAD~1', 'nothing here');

        $this->assertSame(1, $code, "Default mode must remain enforcing.\n{$out}");
    }

    /**
     * `report` is the push mode: loud, recorded, and NOT blocking -- because on a push there is
     * no body to acknowledge in. It must still name every sensitive path.
     */
    public function test_report_mode_warns_without_failing(): void
    {
        $this->commitFile('app/Http/Middleware/SomeGate.php');

        [$code, $out] = $this->runGate('HEAD~1', '', 'report');

        $this->assertSame(0, $code, "report mode must not block.\n{$out}");
        $this->assertStringContainsString('::warning::', $out);
        $this->assertStringNotContainsString('::error::', $out);
        $this->assertStringContainsString('Middleware/SomeGate.php', $out);
        $this->assertStringContainsString(
            'REPORTED',
            $out,
            'report mode must say plainly that the gate was not enforced, so a reader cannot '
            . 'mistake a warning for a satisfied check.'
        );
    }

    public function test_report_mode_still_honours_an_acknowledgement(): void
    {
        $this->commitFile('app/Policies/SomePolicy.php');

        [$code, $out] = $this->runGate('HEAD~1', "SECURITY-DIFF-REVIEW: completed\n", 'report');

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('acknowledged', $out);
        $this->assertStringNotContainsString('::warning::', $out);
    }

    /**
     * A TYPO MUST NOT BE THE THING THAT DOWNGRADES A BLOCKING GATE. An unrecognised mode is a
     * usage error, never a silent fall back to `report`.
     */
    public function test_an_unknown_mode_is_a_usage_error_not_a_downgrade(): void
    {
        $this->commitFile('config/services.php');

        [$code, $out] = $this->runGate('HEAD~1', '', 'repot');

        $this->assertSame(2, $code, "An unknown mode must exit 2.\n{$out}");
        $this->assertStringContainsString('unknown mode', $out);
    }

    public function test_a_missing_base_ref_is_a_usage_error(): void
    {
        exec(
            'cd ' . escapeshellarg($this->repo) . ' && ' . escapeshellarg((string) realpath(self::SCRIPT))
            . ' 2>&1',
            $out,
            $code
        );

        $this->assertSame(2, $code, implode("\n", $out));
        $this->assertStringContainsString('usage:', implode("\n", $out));
    }

    /**
     * The step summary is what makes a push-time bypass outlive the log. A collapsed log section
     * is where the old silence lived.
     */
    public function test_a_bypass_is_recorded_in_the_step_summary(): void
    {
        $this->commitFile('config/services.php');

        $summary = $this->repo . '/summary.md';
        $ack     = $this->repo . '/.ack.txt';
        file_put_contents($ack, '');

        exec(
            'cd ' . escapeshellarg($this->repo)
            . ' && GITHUB_STEP_SUMMARY=' . escapeshellarg($summary) . ' '
            . escapeshellarg((string) realpath(self::SCRIPT))
            . ' HEAD~1 ' . escapeshellarg($ack) . ' report 2>&1',
            $out,
            $code
        );

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertFileExists($summary, 'No run-summary entry was written for the bypass.');

        $written = (string) file_get_contents($summary);
        $this->assertStringContainsString('NOT acknowledged', $written);
        $this->assertStringContainsString('config/services.php', $written);
    }
}
