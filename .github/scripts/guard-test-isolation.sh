#!/usr/bin/env bash
#
# Fails if the test-database isolation wiring has been weakened.
#
# WHAT THIS PROTECTS. RefreshDatabase runs `migrate:fresh`, which DROPS EVERY TABLE in
# whatever database the suite is pointed at. Increment 1 pinned the suite to
# sqlite :memory: in two places. If either is removed, commented out, or re-pointed, a
# single `vendor/bin/phpunit` run can destroy a developer's -- or worse, a shared --
# MySQL database. That is a one-keystroke, unrecoverable mistake, so it is guarded in CI
# rather than left to review.
#
# Checked:
#   1. phpunit.xml sets bootstrap="tests/bootstrap.php"      (Layer 0, the primary control)
#   2. phpunit.xml forces DB_CONNECTION=sqlite   with force="true"
#   3. phpunit.xml forces DB_DATABASE=":memory:" with force="true"
#   4. tests/bootstrap.php and tests/Support/TestDatabaseGuard.php still exist
#   5. no uncommented DB_CONNECTION pointing at anything but sqlite
#
# Comment-awareness matters: `<!-- <env name="DB_CONNECTION" value="sqlite" ... > -->`
# satisfies a naive grep while doing nothing at all. XML comments are stripped before
# the content checks.
#
# Usage: .github/scripts/guard-test-isolation.sh [repo-root]
set -euo pipefail

ROOT="${1:-.}"
CONFIG="${ROOT}/phpunit.xml"
FAILED=0

fail() {
    printf '  FAIL: %s\n' "$1" >&2
    FAILED=1
}

printf 'Guard: test-database isolation\n'

for required in \
    "${CONFIG}" \
    "${ROOT}/tests/bootstrap.php" \
    "${ROOT}/tests/Support/TestDatabaseGuard.php"
do
    if [[ ! -f "${required}" ]]; then
        fail "missing ${required#"${ROOT}"/} -- the isolation harness is incomplete."
    fi
done

if [[ -f "${CONFIG}" ]]; then
    # Strip XML comments so a commented-out override cannot satisfy these checks.
    STRIPPED="$(perl -0777 -pe 's/<!--.*?-->//gs' "${CONFIG}")"

    if ! grep -Eq 'bootstrap[[:space:]]*=[[:space:]]*"tests/bootstrap\.php"' <<<"${STRIPPED}"; then
        fail 'phpunit.xml does not set bootstrap="tests/bootstrap.php". Layer 0 is the only
        guard that runs before RefreshDatabase can issue migrate:fresh.'
    fi

    if ! grep -Eq '<env[[:space:]]+name="DB_CONNECTION"[[:space:]]+value="sqlite"[^>]*force="true"' <<<"${STRIPPED}"; then
        fail 'phpunit.xml does not force DB_CONNECTION=sqlite with force="true".'
    fi

    if ! grep -Eq '<env[[:space:]]+name="DB_DATABASE"[[:space:]]+value=":memory:"[^>]*force="true"' <<<"${STRIPPED}"; then
        fail 'phpunit.xml does not force DB_DATABASE=":memory:" with force="true".'
    fi

    # Any other DB_CONNECTION/DB_DATABASE value in the live config is a redirection away
    # from the in-memory database, even if the correct entry is also present.
    BAD_CONN="$(grep -Eo '<env[[:space:]]+name="DB_CONNECTION"[[:space:]]+value="[^"]*"' <<<"${STRIPPED}" \
        | grep -v 'value="sqlite"' || true)"

    if [[ -n "${BAD_CONN}" ]]; then
        fail "phpunit.xml points DB_CONNECTION away from sqlite: ${BAD_CONN}"
    fi

    BAD_DB="$(grep -Eo '<env[[:space:]]+name="DB_DATABASE"[[:space:]]+value="[^"]*"' <<<"${STRIPPED}" \
        | grep -v 'value=":memory:"' || true)"

    if [[ -n "${BAD_DB}" ]]; then
        fail "phpunit.xml points DB_DATABASE away from :memory:: ${BAD_DB}"
    fi
fi

if [[ "${FAILED}" -ne 0 ]]; then
    cat >&2 <<'MSG'

================================================================================
TEST-DATABASE ISOLATION IS NOT INTACT. Refusing to proceed.

RefreshDatabase runs migrate:fresh, which DROPS EVERY TABLE in the target
database. The suite must be pinned to sqlite :memory: by phpunit.xml AND
asserted by tests/bootstrap.php before any test class loads.

See docs/development/phase-1/05-REGRESSION-TEST-PLAN.md.
================================================================================
MSG
    exit 1
fi

printf '  OK: phpunit.xml pins the suite to sqlite :memory: and bootstrap is wired.\n'
