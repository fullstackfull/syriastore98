#!/usr/bin/env php
<?php

/**
 * SCREEN A FAILED `composer install`: WHICH PACKAGES ACTUALLY FAILED?
 * =============================================================================================
 *
 * WHAT PROBLEM THIS SOLVES. `composer install` from this repository's lock cannot succeed on
 * any machine, because ONE locked package is gone from its upstream: lahirulhr/laravel-payhere
 * pins a dist zipball at a commit that no longer exists (HTTP 404 from a clean ubuntu-latest
 * runner; a 403 would have meant an egress policy, a 404 means the commit is gone). The
 * consequence until now was that the REQUIRED `tests` job died at the install step and the
 * Laravel suites had NEVER executed in CI -- so the required gate protected nothing.
 *
 * The obvious reaction is to make the install non-fatal. That is the wrong reaction, and it is
 * the reaction this project has already been burnt by elsewhere (`continue-on-error` on the
 * Feature suite). `|| true` on an install tolerates EVERY dependency failure forever: the day a
 * second package dies, or the day the lock is edited into an uninstallable state, the pipeline
 * says nothing.
 *
 * So this script converts "the install failed" into "the install failed, and here is exactly
 * which packages failed". A caller may then proceed if, and only if, that set is EXACTLY the set
 * it has declared dead in writing. Any other package -- or a non-zero exit with no identifiable
 * package failure at all, which means something else broke -- is a hard stop.
 *
 * HOW A PACKAGE IS IDENTIFIED. Two independent routes, unioned, because composer reports the
 * same event in two different shapes depending on where it gives up:
 *
 *   1. BY NAME. The downloader's own message names the package:
 *        Failed to download lahirulhr/laravel-payhere from dist: "..." could not be downloaded
 *      Also covered: "Could not find package x/y", "Package x/y has no ... version", and the
 *      `- Downloading x/y (v1)` / `- Installing x/y (v1)` progress lines when the SAME line
 *      carries a failure marker.
 *
 *   2. BY URL, RESOLVED THROUGH composer.lock. When composer rethrows as a TransportException
 *      the block names only the URL and NOT the package:
 *        In TransportException.php line 110:
 *          The "https://api.github.com/repos/.../zipball/72ce0aa8..." file could not be
 *          downloaded (HTTP/2 404)
 *      Every URL found next to a failure marker is matched against every locked package's
 *      dist.url and source.url. This is why composer.lock is an argument: the mapping is only
 *      derivable from the lock, and deriving it there means a renamed or re-sourced package
 *      cannot be silently folded into the existing tolerance.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It does not install anything, does not edit composer.json
 * or composer.lock (it opens the lock read-only), does not decide whether the tolerance is
 * acceptable -- the tolerated set is passed in by the caller, so it is visible in the workflow
 * file and in this script's output rather than buried here -- and it never passes on a log it
 * could not interpret.
 *
 * Covered by tests/Unit/Dependency/InstallFailureScreenTest.php, which feeds it real composer
 * output shapes with known verdicts, including a second dead package and a resolver failure
 * that names no package at all.
 *
 * Usage: screen-install-failure.php <install.log> <composer.lock> <tolerated-pkg>[,<pkg>...]
 * Exit:  0 = the set of packages that failed is EXACTLY the tolerated set
 *        1 = it is not, or the log could not be interpreted
 */

$argv0 = $argv[0] ?? 'screen-install-failure.php';

if ($argc < 4) {
    fwrite(STDERR, "usage: {$argv0} <install.log> <composer.lock> <tolerated-pkg>[,<pkg>...]\n");
    exit(1);
}

$logPath   = $argv[1];
$lockPath  = $argv[2];
$tolerated = array_values(array_filter(array_map('trim', explode(',', (string) $argv[3])), 'strlen'));

$error = static fn (string $line): int => fwrite(STDERR, '::error::' . $line . "\n");
$notice = static fn (string $line): int => fwrite(STDOUT, $line . "\n");

if ($tolerated === []) {
    $error('No tolerated package was named. This screen exists to permit an EXPLICIT, named');
    $error('exception; with an empty list there is nothing to permit and the caller should');
    $error('simply let the install failure stand.');
    exit(1);
}

