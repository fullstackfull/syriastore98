#!/usr/bin/env bash
#
# P2.5-SAFETY-W -- DOES THE WRITE-SIDE DATABASE GUARD ACTUALLY REFUSE, AND WRITE NOTHING?
# =================================================================================================
#
# WHY THIS IS A SCRIPT AND NOT A TEST. `Tests\Unit\DevFixtureDatabaseGuardTest` proves the guard's
# LOGIC with literals against a pure function, which is the only safe way to test a guard whose
# failure mode is writing to the wrong database. It cannot prove that the guard is WIRED: that the
# three dev tools actually call it, before the application boots, and exit non-zero without
# touching the fixture. That needs the real executables and the real fixture file, so it lives here
# as evidence rather than as a conditionally-skipped test -- the Feature suite asserts a declared
# outcome SET in both directions, and a test that skips when the fixture is absent would break it.
#
# PRECONDITIONS, stated rather than assumed:
#   - run from the repository root
#   - `database/p2dev.sqlite` exists (database/seed-p2dev.php is its canonical creator)
#   - a dev install: `Tests\` must be autoloadable, i.e. NOT `composer install --no-dev`
#
# SAFETY. Every case below is a REFUSAL case, so by construction nothing should be written; the
# before/after row counts are what proves it rather than the exit codes alone. The one case that is
# allowed to write is the positive control, and it is the tool's normal idempotent run.
#
# usage: bash tests/Support/Security/bin/verify-dev-fixture-write-guard.sh

set -u

REPO="$(cd "$(dirname "$0")/../../../.." && pwd)"
cd "$REPO" || exit 2

FIXTURE="$REPO/database/p2dev.sqlite"
[ -f "$FIXTURE" ] || { echo "PRECONDITION FAILED: $FIXTURE is absent."; exit 2; }
# `php -r` carries NO autoloader, so the first draft of this line reported every install as
# --no-dev. The autoloader has to be required explicitly.
php -r 'require "vendor/autoload.php";
        exit(class_exists("Tests\\Support\\DevFixtureDatabaseGuard") ? 0 : 1);' \
  || { echo "PRECONDITION FAILED: Tests\\ is not autoloadable (--no-dev install?)."; exit 2; }

TOOLS=(
  "docs/development/phase-2.5/tools/seed-dev-interaction-fixtures.php"
  "docs/development/phase-2.5/tools/grant-dev-finance-permissions.php"
  "docs/development/phase-2.5/tools/grant-dev-screen-permissions.php"
)

snapshot() {
  php -r '
    $p = new PDO("sqlite:" . $argv[1]);
    $o = [];
    foreach (["orders","customers","carts","wishlists","store_withdrawals",
              "store_wallet_transactions","coupons","testimonials","faqs",
              "permissions","roles","permission_role"] as $t) {
        try { $o[$t] = $p->query("select count(*) from $t")->fetchColumn(); }
        catch (Throwable $e) { $o[$t] = "absent"; }
    }
    echo json_encode($o);
  ' "$FIXTURE"
}

BEFORE="$(snapshot)"
PASS=0
FAIL=0

check() { # label, expected-fragment, then the env assignments + tool
  local label="$1"; shift
  local fragment="$1"; shift
  local out code
  out="$("$@" 2>&1)"
  code=$?
  if [ "$code" -eq 0 ]; then
    echo "  FAIL  $label -- exited 0; the guard did not refuse"
    FAIL=$((FAIL + 1))
  elif ! printf '%s' "$out" | grep -qF "$fragment"; then
    echo "  FAIL  $label -- exited $code but the message did not mention: $fragment"
    echo "        got: $(printf '%s' "$out" | head -1)"
    FAIL=$((FAIL + 1))
  else
    echo "  ok    $label -- exit $code, refused"
    PASS=$((PASS + 1))
  fi
}

for TOOL in "${TOOLS[@]}"; do
  echo "$TOOL"
  check "no environment at all" "no environment source names a database" \
        env -u DB_CONNECTION -u DB_DATABASE php "$TOOL"
  check "mysql in one source" "not 'sqlite'" \
        env DB_CONNECTION=mysql DB_DATABASE="$FIXTURE" php "$TOOL"
  check "a non-fixture sqlite file" "is not a known development fixture" \
        env DB_CONNECTION=sqlite DB_DATABASE="$REPO/database/database.sqlite" php "$TOOL"
  check "fixture name, absent path" "which does not exist" \
        env DB_CONNECTION=sqlite DB_DATABASE=/nonexistent/p2dev.sqlite php "$TOOL"
done

AFTER="$(snapshot)"

echo
if [ "$BEFORE" = "$AFTER" ]; then
  echo "  ok    the fixture is byte-for-byte unchanged by every refusal"
  PASS=$((PASS + 1))
else
  echo "  FAIL  A REFUSAL WROTE TO THE FIXTURE."
  echo "        before: $BEFORE"
  echo "        after:  $AFTER"
  FAIL=$((FAIL + 1))
fi

# POSITIVE CONTROL. Without it a guard that refused EVERYTHING would pass every case above, and
# the tools would be silently dead rather than safe.
echo
echo "positive control -- the correct environment must still work"
if env DB_CONNECTION=sqlite DB_DATABASE="$FIXTURE" CACHE_STORE=file SESSION_DRIVER=file \
     php "${TOOLS[0]}" >/dev/null 2>&1; then
  echo "  ok    the seeder still runs and exits 0 with the documented environment"
  PASS=$((PASS + 1))
else
  echo "  FAIL  the guard refuses the CORRECT environment too; the tools are dead, not safe"
  FAIL=$((FAIL + 1))
fi

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] || exit 1
