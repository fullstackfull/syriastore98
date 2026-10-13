#!/usr/bin/env bash
#
# COMPOSER LOCK INTEGRITY GUARD -- does vendor/ actually contain what composer.lock pins?
#
# WHY THIS EXISTS. Every test number this project has ever reported was produced by a
# vendor/ tree that does NOT match composer.lock. The drift was invisible for three
# phases because nothing compared the two: `composer validate` checks composer.json
# against composer.lock, and `composer install` is trusted to have done the rest. Nobody
# checked the third pair. Measured on p1e/agent5-qa, de8646b7:
#
#     239 packages locked, 235 installed
#      85 at the locked version
#     148 at a DIFFERENT version
#       6 locked but not installed
#       2 installed but absent from the lock
#       4 crossing a MAJOR version (phpseclib 3.0.42 -> 4.0.2,
#         firebase/php-jwt v6.10.2 -> v7.2.1, hamcrest v2.0.1 -> v3.0.0,
#         sabberworm/php-css-parser v8.7.0 -> v9.5.0)
#
# and the test runner itself differs: phpunit 11.4.4 locked, 11.5.57 installed. The
# framework under test is Laravel 11.57.0 while the lock pins 11.47.0, which is ten
# patch releases of behaviour the suite has never actually exercised.
#
# A green suite measured against the wrong dependency set does not prove the locked set
# is green. This guard makes that condition loud instead of silent.
#
# WHERE IT HAS TEETH. In the `tests` job, vendor/ is created by `composer install` from
# the lock moments earlier, so a mismatch there is a real defect -- a broken install, a
# cached workspace, or a lock edited without reinstalling. That is the only place this
# runs BLOCKING. In --report mode it prints the same measurement and exits 0, for use
# where vendor/ is known to be a shared, hardlinked, drifting tree that must not be
# touched (every agent worktree in this project).
#
# ------------------------------------------------------------------------------------------
# --allow-missing=<pkg>[,<pkg>...]  -- THE ONE NARROW TOLERANCE, AND WHY IT IS NARROW
# ------------------------------------------------------------------------------------------
# One locked package, lahirulhr/laravel-payhere, is gone from its upstream: the dist zipball
# composer.lock pins 404s from a clean runner because the commit no longer exists. For as long
# as that is true, `composer install` from the lock cannot succeed ON ANY MACHINE, and the
# consequence was that the REQUIRED test gate died at the install step and the Laravel suites
# NEVER EXECUTED in CI. The `tests-degraded` job exists to run the real suites anyway, from an
# install that excludes exactly that one package -- and it then has to measure the tree it
# produced against the real lock, which is what this mode is for.
#
# The tolerance is deliberately the smallest shape that works:
#
#   * It applies ONLY to the "locked, not installed" category. A version DIFFERENCE on a
#     tolerated package, or a tolerated package appearing in the tree but not the lock, is
#     still drift and still fails. The whole value of this guard is the exact version-string
#     match, and a tolerance that reached the differ/extra categories would throw that away.
#   * A package not in the allow list is still drift, in every category. One dead upstream does
#     not buy amnesty for a second.
#   * It changes NOTHING about the default mode, so the strict `tests` job -- which is what
#     gates the day the upstream returns -- is unaffected and still fails on this very package.
#   * It is passed in by the CALLER, so what is being tolerated is visible in the workflow file
#     and printed in this script's output, not buried in a constant here.
#
# When the owner decision on PayHere lands, delete the flag from the workflow and the
# `tests-degraded` job with it. Covered by tests/Unit/Dependency/ComposerLockIntegrityGuardTest.php,
# which proves each of the four "still fails" properties above.
#
# WHAT IT DELIBERATELY DOES NOT DO. It never edits the lock, never installs, never
# removes a package to make the numbers agree, and never treats "close enough" as equal.
# An exact version-string match is the whole point: the four major-version crossings
# above are exactly the kind of difference a fuzzy comparison would have hidden.
#
# Usage: guard-composer-lock-integrity.sh [repo-root] [--report|--allow-missing=<pkg>,...]
set -euo pipefail

ROOT="${1:-.}"
MODE="${2:-enforce}"
ALLOW_MISSING=""

case "${MODE}" in
  --allow-missing=*)
    ALLOW_MISSING="${MODE#--allow-missing=}"
    MODE="--allow-missing"
    if [ -z "${ALLOW_MISSING}" ]; then
      echo "::error::--allow-missing= was given with an empty list. Name the package(s) or drop the flag." >&2
      exit 1
    fi
    ;;
  --report|enforce) ;;
  *)
    echo "::error::Unknown mode '${MODE}'. Use --report or --allow-missing=<pkg>[,<pkg>...]." >&2
    exit 1
    ;;
esac

LOCK="$ROOT/composer.lock"
INSTALLED="$ROOT/vendor/composer/installed.json"

if [ ! -f "$LOCK" ]; then
  echo "::error::composer.lock not found at $LOCK"
  exit 1
fi

if [ ! -f "$INSTALLED" ]; then
  if [ "$MODE" = "--report" ]; then
    echo "NOTE: $INSTALLED absent (no vendor/ tree). Nothing to compare; reporting only."
    exit 0
  fi
  echo "::error::vendor/composer/installed.json not found at $INSTALLED."
  echo "::error::vendor/ is absent or was not produced by composer. Cannot verify lock integrity."
  exit 1
fi

