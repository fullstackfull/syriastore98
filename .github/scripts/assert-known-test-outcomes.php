#!/usr/bin/env php
<?php

/**
 * ASSERT THE KNOWN-OUTCOME SET, NOT A COUNT.
 * =============================================================================================
 *
 * WHAT PROBLEM THIS SOLVES. The Feature suite has eight failures and seventeen skips that are
 * real, understood and not regressions. Three ways of handling that in CI were available and two
 * of them are dishonest:
 *
 *   continue-on-error: true          -- what this pipeline did until now. The suite runs, the
 *                                      numbers scroll past, and NOTHING is enforced. A ninth
 *                                      failure is indistinguishable from the eight. This is how
 *                                      every number the project published came to be maintained
 *                                      by hand.
 *   a hard-coded expected count      -- "fail unless failures == 8". Better, but it passes when
 *                                      one known failure is fixed and a new one appears in its
 *                                      place, which is the exact scenario a regression gate
 *                                      exists for.
 *   assert the SET                   -- what this script does. Every failing and skipped test is
 *                                      matched by NAME against a declared manifest. A new
 *                                      failure fails the gate. A known failure that starts
 *                                      passing ALSO fails the gate, because the manifest has
 *                                      become a lie and someone must delete the entry.
 *
 * The manifest is .github/known-test-outcomes.json. It carries, per entry, the test identifier,
 * the root cause with a file:line, and who owns the fix -- so the gate's output explains itself
 * instead of printing a number.
 *
 * ERRORS ARE NEVER TOLERATED. The suite's zero-errors property is worth keeping separately from
 * its failures: an error means a test could not run at all, which usually means a fixture or the
 * framework broke rather than an assertion. There is no manifest section for errors and there
 * should not be one.
 *
 * SELF-CHECK. This script refuses to pass if the JUnit log is missing, unparseable, or contains
 * fewer testcases than the manifest's recorded floor -- because all three would make every
 * comparison below trivially empty, and a gate that passes when it cannot see the run is worse
 * than no gate. Covered by tests/Unit/Ci/KnownTestOutcomesCheckerTest.php, which feeds it
 * synthetic JUnit logs with known verdicts.
 *
 * WHY TWO LOGS. The outcome SET comes from --log-junit, which is the stable machine-readable
 * format. The skip REASONS cannot: MEASURED on PHPUnit 11.5.57, --log-junit writes a bare
 * `<skipped/>` element with no message at all, so a reason check against JUnit can only ever
 * compare against the empty string. --log-teamcity does carry it
 * (`##teamcity[testIgnored name='...' message='...']`), so the reason check reads that instead.
 * When the manifest sets require_reason_log, a missing TeamCity log is a FAILURE rather than a
 * quietly skipped check -- otherwise dropping one CI flag would disable half the gate in silence.
 *
 * Usage: assert-known-test-outcomes.php <junit.xml> [manifest.json] [teamcity.txt]
 * Exit:  0 = the observed outcome set matches the manifest exactly
 *        1 = it does not, or the log could not be trusted
 */

$argv0 = $argv[0] ?? 'assert-known-test-outcomes.php';

if ($argc < 2) {
    fwrite(STDERR, "usage: {$argv0} <junit.xml> [manifest.json] [teamcity.txt]\n");
    exit(1);
}

$junitPath    = $argv[1];
$manifestPath = $argv[2] ?? dirname(__DIR__) . '/known-test-outcomes.json';
$teamcityPath = $argv[3] ?? null;

/** Emit a GitHub-annotated error line, and plain text when not on Actions. */
$error = static function (string $line): void {
    fwrite(STDERR, '::error::' . $line . "\n");
};

$notice = static function (string $line): void {
    fwrite(STDOUT, $line . "\n");
};

// ---------------------------------------------------------------------------------------------
// Load the manifest.
// ---------------------------------------------------------------------------------------------
if (! is_file($manifestPath)) {
    $error("Manifest not found at {$manifestPath}. The gate cannot judge the run without it.");
    exit(1);
}

$manifest = json_decode((string) file_get_contents($manifestPath), true);

if (! is_array($manifest)) {
    $error("Manifest at {$manifestPath} is not valid JSON: " . json_last_error_msg());
    exit(1);
}

foreach (['minimum_testcases', 'expected_failures', 'expected_skips'] as $key) {
    if (! array_key_exists($key, $manifest)) {
        $error("Manifest is missing the required key '{$key}'.");
        exit(1);
    }
}

$expectedFailures = $manifest['expected_failures'];
$expectedSkips    = $manifest['expected_skips'];

