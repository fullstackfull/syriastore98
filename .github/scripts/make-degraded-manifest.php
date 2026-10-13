#!/usr/bin/env php
<?php

/**
 * DERIVE A SIDE MANIFEST THAT EXCLUDES NAMED PACKAGES -- WITHOUT TOUCHING composer.json/lock.
 * =============================================================================================
 *
 * WHAT THIS IS FOR. One locked package, lahirulhr/laravel-payhere, is gone from its upstream, so
 * `composer install` from composer.lock fails and the REQUIRED test gate has never run a single
 * Laravel test. The suites themselves do not need that package -- measured: the development tree
 * that produced the current board (Unit 107, Feature 1255) has no vendor/lahirulhr at all. What
 * CI lacks is not a capability but a narrow, auditable tolerance.
 *
 * WHY A SIDE MANIFEST AND NOT AN EDIT. The remedy must NOT be "remove the package from
 * composer.json/composer.lock":
 *
 *   * The lock is owned by the dependency agent, and editing it here would collide.
 *   * More importantly, dropping the package from the real manifests is the FORBIDDEN remedy on
 *     its own merits. Five files `use Lahirulhr\PayHere\PayHere`
 *     (app/Services/PaymentService.php:46 and :3486, app/Http/Controllers/PayHerePaymnetController.php:12
 *     and :92, and the settings readers in app/Models/Utility.php and app/Models/Setting.php),
 *     so a lock edit alone converts a recoverable upstream outage into a fatal at class load and
 *     reads, in the diff, as "the dependency problem is resolved". It is not resolved.
 *   * A committed exclusion is permanent by default. A manifest derived at build time, from the
 *     committed one, every run, is not: the day the upstream returns, the strict install
 *     succeeds, this script is never called, and the tolerance evaporates with no commit.
 *
 * So composer is pointed at a DERIVED PAIR via its `COMPOSER` environment variable
 * (`COMPOSER=composer-ci-degraded.json composer install`, whose lock filename composer derives
 * from the json filename). The committed composer.json and composer.lock are opened read-only
 * and the caller is expected to verify their checksums afterwards.
 *
 * THE DERIVED LOCK IS NOT A RESOLUTION. It is the committed lock minus whole entries. No version
 * is changed, no constraint is relaxed, nothing is added. The caller then measures the installed
 * tree against the REAL committed lock, tolerating only the named packages as absent, so the
 * difference between what ran and what is pinned stays a measured, printed quantity rather than
 * an assumption.
 *
 * WHY THE ROOT REQUIRE MUST GO TOO. `composer install` from a lock is not a blind unpack:
 * composer solves the locked repository against the root requirements to order the installs and
 * to verify the lock satisfies them. Leaving `"lahirulhr/laravel-payhere": "^1.0"` in the derived
 * json while removing it from the derived lock therefore fails with "Your lock file does not
 * contain a compatible set of packages". Removing it from both changes the derived json's
 * content-hash, so composer prints its "lock file is not up to date" warning -- `install` warns
 * and proceeds, which is why the caller asserts the exit status rather than the absence of
 * warnings.
 *
 * REFUSALS, all of which exist because each would make the result a lie:
 *   * an empty exclusion list                     -- nothing to do; the caller should not be here
 *   * an output basename of "composer"            -- would overwrite the committed manifests
 *   * a named package absent from the lock        -- the exclusion is stale or misspelled, and a
 *                                                    silent no-op would leave the caller thinking
 *                                                    a package was excluded when it never was
 *   * a named package required by ANOTHER locked
 *     package                                     -- dropping it would break that package's own
 *                                                    requirements, so the degraded set would
 *                                                    differ from the lock by more than the names
 *                                                    given
 *   * a post-write count that is not exactly
 *     count(excluded) smaller                     -- the filter did something other than what it
 *                                                    says
 *
 * Covered by tests/Unit/Dependency/DegradedManifestBuilderTest.php.
 *
 * Usage: make-degraded-manifest.php <repo-root> <output-basename> <pkg>[,<pkg>...]
 * Exit:  0 = <output-basename>.json and <output-basename>.lock written, excluding exactly <pkg>s
 *        1 = refused, for one of the reasons above
 */

$argv0 = $argv[0] ?? 'make-degraded-manifest.php';

if ($argc < 4) {
    fwrite(STDERR, "usage: {$argv0} <repo-root> <output-basename> <pkg>[,<pkg>...]\n");
    exit(1);
}

$root     = rtrim($argv[1], '/');
$basename = trim($argv[2]);
$exclude  = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $argv[3])), 'strlen')));

$error  = static fn (string $line): int => fwrite(STDERR, '::error::' . $line . "\n");
$notice = static fn (string $line): int => fwrite(STDOUT, $line . "\n");