# The comparison is done in PHP, not jq: PHP is guaranteed present wherever composer.lock
# matters, jq is not, and the lock is PHP's own data format.
php -r '
$lockPath = $argv[1];
$instPath = $argv[2];
$mode     = $argv[3] ?? "";
$report   = $mode === "--report";
$allowed  = [];
if ($mode === "--allow-missing") {
    foreach (explode(",", (string) ($argv[4] ?? "")) as $n) {
        $n = trim($n);
        if ($n !== "") { $allowed[$n] = true; }
    }
}

$lock = json_decode(file_get_contents($lockPath), true);
$inst = json_decode(file_get_contents($instPath), true);
if (!is_array($lock) || !is_array($inst)) {
    fwrite(STDERR, "::error::composer.lock or installed.json is not valid JSON.\n");
    exit(1);
}

$locked = [];
foreach (array_merge($lock["packages"] ?? [], $lock["packages-dev"] ?? []) as $p) {
    $locked[$p["name"]] = $p["version"];
}
$installed = [];
foreach ($inst["packages"] ?? [] as $p) {
    $installed[$p["name"]] = $p["version"];
}

$match = 0; $differ = []; $missing = []; $extra = []; $major = []; $tolerated = []; $unusedTolerance = [];
foreach ($locked as $name => $want) {
    if (!array_key_exists($name, $installed)) {
        # TOLERATED ABSENCE, and ONLY absence. A tolerated package that is present but at the
        # wrong version falls through to the $differ branch below and still fails -- the
        # tolerance never reaches the version comparison.
        if (isset($allowed[$name])) { $tolerated[$name] = $want; continue; }
        $missing[$name] = $want;
        continue;
    }
    if (isset($allowed[$name])) { $unusedTolerance[$name] = $installed[$name]; }
    $have = $installed[$name];
    if ($have === $want) { $match++; continue; }
    $differ[$name] = [$want, $have];
    $mw = explode(".", ltrim($want, "v"))[0];
    $mh = explode(".", ltrim($have, "v"))[0];
    if ($mw !== $mh) { $major[$name] = [$want, $have]; }
}
foreach ($installed as $name => $have) {
    if (!array_key_exists($name, $locked)) { $extra[$name] = $have; }
}

printf("composer.lock integrity\n");
printf("  locked packages          : %d\n", count($locked));
printf("  installed packages       : %d\n", count($installed));
printf("  at the locked version    : %d\n", $match);
printf("  at a DIFFERENT version   : %d\n", count($differ));
printf("  locked, not installed    : %d\n", count($missing));
printf("  installed, not in lock   : %d\n", count($extra));
printf("  crossing a MAJOR version : %d\n", count($major));
if ($allowed !== []) {
    printf("  TOLERATED absent         : %d  (allow-missing: %s)\n", count($tolerated), implode(", ", array_keys($allowed)));
}

$drift = count($differ) + count($missing) + count($extra);

if ($tolerated !== []) {
    printf("\nTOLERATED ABSENT -- declared dead upstream, excluded from the install ON PURPOSE.\n");
    printf("These packages are NOT installed. Nothing here says they work; every code path that\n");
    printf("needs their classes is still broken until an owner decision lands on the dependency.\n");
    foreach ($tolerated as $n => $v) { printf("  %-40s %s (locked, absent)\n", $n, $v); }
}

if ($unusedTolerance !== []) {
    printf("\nTOLERANCE UNUSED -- these are allow-listed as possibly-absent but ARE installed:\n");
    foreach ($unusedTolerance as $n => $v) { printf("  %-40s %s\n", $n, $v); }
    printf("If the upstream has returned, DELETE the --allow-missing flag and the degraded job.\n");
}

if ($drift > 0) {
    if ($major) {
        printf("\nMAJOR-VERSION CROSSINGS (behaviour under test is not the behaviour pinned):\n");
        foreach ($major as $n => $p) { printf("  %-40s locked %-16s installed %s\n", $n, $p[0], $p[1]); }
    }
    if ($missing) {
        printf("\nLOCKED BUT NOT INSTALLED:\n");
        foreach ($missing as $n => $v) { printf("  %-40s %s\n", $n, $v); }
    }
    if ($extra) {
        printf("\nINSTALLED BUT NOT IN THE LOCK:\n");
        foreach ($extra as $n => $v) { printf("  %-40s %s\n", $n, $v); }
    }
    # The full 148-line version table is noise in a CI log; the headline counts plus the
    # three categories above are what a reader acts on. Reproduce the full table with
    # the same PHP against composer.lock and vendor/composer/installed.json.
    printf("\n%d package(s) drift from composer.lock.\n", $drift);
}

if ($drift === 0) {
    if ($tolerated !== []) {
        printf("\nvendor/ matches composer.lock on EVERY package except the %d tolerated absence(s)\n", count($tolerated));
        printf("named above. This is NOT \"vendor/ matches composer.lock\": the installed set is the\n");
        printf("locked set MINUS those entries, deliberately and by name, and no other package\n");
        printf("differs by so much as a patch release.\n");
        exit(0);
    }
    printf("\nvendor/ matches composer.lock exactly.\n");
    exit(0);
}

if ($report) {
    printf("\nREPORT MODE: exiting 0. vendor/ here is a shared tree that must not be reinstalled.\n");
    printf("This measurement is the reason no suite result from this tree proves the LOCKED set green.\n");
    exit(0);
}

fwrite(STDERR, "::error::vendor/ does not match composer.lock. A suite run against this tree does not verify the locked dependency set.\n");
exit(1);
' "$LOCK" "$INSTALLED" "$MODE" "$ALLOW_MISSING"