sort($tolerated);

if (! is_file($logPath)) {
    $error("Install log not found at {$logPath}. Refusing to judge an install it cannot see.");
    exit(1);
}

$log = (string) file_get_contents($logPath);

if (trim($log) === '') {
    $error("Install log at {$logPath} is empty. `composer install` exited non-zero without");
    $error('writing a word, which is not the known dead-upstream failure and needs a human.');
    exit(1);
}

if (! is_file($lockPath)) {
    $error("composer.lock not found at {$lockPath}. The URL-to-package mapping cannot be built,");
    $error('so a TransportException naming only a URL could not be attributed. Refusing to pass.');
    exit(1);
}

$lock = json_decode((string) file_get_contents($lockPath), true);

if (! is_array($lock)) {
    $error("composer.lock at {$lockPath} is not valid JSON.");
    exit(1);
}

// -------------------------------------------------------------------------------------------
// Build url => package name from the lock. Both dist and source, both sections.
// -------------------------------------------------------------------------------------------
$urlToPackage = [];
$lockedNames  = [];

foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
    $name = (string) ($package['name'] ?? '');

    if ($name === '') {
        continue;
    }

    $lockedNames[$name] = true;

    foreach (['dist', 'source'] as $section) {
        $url = $package[$section]['url'] ?? null;

        if (is_string($url) && $url !== '') {
            $urlToPackage[$url] = $name;
        }
    }
}

// -------------------------------------------------------------------------------------------
// Markers that mean "this line is about something going wrong". A package name or URL is only
// attributed when it shares a line with one of these, so the ordinary progress chatter
// (`- Downloading x/y`, `- Installing x/y`) is not read as a failure.
// -------------------------------------------------------------------------------------------
$failureMarkers = [
    'failed to download',
    'could not be downloaded',
    'could not be fetched',
    'could not find package',
    'could not be resolved',
    'no matching package found',
    'has no version',
    'file could not be downloaded',
    'the requested url returned error',
    'install of',           // "Install of x/y failed"
    'failed to extract',
    'invalid credentials',
    'authentication required',
    'checksum verification',
    'hash verification',
    'transportexception',
    'curl error',
];

$isFailureLine = static function (string $line) use ($failureMarkers): bool {
    $lower = strtolower($line);

    foreach ($failureMarkers as $marker) {
        if (str_contains($lower, $marker)) {
            return true;
        }
    }

    return false;
};

$implicated   = [];
$evidence     = [];
$failureLines = [];

foreach (preg_split('/\R/', $log) ?: [] as $line) {
    if (! $isFailureLine($line)) {
        continue;
    }

    $failureLines[] = rtrim($line);

    // 1. BY NAME. A composer package name is vendor/name, lowercase, with a restricted
    //    character set. Anchoring on that shape rather than on one message's wording keeps
    //    this working across composer's several phrasings.
    if (preg_match_all('#(?<![\w./-])([a-z0-9]([a-z0-9._-]*[a-z0-9])?/[a-z0-9]([a-z0-9._-]*[a-z0-9])?)(?![\w/-])#', $line, $matches)) {
        foreach ($matches[1] as $candidate) {
            // Only accept something the lock actually knows. Without this, a path fragment or
            // a host/path pair in a message would be read as a package.
            if (isset($lockedNames[$candidate])) {
                $implicated[$candidate] = true;
                $evidence[$candidate]['by name'] = true;
            }
        }
    }

    // 2. BY URL, resolved through the lock.
    if (preg_match_all('#https?://[^\s"\'<>()\]]+#', $line, $urlMatches)) {
        foreach ($urlMatches[0] as $url) {
            $url = rtrim($url, '.,;:');

            if (isset($urlToPackage[$url])) {
                $name = $urlToPackage[$url];
                $implicated[$name] = true;
                $evidence[$name]['by url'] = $url;

                continue;
            }

            // A zipball URL in the log can carry a suffix or differ in trailing punctuation,
            // so also accept a locked URL that is a prefix of what was logged.
            foreach ($urlToPackage as $lockedUrl => $name) {
                if ($lockedUrl !== '' && str_starts_with($url, $lockedUrl)) {
                    $implicated[$name] = true;
                    $evidence[$name]['by url'] = $lockedUrl;

                    break;
                }
            }
        }
    }
}

