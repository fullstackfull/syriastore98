<?php

declare(strict_types=1);

/**
 * MERGE-BASE REGRESSION GATE.
 *
 * WHY THIS EXISTS, AND WHAT IT IS NOT. The official gate is
 * .github/scripts/assert-known-test-outcomes.php, which compares the observed outcome set
 * against .github/known-test-outcomes.json. That manifest is the owner's record of failures
 * accepted with a named root cause. It currently declares 8 failures; the repository's own
 * merge base produces more than that, so the official gate is red on main as well as on any
 * branch cut from it, and the manifest's README explicitly forbids adding entries to get green.
 *
 * This script does NOT fix that, hide it, or substitute for it. It answers a different and
 * narrower question, which is the one a reviewer actually needs while the manifest is stale:
 *
 *     Did THIS BRANCH break something that was working at the merge base?
 *
 * It compares failing test IDENTITIES between a base run and a head run. A failure present in
 * both is inherited and is NOT this branch's doing. A failure present only at head is new and
 * fails this gate. A failure present only at base was resolved. Counts are never compared --
 * a branch that fixes one test and breaks another keeps the same total, and that must fail.
 *
 * NOTHING HERE IS ACCEPTANCE. Inherited failures are reported as unresolved baseline drift on
 * every run, with a loud reminder that they remain defects. This is a temporary detection
 * mechanism that exists because the manifest is stale, and it should be deleted once the owner
 * has reconciled the manifest.
 *
 * Usage:
 *   php .github/scripts/regression-gate.php BASE_JUNIT HEAD_JUNIT [--manifest=PATH]
 *                                           [--json=PATH] [--official-exit=N] [--base-ref=SHA]
 *
 * Exit codes: 0 = no new failures. 1 = at least one new failure. 2 = the inputs are unusable.
 */

function fail_hard(string $message): never
{
    fwrite(STDERR, "regression-gate: {$message}\n");
    exit(2);
}

/** @return array{failures: array<string,string>, errors: array<string,string>, total: int} */
function read_junit(string $path): array
{
    if (! is_file($path)) {
        fail_hard("junit file not found: {$path}");
    }

    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($path);
    libxml_use_internal_errors($previous);

    if ($xml === false) {
        fail_hard("junit file is not parseable XML: {$path}");
    }

    $failures = [];
    $errors = [];
    $total = 0;

    foreach ($xml->xpath('//testcase') ?: [] as $case) {
        $total++;
        $class = (string) ($case['class'] ?? '');
        $name = (string) ($case['name'] ?? '');

        if ($class === '' || $name === '') {
            continue;
        }

        // PHPUnit appends a data-set suffix to the name for data providers; it is part of the
        // identity and is kept, because two data sets of one method fail independently.
        $id = $class . '::' . $name;

        if (isset($case->failure)) {
            $failures[$id] = trim((string) $case->failure);
        }

        if (isset($case->error)) {
            $errors[$id] = trim((string) $case->error);
        }
    }

    if ($total === 0) {
        fail_hard("junit file contains no testcases, which means the run did not happen: {$path}");
    }

    return ['failures' => $failures, 'errors' => $errors, 'total' => $total];
}

$argvRest = array_slice($argv, 1);
$positional = [];
$options = [];

foreach ($argvRest as $arg) {
    if (str_starts_with($arg, '--')) {
        [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '');
        $options[$k] = $v;
        continue;
    }
    $positional[] = $arg;
}

if (count($positional) < 2) {
    fail_hard('usage: regression-gate.php BASE_JUNIT HEAD_JUNIT [--manifest=] [--json=] [--official-exit=] [--base-ref=]');
}

[$basePath, $headPath] = $positional;
$base = read_junit($basePath);
$head = read_junit($headPath);

// Errors are never tolerated by the official gate and are not tolerated here either, but they
// are classified the same way so an inherited error is not misreported as this branch's.
$baseBad = $base['failures'] + $base['errors'];
$headBad = $head['failures'] + $head['errors'];

$accepted = [];
$manifestPath = $options['manifest'] ?? '.github/known-test-outcomes.json';

if (is_file($manifestPath)) {
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (is_array($manifest)) {
        // `expected_failures` is an OBJECT keyed by test id, whose values are the accepted
        // root causes -- not a list. Reading the values instead of the keys silently classified
        // every manifest-accepted failure as unaccepted drift, which is the one distinction
        // this gate exists to draw. Both shapes are handled so a future list does not regress it.
        foreach ($manifest['expected_failures'] ?? [] as $key => $entry) {
            $id = is_string($key) ? $key
                : (is_string($entry) ? $entry : ($entry['test'] ?? null));

            if (is_string($id) && $id !== '') {
                $accepted[$id] = true;
            }
        }
    }
}

