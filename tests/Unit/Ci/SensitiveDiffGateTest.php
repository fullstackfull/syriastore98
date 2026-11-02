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
     * P1-SEC-56. THE CLASSIFIER DID NOT LOOK WHERE THIS CODEBASE KEEPS ITS DEFECTS.
     *
     * Measured 2026-11-02 against the pattern as it stood: PR #128 -- which adds an authorization
     * gate to an OTP controller and moves an outbound send of a stored `api_secret` onto a pinned
     * transport -- matched 0 of its 7 files, and the job log read `no security-sensitive paths in
     * this diff.` The same pattern missed 0-of-7 on 2497f28e ("four live defects GATE F turned
     * on") and 0-of-2 on d9a3fbc2 ("the shipping quote leaked another tenant's product price",
     * which changed `app/Http/Controllers/Api/ApiController.php`).
     *
     * `app/Http/Controllers/`, `app/Services/` and `app/Models/` were simply not on the list,
     * while `Policies/`, `Middleware/`, `routes/` and `config/` were. But CLAUDE.md's whole
     * catalogue of this project's real defects is in controllers: the target derived from
     * `$request->some_id` instead of the principal, the authorization check missing its
     * operator-first clause, the `Product::find()` that is not scoped.
     *
     * The shell harness asserts all 27 surfaces in both directions. This is the same property in
     * a second runner, on the one surface that matters most, so a pattern edit cannot go green
     * here while the harness is skipped or vice versa.
     *
     * @dataProvider surfacesThatMustBeSensitive
     */
    public function test_the_surfaces_this_codebase_keeps_its_defects_on_are_sensitive(string $path): void
    {
        $this->commitFile($path);

        [$code, $out] = $this->runGate('HEAD~1', '', 'enforce');

        $this->assertSame(1, $code, "{$path} must be classified security-sensitive.\n{$out}");
        $this->assertStringContainsString('SECURITY-SENSITIVE DIFF', $out);
        $this->assertStringContainsString($path, $out);
    }

    public static function surfacesThatMustBeSensitive(): array
    {
        return [
            'a controller -- where the authorization check is' => ['app/Http/Controllers/OtpIntegrationController.php'],
            'the storefront API controller (d9a3fbc2)' => ['app/Http/Controllers/Api/ApiController.php'],
            'a customer auth controller -- "Auth" was not on the list' => ['app/Http/Controllers/Customer/Auth/CustomerLoginController.php'],
            'a FormRequest -- validation rules are a control' => ['app/Http/Requests/SomeRequest.php'],
            'a service -- SafeWebhook, OtpTransport, the money services' => ['app/Services/OtpTransport.php'],
            'a model -- global scopes, $hidden, casts' => ['app/Models/Product.php'],
            'an observer -- what calls the financial service at all' => ['app/Observers/OrderObserver.php'],
            'a security test -- weakening one is a security diff' => ['tests/Feature/Security/SomethingTest.php'],
            'a tenancy test (83ae48d9)' => ['tests/Feature/Tenancy/SomethingTest.php'],
            'the Flutter API contract' => ['tests/Contract/SomethingTest.php'],
        ];
    }

    /**
     * THE BOUND ON THE ABOVE, and it is load-bearing. Widening the classifier is only an
     * improvement while a routine diff still clears it: if UI, copy, docs and assets started
     * firing, the acknowledgement would become a reflex and the gate would mean nothing. These
     * are the diffs this project produces in bulk.
     *
     * @dataProvider surfacesThatMustStayQuiet
     */
    public function test_an_ordinary_diff_is_still_not_security_sensitive(string $path): void
    {
        $this->commitFile($path);

        [$code, $out] = $this->runGate('HEAD~1', '', 'enforce');

        $this->assertSame(0, $code, "{$path} must NOT be classified security-sensitive.\n{$out}");
        $this->assertStringContainsString('no security-sensitive paths', $out);
    }

    public static function surfacesThatMustStayQuiet(): array
    {
        return [
            'a blade view' => ['resources/views/store/dashboard.blade.php'],
            'an Arabic lang file' => ['lang/ar/messages.php'],
            'the design-system stylesheet' => ['public/css/store-syria-ds.css'],
            'a ledger document' => ['docs/development/phase-2/PHASE-02-TASK-LEDGER.md'],
            'an ordinary commerce test' => ['tests/Feature/Commerce/SomethingTest.php'],
        ];
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

    /**
     * INVERTED ON 2026-11-02 BY OWNER DIRECTION (Decision 3, item 3). It used to be
     * `test_report_mode_still_honours_an_acknowledgement`, and it asserted the opposite of what
     * this now asserts. The old contract was not a mistake when written -- it was a mistake in
     * what it trusted.
     *
     * `report` is the PUSH mode, and the acknowledgement file on a push is
     * `git log --format=%B base..HEAD` -- THE COMMIT MESSAGES. A commit message is not a review:
     * it is written by whoever wrote the code, before anyone reviewed it, and a reviewer cannot
     * amend it without rewriting history.
     *
     * AND IT WAS EXPLOITED, BY ACCIDENT, WHICH IS WHY THE OWNER CHANGED THE CONTRACT. The commit
     * that RECORDED the substring defect (P1-SEC-53) quoted the acknowledgement marker in its own
     * message while explaining it. Measured against the real commit range: with that line present
     * the script printed "Checklist acknowledged" and the `::warning` was SUPPRESSED; with the one
     * line stripped, the warning fired as designed. A control that goes quiet because somebody
     * documented it is the exact failure mode this file exists to prevent.
     *
     * So report mode now ignores the acknowledgement file ENTIRELY and always reports. The owner's
     * words: "Stop treating pushed commit messages as proof of human review. In push/report mode,
     * report the finding without falsely declaring acknowledgement."
     *
     * @see docs/security/HUMAN-REVIEW-GATE.md
     */
    public function test_report_mode_ignores_the_acknowledgement_file_entirely(): void
    {
        $this->commitFile('app/Policies/SomePolicy.php');

        // A PERFECTLY VALID acknowledgement, on its own line. In enforce mode this would pass.
        [$code, $out] = $this->runGate('HEAD~1', "SECURITY-DIFF-REVIEW: completed\n", 'report');

        $this->assertSame(0, $code, "report mode must still not block.\n{$out}");
        $this->assertStringContainsString(
            '::warning::',
            $out,
            'report mode must warn even when the ack file would have satisfied enforce mode: a '
            . 'push has nothing a reviewer could have written.'
        );
        $this->assertStringNotContainsString(
            'acknowledged',
            $out,
            'report mode must NOT declare an acknowledgement. The commit that recorded '
            . 'P1-SEC-53 quoted the marker in its message and silenced this warning.'
        );
        $this->assertStringContainsString('REPORTED IS NOT SATISFIED', $out);
    }

    /**
     * THE DEFECT THAT PROMPTED ALL OF THIS, as a test. The match was `grep -qF` -- a fixed-string
     * SUBSTRING search over the whole body -- so an agent writing a warning that the checklist was
     * NOT acknowledged, and quoting the marker inside that warning, SATISFIED THE GATE.
     *
     * Three check runs on one commit (acfa806b), with prose the only variable:
     *     18:43:10Z  the push, body had no marker       failure
     *     18:44:15Z  the description edit              SUCCESS, mergeable_state "clean"
     *     19:30:03Z  the string removed                failure
     *
     * Owner direction, Decision 3, item 2: "Ensure quotation, negation and ordinary documentation
     * do not satisfy the PR acknowledgement."
     *
     * @dataProvider nonAcknowledgements
     */
    public function test_the_marker_must_BE_a_line_not_appear_in_one(string $label, string $body): void
    {
        $this->commitFile('config/services.php');

        [$code, $out] = $this->runGate('HEAD~1', $body, 'enforce');

        $this->assertSame(1, $code, "{$label} must NOT acknowledge the checklist.\n{$out}");
        $this->assertStringNotContainsString('acknowledged', $out, $label);
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function nonAcknowledgements(): array
    {
        $m = 'SECURITY-DIFF-REVIEW: completed';

        return [
            // The real one. A backticked mention inside a sentence.
            'a backticked mention inside prose' => [
                'a backticked mention inside prose',
                "the acknowledgement line `{$m}` is absent, and I did not add it\n",
            ],
            // A sentence that DENIES the acknowledgement grants it, under a substring match.
            'a sentence denying it' => [
                'a sentence denying it',
                "I have NOT written {$m} anywhere in this body\n",
            ],
            'a markdown blockquote' => [
                'a markdown blockquote',
                "> {$m}\n",
            ],
            'words after the marker' => [
                'words after the marker',
                "{$m} -- but only once a human has actually done it\n",
            ],
            'words before the marker' => [
                'words before the marker',
                "do not merge until someone writes {$m}\n",
            ],
            // FOUND BY THIS TEST, not by reading the script: anchoring alone still accepted a
            // fenced block, because inside a fence the marker IS its own line -- and a fenced
            // block is how anyone would DOCUMENT the marker. A pull request explaining this gate
            // would have acknowledged itself.
            'the marker inside a fenced block' => [
                'the marker inside a fenced block',
                "```\n{$m}\n```\n",
            ],
            'the marker inside a tilde fence' => [
                'the marker inside a tilde fence',
                "~~~\n{$m}\n~~~\n",
            ],
            'the marker inside a fence with an info string' => [
                'the marker inside a fence with an info string',
                "```text\n{$m}\n```\n",
            ],
            // Safe direction: an unterminated fence swallows the rest, so the gate stays red.
            'the marker after an unterminated fence' => [
                'the marker after an unterminated fence',
                "```\nsome example\n\n{$m}\n",
            ],
        ];
    }

    /**
     * THE POSITIVE CONTROL FOR THE ABOVE. Anchoring must not have made the gate unsatisfiable --
     * a reviewer's standalone line still acknowledges it, indented or with CRLF endings, because
     * GitHub pull-request bodies arrive with CRLF and a reviewer may indent.
     *
     * Without this, every assertion in test_the_marker_must_BE_a_line_not_appear_in_one would
     * also pass against a gate that simply never acknowledges anything.
     *
     * @dataProvider realAcknowledgements
     */
    public function test_a_standalone_marker_line_still_acknowledges(string $label, string $body): void
    {
        $this->commitFile('config/services.php');

        [$code, $out] = $this->runGate('HEAD~1', $body, 'enforce');

        $this->assertSame(0, $code, "{$label} must acknowledge the checklist.\n{$out}");
        $this->assertStringContainsString('acknowledged on its own line', $out, $label);
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function realAcknowledgements(): array
    {
        $m = 'SECURITY-DIFF-REVIEW: completed';

        return [
            'on its own line'            => ['on its own line', "answers above\n\n{$m}\n"],
            'indented'                   => ['indented', "answers above\n\n    {$m}\n"],
            'with CRLF line endings'     => ['with CRLF line endings', "answers above\r\n{$m}\r\n"],
            'with trailing whitespace'   => ['with trailing whitespace', "{$m}   \n"],
            'as the only line in a body' => ['as the only line in a body', "{$m}"],
        ];
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