$implicatedNames = array_keys($implicated);
sort($implicatedNames);

$notice('INSTALL FAILURE SCREEN');
$notice(str_repeat('-', 78));
$notice(sprintf('install log          : %s', $logPath));
$notice(sprintf('lock                 : %s (%d locked packages)', $lockPath, count($lockedNames)));
$notice(sprintf('tolerated set        : %s', implode(', ', $tolerated)));
$notice(sprintf('failure lines seen   : %d', count($failureLines)));
$notice(sprintf('packages implicated  : %s', $implicatedNames === [] ? '(none identified)' : implode(', ', $implicatedNames)));
$notice('');

foreach ($implicatedNames as $name) {
    $how = [];

    if (isset($evidence[$name]['by name'])) {
        $how[] = 'named in the message';
    }

    if (isset($evidence[$name]['by url'])) {
        $how[] = 'URL from composer.lock: ' . $evidence[$name]['by url'];
    }

    $notice(sprintf('  %-40s %s', $name, implode('; ', $how)));
}

$notice('');
$notice('The failure lines this verdict rests on:');

foreach (array_slice($failureLines, 0, 40) as $line) {
    $notice('  | ' . substr(preg_replace('/\s+/', ' ', $line) ?? '', 0, 200));
}

if (count($failureLines) > 40) {
    $notice(sprintf('  | ... %d more', count($failureLines) - 40));
}

$notice('');

// -------------------------------------------------------------------------------------------
// VERDICT. Equality, not containment, in both directions.
// -------------------------------------------------------------------------------------------
if ($implicatedNames === []) {
    $error('`composer install` failed and NO locked package could be identified as the cause.');
    $error('That is not the known dead-upstream failure. It is a resolver failure, a platform');
    $error('requirement, a corrupt lock, a disk or network fault, or a composer change -- all of');
    $error('which need a human. Refusing to proceed to the degraded install: a tolerance that');
    $error('fires on an unattributable failure tolerates everything.');
    exit(1);
}

$unexpected = array_values(array_diff($implicatedNames, $tolerated));
$absent     = array_values(array_diff($tolerated, $implicatedNames));

// BOTH HALVES ARE REPORTED BEFORE EXITING, not just the first one found. The two findings mean
// different things and a reader needs both: "something new broke" and "the thing we have on
// record as broken did not break this time" are separate pieces of news, and printing only the
// first would send someone to investigate a new package while the stale tolerance that should
// have been deleted stays invisible.
$failed = false;

if ($unexpected !== []) {
    foreach ($unexpected as $name) {
        $error('UNDECLARED DEPENDENCY FAILURE: ' . $name);
    }

    $error('');
    $error('Only the packages named in the tolerated set may fail. A NEW package failing to');
    $error('install is a NEW finding and must not be filed under the existing dead upstream.');
    $error('Do NOT add it to the tolerated list to get green: investigate it, and if it is also');
    $error('dead upstream that is an owner decision about the dependency, recorded in');
    $error('docs/BUILD-REPRODUCIBILITY.md, not a workflow edit.');
    $failed = true;
}

if ($absent !== []) {
    foreach ($absent as $name) {
        $error('TOLERATED PACKAGE DID NOT FAIL: ' . $name);
    }

    $error('');
    $error('The install failed, but not on the package this tolerance exists for. Either the');
    $error('dead upstream has returned (good news -- delete the tolerance and the degraded job)');
    $error('or the failure moved. Either way the recorded cause is now wrong. Refusing to pass');
    $error('a screen whose own premise no longer holds.');
    $failed = true;
}

if ($failed) {
    exit(1);
}

$notice('OK: every package that failed to install is in the declared tolerated set, and every');
$notice('tolerated package did in fact fail. The caller may proceed with an install that');
$notice('excludes exactly these packages -- and NOTHING about this is a statement that they');
$notice('work. They are absent. The code paths that need them are broken until an owner');
$notice('decision lands on the dependency itself.');

exit(0);