if ($exclude === []) {
    $error('No package to exclude was named. This script only ever removes an EXPLICIT, named');
    $error('set; with an empty set the derived manifest would be identical to the committed one');
    $error('and the caller should use the committed one directly.');
    exit(1);
}

sort($exclude);

// -------------------------------------------------------------------------------------------
// REFUSE TO WRITE OVER THE COMMITTED MANIFESTS. This is the single most important refusal in
// the file: the whole point of a derived pair is that composer.json and composer.lock are not
// touched, and a caller passing "composer" here -- by typo or by someone "simplifying" the
// workflow -- would silently destroy that property.
// -------------------------------------------------------------------------------------------
if ($basename === '' || $basename === 'composer' || str_contains($basename, '/') || str_contains($basename, '\\')) {
    $error("Refusing output basename '{$basename}'.");
    $error('It must be a plain filename in the repository root and must not be "composer":');
    $error('this script must never be able to overwrite the committed composer.json or');
    $error('composer.lock. Those belong to the dependency owner and a build must not edit them.');
    exit(1);
}

$jsonPath = $root . '/composer.json';
$lockPath = $root . '/composer.lock';

foreach ([$jsonPath, $lockPath] as $path) {
    if (! is_file($path)) {
        $error("Not found: {$path}");
        exit(1);
    }
}

$jsonRaw = (string) file_get_contents($jsonPath);
$lockRaw = (string) file_get_contents($lockPath);

$json = json_decode($jsonRaw, true);
$lock = json_decode($lockRaw, true);

if (! is_array($json) || ! is_array($lock)) {
    $error('composer.json or composer.lock is not valid JSON.');
    exit(1);
}

$sourceJsonHash = hash('sha256', $jsonRaw);
$sourceLockHash = hash('sha256', $lockRaw);

// -------------------------------------------------------------------------------------------
// Every named package must actually be in the lock, and must not be required by another
// locked package.
// -------------------------------------------------------------------------------------------
$lockedBySection = [
    'packages'     => $lock['packages'] ?? [],
    'packages-dev' => $lock['packages-dev'] ?? [],
];

$lockedNames = [];

foreach ($lockedBySection as $packages) {
    foreach ($packages as $package) {
        $name = (string) ($package['name'] ?? '');

        if ($name !== '') {
            $lockedNames[$name] = $package;
        }
    }
}

$missing = array_values(array_diff($exclude, array_keys($lockedNames)));

if ($missing !== []) {
    foreach ($missing as $name) {
        $error('NOT IN THE LOCK: ' . $name);
    }

    $error('');
    $error('An exclusion for a package the lock does not contain is either stale -- the owner');
    $error('decision landed and the package is gone, so the tolerance must be deleted -- or');
    $error('misspelled, in which case the real package would be installed and the exclusion');
    $error('would do nothing while appearing to work. Refusing either way.');
    exit(1);
}

$requiredByOthers = [];

foreach ($lockedNames as $name => $package) {
    if (in_array($name, $exclude, true)) {
        continue;
    }

    foreach (['require', 'require-dev'] as $linkType) {
        foreach (($package[$linkType] ?? []) as $target => $constraint) {
            if (in_array((string) $target, $exclude, true)) {
                $requiredByOthers[] = sprintf('%s requires %s (%s) via %s', $name, $target, $constraint, $linkType);
            }
        }
    }
}

if ($requiredByOthers !== []) {
    foreach ($requiredByOthers as $line) {
        $error('STILL REQUIRED BY A LOCKED PACKAGE: ' . $line);
    }

    $error('');
    $error('Excluding a package another locked package depends on does not produce "the locked');
    $error('set minus one entry" -- it produces an unsatisfiable set, and composer would either');
    $error('refuse it or the dependent would break at runtime. The difference between what runs');
    $error('and what is pinned has to stay exactly the packages named here, so this is refused.');
    exit(1);
}

// -------------------------------------------------------------------------------------------
// Filter. Entries are REMOVED WHOLE; nothing is rewritten.
// -------------------------------------------------------------------------------------------
$derivedLock = $lock;
$removed     = [];

foreach (['packages', 'packages-dev'] as $section) {
    if (! isset($derivedLock[$section]) || ! is_array($derivedLock[$section])) {
        continue;
    }

    $kept = [];

    foreach ($derivedLock[$section] as $package) {
        $name = (string) ($package['name'] ?? '');

        if ($name !== '' && in_array($name, $exclude, true)) {
            $removed[$name] = [
                'section' => $section,
                'version' => (string) ($package['version'] ?? '?'),
                'dist'    => (string) ($package['dist']['url'] ?? ''),
            ];

            continue;
        }

        $kept[] = $package;
    }

    $derivedLock[$section] = array_values($kept);
}

