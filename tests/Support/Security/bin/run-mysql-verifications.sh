#!/usr/bin/env bash
#
# P1-PLT-01 — run every MySQL verification script against one throwaway server.
#
# WHY THIS EXISTS. The Feature suite runs on SQLite `:memory:` (phpunit.xml) and
# tests/Support/TestDatabaseGuard.php ABORTS any PHPUnit run whose connection is not sqlite,
# because RefreshDatabase would migrate:fresh and drop every table in the target database. That
# guard is correct and must not be weakened — so MySQL-specific evidence lives in standalone
# scripts that create and drop their OWN database. This runner points all of them at one server
# and reports per script.
#
# SQLite's type affinity and its NOT NULL handling have hidden real defects here in BOTH
# directions:
#   - P01-SEC-13: customers.password_otp is INTEGER while the web reset writes a 60-char bcrypt
#     hash. SQLite stores it happily; MySQL coerced it to 0 and the reset could never complete.
#   - P1-INV-01 / S37-1: ProductController::update writes NULL into NOT NULL columns from unposted
#     request fields. SQLite REFUSES the write, which masked a cross-store update; MySQL with
#     `strict => false` (config/database.php:55 — the production setting) coerces and the write
#     lands. Production was more exposed than the test database.
#
# USAGE
#   tests/Support/Security/bin/run-mysql-verifications.sh
#
#   MYSQL_SOCK=/var/run/mysqld/mysqld.sock   # or MYSQL_HOST/MYSQL_PORT_NUM
#   MYSQL_USER_NAME=root
#   MYSQL_PASS=
#
# PROVISIONING A THROWAWAY SERVER (an ephemeral dev container — never a shared one):
#   apt-get update -q && DEBIAN_FRONTEND=noninteractive apt-get install -y -q mysql-server-8.0
#   service mysql start
#
# SAFETY. Every script creates and drops its own database and refuses a database name that does
# not contain 'verify' (or 'sec13'). NEVER point this at the development or production server.
# Exit code 0 means every script reported every assertion held.
set -uo pipefail

cd "$(dirname "$0")/../../../.." || exit 2

SOCK="${MYSQL_SOCK:-/var/run/mysqld/mysqld.sock}"
HOST="${MYSQL_HOST:-127.0.0.1}"
PORT="${MYSQL_PORT_NUM:-3306}"
USER_NAME="${MYSQL_USER_NAME:-root}"
PASS="${MYSQL_PASS:-}"

echo "MySQL verification runner"
echo "========================="
echo "socket : ${SOCK}"
echo "host   : ${HOST}:${PORT}"
echo "user   : ${USER_NAME}"
echo

declare -a FAILED=()
declare -a PASSED=()

run_one() {
  local label="$1"; shift
  echo "---------------------------------------------------------------------------"
  echo ">>> ${label}"
  if env "$@" php "tests/Support/Security/bin/${label}.php" >"/tmp/mysqlverify-${label}.log" 2>&1; then
    echo "    PASS  (see /tmp/mysqlverify-${label}.log)"
    tail -3 "/tmp/mysqlverify-${label}.log" | sed 's/^/    /'
    PASSED+=("${label}")
  else
    echo "    FAIL  (see /tmp/mysqlverify-${label}.log)"
    tail -12 "/tmp/mysqlverify-${label}.log" | sed 's/^/    /'
    FAILED+=("${label}")
  fi
  echo
}

run_one verify-sec13-migration-on-mysql \
  "SEC13_MYSQL_SOCKET=${SOCK}" "SEC13_MYSQL_HOST=${HOST}" "SEC13_MYSQL_PORT=${PORT}" \
  "SEC13_MYSQL_DATABASE=sec13_verify" "SEC13_MYSQL_USERNAME=${USER_NAME}" "SEC13_MYSQL_PASSWORD=${PASS}"

run_one verify-product-ownership-on-mysql \
  "PRODOWN_MYSQL_SOCKET=${SOCK}" "PRODOWN_MYSQL_HOST=${HOST}" "PRODOWN_MYSQL_PORT=${PORT}" \
  "PRODOWN_MYSQL_DATABASE=prodown_verify" "PRODOWN_MYSQL_USERNAME=${USER_NAME}" "PRODOWN_MYSQL_PASSWORD=${PASS}"

run_one verify-coupon-code-matching-on-mysql \
  "COUPON_MYSQL_SOCKET=${SOCK}" "COUPON_MYSQL_HOST=${HOST}" "COUPON_MYSQL_PORT=${PORT}" \
  "COUPON_MYSQL_DATABASE=coupon_verify" "COUPON_MYSQL_USERNAME=${USER_NAME}" "COUPON_MYSQL_PASSWORD=${PASS}"

run_one verify-cart-quantity-guard-on-mysql \
  "MYSQL_SOCKET=${SOCK}" "MYSQL_HOST=${HOST}" "MYSQL_PORT=${PORT}" \
  "MYSQL_USER=${USER_NAME}" "MYSQL_PASSWORD=${PASS}"

echo "==========================================================================="
echo "passed: ${#PASSED[@]}   failed: ${#FAILED[@]}"
if [ "${#FAILED[@]}" -gt 0 ]; then
  printf '  FAILED: %s\n' "${FAILED[@]}"
  exit 1
fi
echo "every MySQL verification script reported all assertions held."