if (! is_array($expectedFailures) || ! is_array($expectedSkips)) {
    $error("Manifest keys 'expected_failures' and 'expected_skips' must both be objects.");
    exit(1);
}

// ---------------------------------------------------------------------------------------------
// Load the JUnit log.
// ---------------------------------------------------------------------------------------------
if (! is_file($junitPath)) {
    $error("JUnit log not found at {$junitPath}. PHPUnit did not write one, which usually means");
    $error('the process died before it could -- check the step log above for a fatal or an exit().');
    exit(1);
}

$previous = libxml_use_internal_errors(true);
$document = simplexml_load_string((string) file_get_contents($junitPath));
libxml_use_internal_errors($previous);

if ($document === false) {
    $error("JUnit log at {$junitPath} could not be parsed as XML. A truncated log means the run");
    $error('was killed mid-write; a gate must not pass on a log it cannot read.');
    exit(1);
}

/**
 * Walk every <testcase> and classify it. PHPUnit nests <testsuite> arbitrarily deep, so this
 * recurses rather than assuming two levels.
 *
 * The identifier is "Class::name", and `name` keeps PHPUnit's data-set suffix
 * (`... with data set "finance: wallets"`) because two data sets of one method can have
 * different outcomes -- three of the seventeen skips are exactly that.
 */
$observed = ['total' => 0, 'failures' => [], 'errors' => [], 'skips' => []];

$walk = static function ($node) use (&$walk, &$observed): void {
    foreach ($node->testsuite ?? [] as $suite) {
        $walk($suite);
    }

    foreach ($node->testcase ?? [] as $case) {
        $observed['total']++;

        $class = (string) ($case['class'] ?? '');
        $name  = (string) ($case['name'] ?? '');

        $id = ($class !== '' ? $class . '::' : '') . $name;

        // ORDER MATTERS. A testcase can in principle carry more than one child; an error is the
        // most severe and is reported as such.
        if (isset($case->error)) {
            $observed['errors'][$id] = trim((string) $case->error);

            continue;
        }

        if (isset($case->failure)) {
            $observed['failures'][$id] = trim((string) $case->failure);

            continue;
        }

        if (isset($case->skipped)) {
            $observed['skips'][$id] = trim((string) $case->skipped);
        }
    }
};

$walk($document);

$notice('KNOWN-OUTCOME GATE');
$notice(str_repeat('-', 78));
$notice(sprintf('junit log            : %s', $junitPath));
$notice(sprintf('manifest             : %s', $manifestPath));
$notice(sprintf('testcases observed   : %d  (manifest floor %d)', $observed['total'], (int) $manifest['minimum_testcases']));
$notice(sprintf('failures observed    : %d  (manifest declares %d)', count($observed['failures']), count($expectedFailures)));
$notice(sprintf('errors observed      : %d  (always expected: 0)', count($observed['errors'])));
$notice(sprintf('skips observed       : %d  (manifest declares %d)', count($observed['skips']), count($expectedSkips)));
$notice('');

$failed = false;

// ---------------------------------------------------------------------------------------------
// 0. REFUSE TO PASS VACUOUSLY.
//
// A log holding three testcases would satisfy every set comparison below (nothing unexpected
// failed, nothing unexpected skipped) while proving nothing at all. The floor is deliberately
// below the real count so that legitimately adding tests does not trip it, and far enough above
// zero that a suite which aborted early cannot slip past.
// ---------------------------------------------------------------------------------------------
if ($observed['total'] < (int) $manifest['minimum_testcases']) {
    $error(sprintf(
        'Only %d testcases are in the JUnit log; the manifest floor is %d.',
        $observed['total'],
        (int) $manifest['minimum_testcases']
    ));
    $error('The run did not complete, so every comparison below would pass without checking');
    $error('anything. Refusing to pass. Raise minimum_testcases only when tests are REMOVED on');
    $error('purpose, and say why in the manifest.');
    $failed = true;
}

// ---------------------------------------------------------------------------------------------
// 1. NO ERRORS, EVER.
// ---------------------------------------------------------------------------------------------
foreach ($observed['errors'] as $id => $message) {
    $error('ERRORED: ' . $id);
    $error('  ' . substr(preg_replace('/\s+/', ' ', $message) ?? '', 0, 400));
    $failed = true;
}

if ($observed['errors'] !== []) {
    $error('The suite has a zero-errors baseline and there is no manifest section for errors.');
    $error('An error means a test could not run -- a broken fixture, a missing class, a fatal --');
    $error('which is a different and worse thing than a failing assertion.');
}

