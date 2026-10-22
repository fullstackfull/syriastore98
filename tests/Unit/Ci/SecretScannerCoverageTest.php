<?php

namespace Tests\Unit\Ci;

use PHPUnit\Framework\TestCase;

/**
 * P1-SEC-05 -- the secret screen could not see a secret written in PHP array syntax, and the three
 * artefacts the repository had already declared unwanted were still tracked.
 * =============================================================================================
 *
 * WHAT WAS WRONG. `.github/scripts/scan-secrets.sh`'s `generic-assigned-secret` rule required the
 * key name and the operator to be ADJACENT (`[[:space:]]*` between them). Every secret in this
 * project's own config and settings code is written `'api_key' => 'literal'`, where a QUOTE sits
 * between them, so the entire array form was invisible. MEASURED before the fix: `$api_key = '…'`
 * caught; `'api_key' => '…'`, `"client_secret" => "…"`, `'MAIL_PASSWORD' => '…'` and
 * `["access_token"]="…"` all missed. `MAIL_PASSWORD` is the name P1-SEC-18 was about.
 *
 * THE PART THAT MAKES THIS A RATCHET AND NOT A REGEX TEST. The pattern exists TWICE in that
 * script -- once as the rule, once as NARROWING 1's quoted-literal gate -- and the gate is
 * reached only for findings the rule already produced. Fixing one copy alone fixes NOTHING: the
 * rule matches and then the gate's `continue` throws the finding away. That was measured too. So
 * these tests assert BOTH copies carry the tolerance, and that they agree with each other.
 */
class SecretScannerCoverageTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../.github/scripts/scan-secrets.sh';

    /** The tolerance that lets a closing quote or bracket sit between the name and the operator. */
    private const TOLERANCE = '[^[:alnum:]_]{0,2}';

    private function script(): string
    {
        $this->assertFileExists(self::SCRIPT);

        return (string) file_get_contents(self::SCRIPT);
    }

    /**
     * Both copies of the name-then-operator pattern, extracted from the script itself rather than
     * restated here -- a test that carries its own copy of the regex passes while the script is
     * broken.
     */
    private function patterns(): array
    {
        $src = $this->script();
        $keyword = '(password|passwd|secret|api_?key|apikey|auth_?token|access_?token|client_?secret)';
        preg_match_all(
            '/' . preg_quote($keyword, '/') . '(\[\^\[:alnum:\]_\]\{0,2\})?\[\[:space:\]\]\*\(=\|=>\|:\)/',
            $src,
            $m,
            PREG_SET_ORDER
        );

        return $m;
    }

    public function test_the_script_is_valid_bash(): void
    {
        exec('bash -n ' . escapeshellarg(self::SCRIPT) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, "scan-secrets.sh is not valid bash:\n" . implode("\n", $out));
    }

    /**
     * There are exactly two copies and BOTH carry the tolerance. If this fails with 1 of 2, someone
     * fixed the rule and left the gate -- which is the shape that fixes nothing.
     */
    public function test_both_copies_of_the_pattern_tolerate_a_quote_before_the_operator(): void
    {
        $found = $this->patterns();

        $this->assertCount(2, $found,
            'Expected exactly two copies of the keyword-then-operator pattern in scan-secrets.sh '
            . '(the rule, and NARROWING 1\'s quoted-literal gate). Found ' . count($found)
            . '. If a copy was added or removed, this test must be updated deliberately.');

        foreach ($found as $i => $set) {
            $this->assertSame(self::TOLERANCE, $set[1] ?? '',
                'Copy #' . ($i + 1) . ' of the pattern does not carry ' . self::TOLERANCE . '. '
                . 'Both must: the gate is only reached for findings the rule produced, so a '
                . 'one-sided fix leaves PHP array syntax invisible exactly as before.');
        }
    }

    /**
     * THE REMOVAL CHECK THE CRITERION ASKS FOR, and it drives the real script rather than a
     * re-implementation of its regex. A throwaway git repository is built with a base commit and a
     * head commit that ADDS the planted lines, because the screen is diff-only by design; the
     * script is then run exactly as CI runs it.
     *
     * Extracting the patterns and re-running them through PCRE was tried first and abandoned: the
     * gate's ERE is assembled in bash as `['"'"'"]`, so any single-quote-delimited extraction
     * truncates it, and POSIX ERE is not PCRE in any case. Driving the script removes both
     * problems.
     */
    public function test_a_planted_php_array_secret_fails_the_scanner(): void
    {
        [$repo, $base] = $this->throwawayRepo();

        // A non-test path, because the generic rule is deliberately narrowed away from tests/.
        file_put_contents($repo . '/config/planted.php', "<?php\nreturn [\n"
            . "    'api_key' => 'a1b2c3d4e5f6g7h8i9j0k1',\n"
            . "    \"client_secret\" => \"abcdef1234567890xyz\",\n"
            . "    'MAIL_PASSWORD' => 's3cretS3cretS3cret',\n"
            . "];\n");
        $this->commitAll($repo);

        [$code, $out] = $this->runScanner($repo, $base);

        $this->assertSame(1, $code, "The scanner must refuse a planted PHP-array secret.\n" . $out);
        $this->assertStringContainsString('config/planted.php', $out);
        $this->assertSame(3, substr_count($out, 'HIT [generic-assigned-secret] config/planted.php'),
            "All three array forms must be reported, not just one.\n" . $out);
        $this->assertStringNotContainsString('a1b2c3d4e5f6g7h8i9j0k1', $out,
            'The scanner must print locations only -- a CI log is a durable, widely-readable artifact.');
    }

    /** The other half: the shapes that must NOT fire, or the gate is noise and gets ignored. */
    public function test_the_scanner_does_not_fire_on_calls_placeholders_or_mentions(): void
    {
        [$repo, $base] = $this->throwawayRepo();

        file_put_contents($repo . '/config/benign.php', "<?php\n"
            . "// secret: see the deployment notes\n"
            . "/** @param string \$client_secret the caller supplies */\n"
            . "return [\n"
            . "    'stripe_secret' => env('STRIPE_SECRET'),\n"
            . "    'secret' => config('services.x.secret'),\n"
            . "    'password' => \$request->password,\n"
            . "    'api_key' => 'placeholder-value-for-tests-only',\n"
            . "];\n");
        $this->commitAll($repo);

        [$code, $out] = $this->runScanner($repo, $base);

        $this->assertSame(0, $code, "A call, a mention and a placeholder must not be flagged.\n" . $out);
    }

    /** The generic rule is narrowed away from tests/ -- planting there must NOT fire... */
    public function test_the_generic_rule_is_not_applied_to_the_test_tree(): void
    {
        [$repo, $base] = $this->throwawayRepo();

        mkdir($repo . '/tests/Feature', 0777, true);
        file_put_contents($repo . '/tests/Feature/FixtureTest.php',
            "<?php\n\$body = ['password' => 'attacker-chosen-password'];\n");
        $this->commitAll($repo);

        [$code, $out] = $this->runScanner($repo, $base);
        $this->assertSame(0, $code, "A fixture password in tests/ must not block the build.\n" . $out);
    }

    /** ...but a HIGH-confidence shape there still must, which is what makes that narrowing safe. */
    public function test_a_high_confidence_credential_in_the_test_tree_still_fails(): void
    {
        [$repo, $base] = $this->throwawayRepo();

        mkdir($repo . '/tests/Feature', 0777, true);
        file_put_contents($repo . '/tests/Feature/LeakTest.php',
            "<?php\n\$k = 'AKIAIOSFODNN7EXAMPLE';\n");
        $this->commitAll($repo);

        [$code, $out] = $this->runScanner($repo, $base);
        $this->assertSame(1, $code,
            "An AWS key in the test tree must still be caught -- the high-confidence rules are "
            . "unnarrowed, and that is the whole basis on which the tests/ skip is defensible.\n" . $out);
        $this->assertStringContainsString('aws-access-key-id', $out);
    }

    /**
     * THE EXCLUSION FOR THIS FILE IS EXACTLY ONE FILE WIDE, AND THAT IS ASSERTED BOTH WAYS.
     *
     * WHY THE EXCLUSION EXISTS. This file must contain a real-shaped AWS key, because
     * test_a_high_confidence_credential_in_the_test_tree_still_fails asserts that such a shape in
     * the test tree still fails the build -- and that assertion is the whole justification for the
     * structural `tests/*` skip the generic rule now carries. The key cannot be renamed into the
     * placeholder vocabulary either: the AWS rule is deliberately exempt from that filter, which is
     * precisely what makes AKIAIOSFODNN7EXAMPLE a hit on purpose. So the file is in `EXCLUDE_RE`,
     * the same way the script and the gitleaks config already are, and for the reason the script's
     * own comment gives: a file that is by its nature full of credential-shaped patterns matches
     * its own rules.
     *
     * FOUND BY CI, NOT BY READING. Run 39835585655 failed with
     * `HIT [aws-access-key-id] tests/Unit/Ci/SecretScannerCoverageTest.php:160` on the very commit
     * that added this file. The local gate had reported `scan-secrets.sh exit=0` -- but with
     * "SKIP: no base ref given", so it had scanned nothing. The lesson belongs in a test and not
     * only in a comment: a scanner exclusion is a hole by construction, so its width is a control.
     *
     * ASSERTED BEHAVIOURALLY RATHER THAN BY READING THE REGEX, because the regex's spelling is not
     * the property that matters -- what matters is which paths the scanner refuses to look at. The
     * same credential is planted at the excluded path and at a sibling path in the same directory;
     * the first must pass and the second must fail. A broadened exclusion (`tests/Unit/Ci/`, or
     * `tests/`, or an accidental unanchored match) fails the second half immediately.
     */
    public function test_the_exclusion_for_this_file_is_exactly_one_file_wide(): void
    {
        [$repo, $base] = $this->throwawayRepo();

        mkdir($repo . '/tests/Unit/Ci', 0777, true);

        // The excluded path: this file's own name. Must NOT block the build.
        file_put_contents(
            $repo . '/tests/Unit/Ci/SecretScannerCoverageTest.php',
            "<?php\n\$k = 'AKIAIOSFODNN7EXAMPLE';\n"
        );
        // A sibling in the same directory, carrying the IDENTICAL value. Must block it.
        file_put_contents(
            $repo . '/tests/Unit/Ci/SomeOtherCiTest.php',
            "<?php\n\$k = 'AKIAIOSFODNN7EXAMPLE';\n"
        );
        $this->commitAll($repo);

        [$code, $out] = $this->runScanner($repo, $base);

        $this->assertSame(1, $code,
            'An AWS key in tests/Unit/Ci/SomeOtherCiTest.php must still fail the build -- the '
            . "exclusion is for THIS file only, not for its directory.\n" . $out);
        $this->assertStringContainsString('SomeOtherCiTest.php', $out,
            'The sibling file must be the reported hit.');
        $this->assertStringNotContainsString('SecretScannerCoverageTest.php', $out,
            'This file is excluded, so it must not be reported -- otherwise the exclusion is not '
            . "working and the build it is meant to unblock stays red.\n" . $out);
    }

    /**
     * AND NO FOURTH FILE JOINED THE LIST WITHOUT SAYING SO.
     *
     * The behavioural test above proves the exclusion is not too WIDE for this one path; this one
     * proves the LIST has not grown. Two path-anchored alternatives, covering three files: the
     * script and the gitleaks config in one group, and this file. An exclusion list is the one
     * place in this screen where adding a line silently REDUCES coverage, so it is pinned -- the
     * same both-directions discipline .github/known-test-outcomes.json uses, for the same reason.
     */
    public function test_the_exclusion_list_names_exactly_the_three_self_referential_files(): void
    {
        $script = file_get_contents(self::SCRIPT);
        $this->assertNotFalse($script);

        $line = null;
        foreach (preg_split('/\R/', $script) as $candidate) {
            if (str_starts_with($candidate, 'EXCLUDE_RE=')) {
                $line = $candidate;
                break;
            }
        }
        $this->assertNotNull($line, 'EXCLUDE_RE has been renamed or removed.');

        // Split the pattern on TOP-LEVEL `|` only, then keep the alternatives anchored to a
        // specific repo-relative path (`^...$`). Depth matters: a naive
        // `preg_match_all('/\^([^|]+?)\$/')` stops at the `|` INSIDE
        // `(scripts/scan-secrets\.sh|gitleaks\.toml)` and silently reports one anchor where there
        // are two -- which is how the first version of this test failed. A naive `/\^[^^$]*\$/`
        // has the opposite fault: it starts at the `^` inside `(^|/)` and runs on into the next
        // alternative's `$`.
        $value = substr($line, strlen('EXCLUDE_RE='));
        $value = trim($value, "'");

        $parts = [];
        $buffer = '';
        $depth = 0;
        $escaped = false;

        foreach (str_split($value) as $char) {
            if ($escaped) {
                $buffer .= $char;
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $buffer .= $char;
                $escaped = true;
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }
            if ($char === '|' && $depth === 0) {
                $parts[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        $parts[] = $buffer;

        $anchored = [];
        foreach ($parts as $part) {
            if (str_starts_with($part, '^') && str_ends_with($part, '$')) {
                $anchored[] = substr($part, 1, -1);
            }
        }

        $this->assertSame(
            [
                '\.github/(scripts/scan-secrets\.sh|gitleaks\.toml)',
                'tests/Unit/Ci/SecretScannerCoverageTest\.php',
            ],
            $anchored,
            'The path-anchored exclusions changed. Each one is a path this screen refuses to look '
            . 'at, so each needs a recorded reason in the comment above EXCLUDE_RE -- and the only '
            . 'reason accepted so far is that the file is itself full of credential-shaped patterns '
            . 'because its job is to describe or exercise this script. Three files qualify. If a '
            . 'fourth genuinely does, say why there and update this list; do not add it quietly.'
        );
    }

    // ---------------------------------------------------------------------------------------------

    /** @return array{0:string,1:string} the repo path and its base commit sha */
    private function throwawayRepo(): array
    {
        $repo = sys_get_temp_dir() . '/sec05-' . bin2hex(random_bytes(6));
        mkdir($repo . '/config', 0777, true);
        mkdir($repo . '/.github/scripts', 0777, true);
        copy(self::SCRIPT, $repo . '/.github/scripts/scan-secrets.sh');
        chmod($repo . '/.github/scripts/scan-secrets.sh', 0755);
        file_put_contents($repo . '/config/app.php', "<?php\nreturn [];\n");

        $q = escapeshellarg($repo);
        exec("git -C {$q} init -q 2>&1 && git -C {$q} config user.email t@e.test && "
            . "git -C {$q} config user.name t && git -C {$q} add -A && "
            . "git -C {$q} -c commit.gpgsign=false commit -q -m base 2>&1", $o, $c);
        $this->assertSame(0, $c, "could not build the throwaway repo:\n" . implode("\n", $o));

        $base = trim((string) shell_exec("git -C {$q} rev-parse HEAD"));
        $this->assertNotSame('', $base);

        $this->repos[] = $repo;

        return [$repo, $base];
    }

    private function commitAll(string $repo): void
    {
        $q = escapeshellarg($repo);
        exec("git -C {$q} add -A && git -C {$q} -c commit.gpgsign=false commit -q -m head 2>&1", $o, $c);
        $this->assertSame(0, $c, "could not commit the planted file:\n" . implode("\n", $o));
    }

    /** @return array{0:int,1:string} */
    private function runScanner(string $repo, string $base): array
    {
        exec('bash ' . escapeshellarg($repo . '/.github/scripts/scan-secrets.sh') . ' '
            . escapeshellarg($base) . ' ' . escapeshellarg($repo) . ' 2>&1', $out, $code);

        return [$code, implode("\n", $out)];
    }

    /** @var list<string> */
    private array $repos = [];

    protected function tearDown(): void
    {
        foreach ($this->repos as $r) {
            exec('rm -rf ' . escapeshellarg($r));
        }
        $this->repos = [];
        parent::tearDown();
    }

    /**
     * The low-confidence rule is narrowed away from the test tree; the HIGH-confidence rules are
     * not, and that asymmetry is the whole basis on which the narrowing is defensible. If someone
     * widens the skip to cover every rule, a live AWS key in a fixture stops being screened.
     */
    public function test_only_the_generic_rule_is_narrowed_away_from_the_test_tree(): void
    {
        $src = $this->script();

        $this->assertMatchesRegularExpression(
            '/if \[\[ "\$\{name\}" == \'generic-assigned-secret\' && "\$\{file\}" == tests\/\* \]\]/',
            $src,
            'the test-tree skip must be conditional on the generic rule by name'
        );

        foreach (['aws-access-key-id', 'private-key-block', 'stripe-live-key', 'google-api-key', 'jwt-literal', 'db-url-with-password', 'slack-token'] as $highConfidence) {
            $this->assertStringNotContainsString(
                '"${name}" == \'' . $highConfidence . '\'',
                $src,
                $highConfidence . ' has acquired a narrowing. The high-confidence rules are '
                . 'deliberately unnarrowed -- they are what still covers the test tree.'
            );
        }
    }

    /**
     * S10: three artefacts the repository had already declared unwanted and was still tracking.
     * `error_log` carried 975 B of PHP fatals from the LIVE host including its absolute path.
     *
     * Untracking does not remove them from history, and history is not rewritten here -- that
     * remains an owner decision, recorded with P1-OWN-10.
     *
     * @dataProvider unwantedArtefacts
     */
    public function test_the_artefact_is_untracked_and_ignored(string $path): void
    {
        $root = dirname(__DIR__, 3);

        exec('git -C ' . escapeshellarg($root) . ' ls-files --error-unmatch ' . escapeshellarg($path) . ' 2>/dev/null', $o, $tracked);
        $this->assertNotSame(0, $tracked, $path . ' is tracked again. `git rm --cached` it.');

        exec('git -C ' . escapeshellarg($root) . ' check-ignore -q ' . escapeshellarg($path) . ' 2>/dev/null', $o2, $ignored);
        $this->assertSame(0, $ignored, $path . ' is not matched by .gitignore, so it can be re-added by accident.');
    }

    public static function unwantedArtefacts(): array
    {
        return [
            'live-host error log' => ['error_log'],
            'shell-escaping artefact' => ["_active', 1)"],
            'composer phar' => ['composer.phar'],
        ];
    }
}
