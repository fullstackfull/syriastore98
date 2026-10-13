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
# WHAT IT DELIBERATELY DOES NOT DO. It never edits the lock, never installs, never
# removes a package to make the numbers agree, and never treats "close enough" as equal.
# An exact version-string match is the whole point: the four major-version crossings
# above are exactly the kind of difference a fuzzy comparison would have hidden.
#
# Usage: guard-composer-lock-integrity.sh [repo-root] [--report]
set -euo pipefail

ROOT="${1:-.}"
MODE="${2:-enforce}"

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
$report   = ($argv[3] ?? "") === "--report";

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

$match = 0; $differ = []; $missing = []; $extra = []; $major = [];
foreach ($locked as $name => $want) {
    if (!array_key_exists($name, $installed)) { $missing[$name] = $want; continue; }
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

$drift = count($differ) + count($missing) + count($extra);

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
' "$LOCK" "$INSTALLED" "$MODE"