// ---------------------------------------------------------------------------------------------
// 2. EVERY FAILURE MUST BE DECLARED.
// ---------------------------------------------------------------------------------------------
$undeclaredFailures = array_diff_key($observed['failures'], $expectedFailures);

foreach ($undeclaredFailures as $id => $message) {
    $error('NEW FAILURE, not in the manifest: ' . $id);
    $error('  ' . substr(preg_replace('/\s+/', ' ', $message) ?? '', 0, 600));
    $failed = true;
}

if ($undeclaredFailures !== []) {
    $error('');
    $error('This is a regression unless you can show otherwise. Do NOT add it to');
    $error('.github/known-test-outcomes.json to get green -- that file records failures the owner');
    $error('has accepted with a named root cause, not a list of things currently broken.');
}

// ---------------------------------------------------------------------------------------------
// 3. EVERY DECLARED FAILURE MUST STILL FAIL.
//
// The half that a count cannot express. If a known failure starts passing, the manifest is now
// wrong, and leaving it in place would let a future regression hide inside an accepted entry.
// ---------------------------------------------------------------------------------------------
foreach ($expectedFailures as $id => $entry) {
    if (isset($observed['failures'][$id])) {
        continue;
    }

    $reason = is_array($entry) ? ($entry['cause'] ?? '') : (string) $entry;

    if (! isset($observed['errors'][$id]) && ! array_key_exists($id, $observed['failures'])) {
        $error('DECLARED FAILURE NO LONGER FAILS: ' . $id);
        $error('  manifest says: ' . substr((string) $reason, 0, 300));
        $error('  If the underlying defect was fixed, DELETE this entry from');
        $error('  .github/known-test-outcomes.json in the same commit as the fix. The gate fails');
        $error('  here on purpose: an accepted-failure list that still names a passing test is a');
        $error('  place for the next regression to hide.');
        $failed = true;
    }
}

// ---------------------------------------------------------------------------------------------
// 4. EVERY SKIP MUST BE DECLARED, AND MUST STILL NAME ITS BLOCKER.
// ---------------------------------------------------------------------------------------------
$undeclaredSkips = array_diff_key($observed['skips'], $expectedSkips);

foreach ($undeclaredSkips as $id => $message) {
    $error('NEW SKIP, not in the manifest: ' . $id);
    $error('  ' . substr(preg_replace('/\s+/', ' ', $message) ?? '', 0, 400));
    $failed = true;
}

if ($undeclaredSkips !== []) {
    $error('');
    $error('A skipped test is NOT a passed test. Every skip in this project must name the');
    $error('undeclared table or external dependency that blocks it, and be recorded here with');
    $error('that name. Skipping to get green is forbidden.');
}

foreach ($expectedSkips as $id => $entry) {
    if (! array_key_exists($id, $observed['skips'])) {
        $reason = is_array($entry) ? ($entry['blocked_on'] ?? '') : (string) $entry;

        $error('DECLARED SKIP NO LONGER SKIPS: ' . $id);
        $error('  manifest says it is blocked on: ' . substr((string) $reason, 0, 200));
        $error('  If the blocker is gone -- a GATE F table finally has a migration of record --');
        $error('  that is good news and the entry must be deleted here in the same commit.');
        $failed = true;
    }
}

// ---------------------------------------------------------------------------------------------
// 5. THE SKIP REASON MUST STILL MENTION THE BLOCKER THE MANIFEST RECORDS.
//
// Guards against a skip being retargeted at a different cause while keeping its name -- which
// would quietly widen what the project is tolerating without changing any count or any set.
//
// READ FROM THE TEAMCITY LOG, NOT JUNIT. Measured on PHPUnit 11.5.57: --log-junit emits a bare
// `<skipped/>` with no message, so this check is IMPOSSIBLE against JUnit and an implementation
// that tried would compare every reason against the empty string and fail all seventeen -- which
// is exactly what the first version of this script did. --log-teamcity carries the message.
// ---------------------------------------------------------------------------------------------
$requireReasonLog = (bool) ($manifest['require_reason_log'] ?? false);

/**
 * Parse `##teamcity[...]` lines into "Class::name" => skip message.
 *
 * The testIgnored line carries no class, but the testStarted line immediately before it carries
 * locationHint='php_qn:///path/To/File.php::\Fully\Qualified\Class::name', so the class is taken
 * from there. PHPUnit emits testStarted -> testIgnored -> testFinished for the same test, so the
 * pairing is positional and exact.
 */