// The root requires must go too, or the solver refuses the derived lock. See the header.
$derivedJson     = $json;
$removedRequires = [];

foreach (['require', 'require-dev'] as $linkType) {
    if (! isset($derivedJson[$linkType]) || ! is_array($derivedJson[$linkType])) {
        continue;
    }

    foreach ($exclude as $name) {
        if (array_key_exists($name, $derivedJson[$linkType])) {
            $removedRequires[] = sprintf('%s: %s (%s)', $name, $derivedJson[$linkType][$name], $linkType);
            unset($derivedJson[$linkType][$name]);
        }
    }
}

// -------------------------------------------------------------------------------------------
// Prove the filter did exactly what it claims before anything is written.
// -------------------------------------------------------------------------------------------
$countBefore = count($lock['packages'] ?? []) + count($lock['packages-dev'] ?? []);
$countAfter  = count($derivedLock['packages'] ?? []) + count($derivedLock['packages-dev'] ?? []);

if ($countBefore - $countAfter !== count($exclude)) {
    $error(sprintf(
        'The filter removed %d locked entries but %d packages were named.',
        $countBefore - $countAfter,
        count($exclude)
    ));
    $error('A duplicate lock entry or a filter defect would produce this. Refusing to write a');
    $error('manifest whose contents do not match what this script says it did.');
    exit(1);
}

$namesBefore = array_keys($lockedNames);
$namesAfter  = [];

foreach (['packages', 'packages-dev'] as $section) {
    foreach ($derivedLock[$section] ?? [] as $package) {
        $namesAfter[] = (string) ($package['name'] ?? '');
    }
}

$actuallyGone = array_values(array_diff($namesBefore, $namesAfter));
sort($actuallyGone);

if ($actuallyGone !== $exclude) {
    $error('The set of packages the derived lock lost is not the set that was named.');
    $error('  named : ' . implode(', ', $exclude));
    $error('  gone  : ' . implode(', ', $actuallyGone));
    exit(1);
}

$outJson = $root . '/' . $basename . '.json';
$outLock = $root . '/' . $basename . '.lock';

if (realpath($outJson) === realpath($jsonPath) || realpath($outLock) === realpath($lockPath)) {
    $error('The derived output would resolve to the committed manifests. Refusing.');
    exit(1);
}

$encode = static fn (array $data): string => (string) json_encode(
    $data,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) . "\n";

if (file_put_contents($outJson, $encode($derivedJson)) === false) {
    $error("Could not write {$outJson}");
    exit(1);
}

if (file_put_contents($outLock, $encode($derivedLock)) === false) {
    $error("Could not write {$outLock}");
    exit(1);
}

// The committed manifests must be byte-identical to how they were found. The caller checks
// this too, from outside; both checks are cheap and the property is the entire premise.
if (hash('sha256', (string) file_get_contents($jsonPath)) !== $sourceJsonHash
    || hash('sha256', (string) file_get_contents($lockPath)) !== $sourceLockHash) {
    $error('composer.json or composer.lock CHANGED while this script ran. Refusing to report');
    $error('success: the premise of the derived manifest is that the committed pair is read-only.');
    exit(1);
}

$notice('DEGRADED MANIFEST -- A BUILD-TIME EXCLUSION, NOT A DEPENDENCY FIX');
$notice(str_repeat('-', 78));
$notice(sprintf('read      : %s (sha256 %s)', $jsonPath, substr($sourceJsonHash, 0, 16)));
$notice(sprintf('read      : %s (sha256 %s)', $lockPath, substr($sourceLockHash, 0, 16)));
$notice(sprintf('wrote     : %s', $outJson));
$notice(sprintf('wrote     : %s', $outLock));
$notice(sprintf('locked    : %d entries -> %d entries', $countBefore, $countAfter));
$notice('');
$notice('EXCLUDED, whole entries, no version or constraint altered:');

foreach ($removed as $name => $meta) {
    $notice(sprintf('  %-40s %-12s [%s]', $name, $meta['version'], $meta['section']));

    if ($meta['dist'] !== '') {
        $notice(sprintf('    dist that cannot be fetched: %s', $meta['dist']));
    }
}

$notice('');
$notice('ROOT REQUIREMENTS DROPPED from the derived json (composer solves the locked repository');
$notice('against the root requires even on `install`, so leaving these would fail outright):');

foreach ($removedRequires as $line) {
    $notice('  ' . $line);
}

$notice('');
$notice('THE COMMITTED composer.json AND composer.lock WERE NOT MODIFIED. The packages above are');
$notice('ABSENT from the tree this install produces. Every code path that needs their classes is');
$notice('still broken, and that is the honest state until an owner decision lands on the');
$notice('dependency. This exclusion exists so the test suites can run at all -- it is not, and');
$notice('must never be reported as, a fix.');

exit(0);