$newFailures = array_diff_key($headBad, $baseBad);
$resolved = array_diff_key($baseBad, $headBad);
$inherited = array_intersect_key($headBad, $baseBad);

// Inherited failures split in two: the ones the owner has formally accepted in the manifest,
// and the ones nobody has -- the drift that makes the official gate red.
$inheritedAccepted = array_intersect_key($inherited, $accepted);
$inheritedDrift = array_diff_key($inherited, $accepted);

$officialExit = array_key_exists('official-exit', $options) && $options['official-exit'] !== ''
    ? (int) $options['official-exit']
    : null;

$result = [
    'schema' => 'storesyria.regression-gate/1',
    'base_ref' => $options['base-ref'] ?? null,
    'base_junit' => $basePath,
    'head_junit' => $headPath,
    'testcases' => ['base' => $base['total'], 'head' => $head['total']],
    'counts' => [
        'new' => count($newFailures),
        'resolved' => count($resolved),
        'inherited_accepted' => count($inheritedAccepted),
        'inherited_unaccepted_drift' => count($inheritedDrift),
    ],
    'new_failures' => array_keys($newFailures),
    'resolved_failures' => array_keys($resolved),
    'inherited_accepted_failures' => array_keys($inheritedAccepted),
    'inherited_unaccepted_drift' => array_keys($inheritedDrift),
    'official_gate' => [
        'script' => '.github/scripts/assert-known-test-outcomes.php',
        'manifest' => $manifestPath,
        'exit_code' => $officialExit,
        'status' => $officialExit === null ? 'not_run' : ($officialExit === 0 ? 'passing' : 'BLOCKED'),
        'note' => 'This gate does not substitute for the official one. A passing regression gate '
            . 'with a blocked official gate means: this branch introduced nothing new, AND the '
            . 'repository is still red.',
    ],
    'verdict' => $newFailures === [] ? 'no_new_failures' : 'NEW_FAILURES',
    'acceptance_note' => 'Inherited failures are NOT accepted by this gate. They are unresolved '
        . 'defects inherited from the merge base and are reported on every run.',
];

if (isset($options['json']) && $options['json'] !== '') {
    $dir = dirname($options['json']);
    if (! is_dir($dir)) {
        mkdir($dir, 0o775, true);
    }
    file_put_contents($options['json'], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

$line = str_repeat('-', 78);
echo "{$line}\n";
echo "MERGE-BASE REGRESSION GATE\n";
echo "{$line}\n";
printf("base            : %s  (%d testcases)\n", $options['base-ref'] ?? $basePath, $base['total']);
printf("head            : %s  (%d testcases)\n", $headPath, $head['total']);
printf("new failures    : %d\n", count($newFailures));
printf("resolved        : %d\n", count($resolved));
printf("inherited (accepted in manifest) : %d\n", count($inheritedAccepted));
printf("inherited (UNACCEPTED drift)     : %d\n", count($inheritedDrift));
echo "\n";

foreach ($newFailures as $id => $message) {
    echo "::error::NEW FAILURE introduced by this branch: {$id}\n";
    echo '    ' . substr(preg_replace('/\s+/', ' ', $message) ?? '', 0, 300) . "\n";
}

foreach ($resolved as $id => $_) {
    echo "resolved by this branch : {$id}\n";
}

foreach ($inheritedDrift as $id => $_) {
    echo "::warning::UNRESOLVED BASELINE DRIFT (present at the merge base, not in the manifest, "
        . "NOT accepted): {$id}\n";
}

foreach ($inheritedAccepted as $id => $_) {
    echo "inherited, accepted in manifest : {$id}\n";
}

echo "\n";

if ($officialExit === null) {
    echo "::warning::The official known-outcome gate was NOT run in this job, so its status is "
        . "unknown. Do not call CI green on the strength of this gate alone.\n";
} elseif ($officialExit !== 0) {
    echo "::error::THE OFFICIAL KNOWN-OUTCOME GATE IS STILL BLOCKED (exit {$officialExit}). Its "
        . "manifest is stale with respect to the merge base. CI IS NOT GREEN. This gate reports "
        . "only that the branch introduced nothing new.\n";
} else {
    echo "The official known-outcome gate passed.\n";
}

if ($inheritedDrift !== []) {
    echo "::warning::" . count($inheritedDrift) . " inherited failure(s) remain unresolved and "
        . "UNACCEPTED. This gate does not approve them and they must not be added to the "
        . "manifest to obtain green.\n";
}

echo "{$line}\n";

if ($newFailures !== []) {
    echo "VERDICT: FAILED -- this branch introduced " . count($newFailures) . " new failure(s).\n";
    exit(1);
}

echo "VERDICT: no new failures introduced by this branch.\n";
echo ($officialExit !== null && $officialExit !== 0)
    ? "OVERALL: NOT GREEN -- the official gate remains blocked.\n"
    : "";
exit(0);