$teamcitySkips = static function (string $path): array {
    $skips = [];
    $currentClass = null;

    $handle = fopen($path, 'r');

    if ($handle === false) {
        return $skips;
    }

    while (($line = fgets($handle)) !== false) {
        if (str_contains($line, '##teamcity[testStarted')) {
            // php_qn:///...php::\Class\Name::method ...   -- take the segment after '::\'
            if (preg_match("/locationHint='php_qn:\/\/[^']*?::\\\\([^:']+)::/", $line, $m)) {
                $currentClass = $m[1];
            }

            continue;
        }

        if (! str_contains($line, '##teamcity[testIgnored')) {
            continue;
        }

        if (! preg_match("/name='(.*?)' message='(.*?)' duration=/s", $line, $m)) {
            continue;
        }

        // TeamCity escaping: |' |n |r || |[ |]
        $unescape = static fn (string $v): string => str_replace(
            ['|n', '|r', "|'", '|[', '|]', '||'],
            ["\n", "\r", "'", '[', ']', '|'],
            $v
        );

        $name    = $unescape($m[1]);
        $message = $unescape($m[2]);

        $skips[($currentClass !== null ? $currentClass . '::' : '') . $name] = $message;
    }

    fclose($handle);

    return $skips;
};

if ($teamcityPath === null) {
    if ($requireReasonLog) {
        $error('No TeamCity log was passed, but the manifest sets require_reason_log.');
        $error('The skip-reason half of this gate therefore did not run. Pass the log:');
        $error('  phpunit --log-junit junit.xml --log-teamcity teamcity.txt');
        $error('  assert-known-test-outcomes.php junit.xml .github/known-test-outcomes.json teamcity.txt');
        $error('This is a failure rather than a silent skip on purpose: dropping one CI flag must');
        $error('not be able to disable a control without anyone noticing.');
        $failed = true;
    } else {
        $notice('NOTE: no TeamCity log passed, so skip REASONS were not verified (only the set).');
    }
} elseif (! is_file($teamcityPath)) {
    $error("TeamCity log not found at {$teamcityPath}. PHPUnit did not write one.");
    $failed = true;
} else {
    $reasons = $teamcitySkips($teamcityPath);

    if ($reasons === [] && $expectedSkips !== []) {
        $error("Parsed zero skips out of {$teamcityPath} while the manifest declares "
            . count($expectedSkips) . '. Either the run skipped nothing (in which case the set');
        $error('checks above have already said so) or this parser no longer understands the log');
        $error('format. Refusing to report the reason check as passed either way.');
        $failed = true;
    }

    $notice(sprintf('skip reasons parsed  : %d from %s', count($reasons), basename($teamcityPath)));
    $notice('');

    foreach ($expectedSkips as $id => $entry) {
        if (! is_array($entry) || ! isset($entry['blocked_on'])) {
            continue;
        }

        // Only judge the reason of a skip that actually happened; a declared skip that did not
        // skip has already been reported in section 4.
        if (! array_key_exists($id, $observed['skips'])) {
            continue;
        }

        $token = (string) $entry['blocked_on'];

        if ($token === '') {
            continue;
        }

        if (! array_key_exists($id, $reasons)) {
            $error('NO SKIP REASON RECOVERED FOR: ' . $id);
            $error('  It is skipped according to the JUnit log but absent from the TeamCity log,');
            $error('  so its reason could not be checked against the recorded blocker (' . $token . ').');
            $failed = true;

            continue;
        }

        if (stripos($reasons[$id], $token) === false) {
            $error('SKIP REASON CHANGED: ' . $id);
            $error('  manifest expects the reason to mention: ' . $token);
            $error('  actual reason: ' . substr(preg_replace('/\s+/', ' ', $reasons[$id]) ?? '', 0, 300));
            $error('  A skip that no longer names the blocker the record claims is either skipping');
            $error('  for a new reason or has lost its evidence. Both need a human.');
            $failed = true;
        }
    }
}

$notice('');

if ($failed) {
    $error('');
    $error('KNOWN-OUTCOME GATE FAILED. The observed outcome set does not match the manifest.');
    exit(1);
}

$notice(sprintf(
    'OK: the observed outcome set matches the manifest exactly -- %d declared failures, '
    . '%d declared skips, 0 errors, and nothing undeclared.',
    count($expectedFailures),
    count($expectedSkips)
));
$notice('');
$notice('This is NOT a statement that the suite is green. It is a statement that the suite is');
$notice('failing and skipping exactly the tests the project has recorded, for the reasons it has');
$notice('recorded. Read .github/known-test-outcomes.json for what is still owed and to whom.');

exit(0);
