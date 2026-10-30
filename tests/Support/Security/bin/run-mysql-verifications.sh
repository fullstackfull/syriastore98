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
#   - P1-INV-14 / S39-5: `WHERE id = '5abc'` returns row 5 on MySQL and no row on SQLite, so a
#     guard that fell through to the route parameter on such a value authorised the caller's own
#     order while the handler mutated another store's — and the suite no-opped the attack
#     silently. Found by review after 23 green tests.
#   - P1-INV-18: MySQL's default collation is case- and accent-INSENSITIVE and SQLite's is BINARY,
#     so `whereIn('lottery_code', ['ABC-1'])` matches a victim's 'abc-1' on MySQL only. A guard
#     that verified the requested VALUE therefore allowed a request whose handler matched a wider
#     row set. Found by thinking the predicate through, not by a test — no test could.
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

# THE EXIT CODE ALONE IS NOT ENOUGH, and this runner reported nine passes while one of the scripts
# was dying halfway through. Found by reading the log of a script this runner had just called
# "PASS": it had crashed at STEP 4 on an undefined variable, printed a rendered ErrorException, and
# exited 0.
#
# WHY IT EXITS 0. Every one of these scripts calls `$kernel->bootstrap()`, which installs Laravel's
# `HandleExceptions` bootstrapper and with it a `set_exception_handler`. An uncaught throwable is
# then RENDERED by that handler rather than killing the process with PHP's usual status 255 -- so
# the script ends normally, with status 0, having skipped every assertion after the crash. A script
# that stops at step 2 of 6 and says nothing is worse than no script: it reports the absence of
# evidence as evidence.
#
# So a script passes only when ALL THREE hold:
#   1. it exits 0;
#   2. its log contains no `[FAIL]` assertion line;
#   3. its log shows no rendered uncaught throwable.
#
# This lives in the runner rather than in the twelve scripts because the runner is the authority --
# one place, and it covers scripts not yet written.
run_one() {
  local label="$1"; shift
  local log="/tmp/mysqlverify-${label}.log"
  echo "---------------------------------------------------------------------------"
  echo ">>> ${label}"

  local status=0
  env "$@" php "tests/Support/Security/bin/${label}.php" >"${log}" 2>&1 || status=$?

  local reason=""
  if [ "${status}" -ne 0 ]; then
    reason="exit status ${status}"
  elif grep -qE '^[[:space:]]*\[FAIL\]' "${log}"; then
    reason="an assertion reported [FAIL]"
  elif grep -qE '^[[:space:]]*(ErrorException|Error|Exception|TypeError|ValueError|ParseError|ArgumentCountError|RuntimeException|PDOException)[[:space:]]*$' "${log}"; then
    # The rendered-exception banner Laravel's handler prints: the class name alone on its own line.
    reason="an uncaught throwable was rendered (the script exited 0 without finishing)"
  fi

  if [ -z "${reason}" ]; then
    echo "    PASS  (see ${log})"
    tail -3 "${log}" | sed 's/^/    /'
    PASSED+=("${label}")
  else
    echo "    FAIL  -- ${reason}  (see ${log})"
    tail -12 "${log}" | sed 's/^/    /'
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

# F58. `products.cost_price` was a GATE F column with no migration, so product CREATE was
# impossible on any database built from this repository. The migration is reconciled against
# production's own decimal(15,2) NOT NULL DEFAULT 0.00 and is a no-op where the column exists.
# SQLite reports any decimal as `numeric` and stops enforcing scale and NOT NULL, so the type,
# the scale, the INSERT-time NULL refusal and the production-shaped no-op can only be
# established here.
run_one verify-cost-price-migration-on-mysql \
  "COSTPRICE_MYSQL_SOCKET=${SOCK}" "COSTPRICE_MYSQL_HOST=${HOST}" "COSTPRICE_MYSQL_PORT=${PORT}" \
  "COSTPRICE_MYSQL_DATABASE=costprice_verify" "COSTPRICE_MYSQL_USERNAME=${USER_NAME}" "COSTPRICE_MYSQL_PASSWORD=${PASS}"

run_one verify-coupon-code-matching-on-mysql \
  "COUPON_MYSQL_SOCKET=${SOCK}" "COUPON_MYSQL_HOST=${HOST}" "COUPON_MYSQL_PORT=${PORT}" \
  "COUPON_MYSQL_DATABASE=coupon_verify" "COUPON_MYSQL_USERNAME=${USER_NAME}" "COUPON_MYSQL_PASSWORD=${PASS}"

# F154/F153 -- the order export's heading-to-value alignment, and the one coercion that made the
# first draft of that script report a FAIL for a fixture of my own making. Added 2026-10-28 under
# the directive's "export column order" clause for MySQL evidence.
run_one verify-order-export-column-order-on-mysql \
  "EXPORTORDER_MYSQL_SOCKET=${SOCK}" "EXPORTORDER_MYSQL_HOST=${HOST}" "EXPORTORDER_MYSQL_PORT=${PORT}" \
  "EXPORTORDER_MYSQL_DATABASE=exportorder_verify" "EXPORTORDER_MYSQL_USERNAME=${USER_NAME}" "EXPORTORDER_MYSQL_PASSWORD=${PASS}"

run_one verify-cart-quantity-guard-on-mysql \
  "MYSQL_SOCKET=${SOCK}" "MYSQL_HOST=${HOST}" "MYSQL_PORT=${PORT}" \
  "MYSQL_USER=${USER_NAME}" "MYSQL_PASSWORD=${PASS}"

run_one verify-category-product-cascade-on-mysql \
  "CASCADE_MYSQL_SOCKET=${SOCK}" "CASCADE_MYSQL_HOST=${HOST}" "CASCADE_MYSQL_PORT=${PORT}" \
  "CASCADE_MYSQL_DATABASE=cascade_verify" "CASCADE_MYSQL_USERNAME=${USER_NAME}" "CASCADE_MYSQL_PASSWORD=${PASS}"

# P1-INV-14 / S39-5. MySQL coerces a non-numeric string in an `id` comparison -- `WHERE id =
# '5abc'` returns row 5 -- while SQLite's INTEGER affinity does not convert and returns nothing.
# ModuleOrderMutationGuard's resolveId() originally fell through to the route parameter on such a
# value, so it authorised the caller's own order while the handler mutated another store's. The
# Feature suite could not see it in either direction, which is why this is here.
run_one verify-module-order-mutation-id-coercion-on-mysql \
  "MYSQL_SOCK=${SOCK}" "MYSQL_HOST=${HOST}" "MYSQL_PORT_NUM=${PORT}" \
  "MYSQL_DATABASE=p1inv14_verify" "MYSQL_USER_NAME=${USER_NAME}" "MYSQL_PASS=${PASS}"

# P1-INV-17 / S39-7. AuctionProductController::update writes NULL into products.trending and
# products.track_stock from unposted request fields. SQLite REFUSES the statement; production
# MySQL (strict => false) COERCES and the cross-tenant product rename LANDS. Same direction and
# same class as P1-INV-01 below: production is more exposed than the test database.
run_one verify-module-resource-ownership-on-mysql \
  "MYSQL_SOCK=${SOCK}" "MYSQL_HOST=${HOST}" "MYSQL_PORT_NUM=${PORT}" \
  "MYSQL_DATABASE=p1inv17_verify" "MYSQL_USER_NAME=${USER_NAME}" "MYSQL_PASS=${PASS}"

# P1-INV-18. MySQL's default collation is case- AND accent-insensitive (utf8mb4_0900_ai_ci here;
# config/database.php defaults DB_COLLATION to utf8mb4_unicode_ci, also _ci), while SQLite's
# default TEXT collation is BINARY. So `whereIn('lottery_code', ['ABC-1'])` matches a victim's
# 'abc-1' on MySQL and not on SQLite -- which made a guard that verified the requested VALUE
# bypassable while the handler matched a wider row set. The suite cannot see this in either
# direction.
run_one verify-lottery-code-collation-on-mysql \
  "MYSQL_SOCK=${SOCK}" "MYSQL_HOST=${HOST}" "MYSQL_PORT_NUM=${PORT}" \
  "MYSQL_DATABASE=p1inv18_verify" "MYSQL_USER_NAME=${USER_NAME}" "MYSQL_PASS=${PASS}"

# P1-INV-20 / #67 item 2. The refund guard's SECOND limb is a single-value comparison, and under
# the same _ci collation it is LOOSER than it reads: `where('lottery_code', 'abc-1')` also matches
# the caller's own 'ABC-1', so limb 2 reports the request as owned while the handler's unscoped
# ->first() destroys a FOREIGN row with the lower primary key. What this measures is not that
# limb 2 is tight -- it is not -- but that limb 1 has already refused every request where that
# looseness could matter, AND that a legitimate same-store refund still passes both limbs.
run_one verify-lottery-refund-collation-on-mysql \
  "MYSQL_SOCK=${SOCK}" "MYSQL_HOST=${HOST}" "MYSQL_PORT_NUM=${PORT}" \
  "MYSQL_DATABASE=p1inv20_verify" "MYSQL_USER_NAME=${USER_NAME}" "MYSQL_PASS=${PASS}"

# RESERVED ROLE NAMES. `roles.name` carries a UNIQUE index, and a unique index is enforced in its
# OWN collation -- so under `utf8mb4_0900_ai_ci` a tenant role named 'Super admin' is a duplicate-key
# violation against the platform's own 'super admin', while SQLite's BINARY index ACCEPTS it. This is
# the engine hiding the truth in the SAFE direction, which is the more dangerous way round: an
# investigation run only on the test engine over-states what an attacker can do, and three
# independent agents duly reported `Role::where('name','LIKE','super admin')->first()` as ambiguous
# when on MySQL exactly one row can ever match it. This script measures both engines side by side.
run_one verify-reserved-role-name-collation-on-mysql \
  "MYSQL_SOCK=${SOCK}" "MYSQL_HOST=${HOST}" "MYSQL_PORT_NUM=${PORT}" \
  "MYSQL_DATABASE=reserved_role_name_verify" "MYSQL_USER_NAME=${USER_NAME}" "MYSQL_PASS=${PASS}"

# P1-ORD-07 / P1-ORD-08 (review rounds 2-3). (A) the refund request's FOR UPDATE freshness re-read: SQLite
# emits no lock, so the PHPUnit test pins only the comparison; this executes the blocking and the
# locking-read-vs-snapshot semantics on InnoDB. (B) the order-track limiter's keys: stores.slug and
# orders.product_order_id compare case- AND accent-insensitively on MySQL, so a key built from the raw
# string gives an attacker a fresh bucket per spelling of one row.
run_one verify-refund-freshness-lock-and-limiter-keys-on-mysql \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=fresh_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

# P1-SEC-23. whatsapp() queued a guest's basket clear as `customer_id = ''`; guest rows carry customer_id 0 and
# are told apart only by cookie_session_id. SQLite reads `'' = 0` as false (nothing cleared), MySQL in
# production's non-strict mode reads it as TRUE (every guest's row for the product deleted), and strict mode
# raises 1292. The script runs the exact statement shape in both modes.
run_one verify-guest-cart-clear-coercion-on-mysql \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=cartclear_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

# P1-SEC-18 (second review). updateStoreOwnerInfo proved ownership with `Store::where('id', $request->store_id)` and then built
# `public_path('uploads/' . $storeId)` from the RAW string. MySQL coerces '5/../../x' to 5 and matches; SQLite does not.
run_one verify-store-id-coercion-on-mysql \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=storeid_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

# P1-SEC-27 (review). The settings writers do `updateOrCreate(['name' => $postedName, ...])` for every posted name; under the project's
# utf8mb4_unicode_ci connection collation `Domains`, `DOMAINS`, `domains ` and `dómains` are the stored `domains` row and the write
# REWRITES it, name included. SQLite compares bytes and matches none of them.
run_one verify-settings-name-collation-on-mysql \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=settingname_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

# P1-SEC-29 (review). The host-claim hook (App\Services\HostClaims, the Setting model's `saving` event) decided WHICH setting a write was
# by comparing the name and the flag value in PHP, while the lookups compare them in SQL under utf8mb4_unicode_ci: `dómains` IS the stored
# `domains` row, `enablé_domain` IS the flag and `ón` IS 'on', so an owner could rewrite the real rows past the hook. RED on the first fix,
# GREEN now; it also measures which bytes the collation folds (printable ASCII: none beyond case) so the PHP fast path stays sound.
run_one verify-host-claims-on-mysql \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=hostclaims_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

# P1-INV-05 / ACCEPTANCE CRITERION E3, which names the engine in its own text: "Concurrent checkout of the last unit cannot oversell --
# on MySQL (SQLite cannot show it)". Until this script there was no MySQL evidence for E3 and none of the other scripts covered stock.
# tests/Feature/Commerce/OrderStockConcurrencyTest.php proves the decrement is not a read-modify-write, by giving ten callers the stale
# read before any writes -- but SQLite serialises writers at the database level, so the interleaving there is modelled in PHP. This
# observes the row lock itself: A holds an uncommitted decrement of the last unit and B's identical statement BLOCKS (error 1205) instead
# of deciding on its stale read, then after A commits B's `product_stock >= qty` is re-evaluated against the committed 0 and refused. It
# also carries its own removal check -- the read-modify-write form the service replaced DOES oversell on this same engine -- and a
# backorder case, so a too-tight implementation that refused everyone could not pass.
run_one verify-stock-oversell-concurrency-on-mysql \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=oversell_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

# The domain-identity audit (docs/development/phase-1/P01-DOMAIN-IDENTITY-AUDIT.md). Two of that audit's claims are engine-dependent and
# the Feature suite runs on SQLite, so the suite is the wrong instrument for both. This script settled one of them AGAINST the audit's
# first draft -- it claimed a null slug is stored and that getStore() then fails open through whereNull, citing production's
# strict => false; MySQL refuses the row under strict AND non-strict, so the real consequence is a 500 on store creation with no
# cross-tenant reading, and the audit was corrected. It also found a collision class the audit did not have: under utf8mb4_unicode_ci
# the lookup folds case, accents and trailing space, so 'Damascus' IS the stored 'damascus' and the de-duplication's own LIKE cannot
# hand out a case variant as free. Carries a removal check -- the proposed UNIQUE index is what refuses all three -- so the control is
# shown to be the cause and not merely correlated.
run_one verify-store-slug-identity-on-mysql \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=slug_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

# P1-PAY-02. The gateway-return claim's whole value is that exactly ONE of two overlapping returns can take a key, and the suite cannot
# show that: phpunit.xml sets CACHE_STORE=array, ArrayStore has no add() of its own, so Cache::add() there is the Repository
# get-then-put fallback -- correct, but not atomic -- and PHPUnit is single-threaded besides. Production is CACHE_STORE=database, where
# DatabaseStore::add() ends in insertOrIgnore against cache.key, the PRIMARY KEY. This script drives two connections through the real
# interleave: A holds an uncommitted claim and B must BLOCK on the row lock rather than decide on its stale read. Section E states, as a
# measurement rather than as prose, exactly what the SQLite suite is and is not evidence for.
run_one verify-gateway-return-claim-on-mysql \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=claim_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

run_one verify-ord15-billing-geography-on-mysql \
  "ORD15_MYSQL_SOCKET=${SOCK}" "ORD15_MYSQL_HOST=${HOST}" "ORD15_MYSQL_PORT=${PORT}" \
  "ORD15_MYSQL_DATABASE=ord15_verify" "ORD15_MYSQL_USERNAME=${USER_NAME}" "ORD15_MYSQL_PASSWORD=${PASS}"

# P1-ORD-02 criterion 2. The pending-balance lookup: MySQL coerces '5abc' to row 5 in the `id`
# comparison, and -- because the module declares `customer_id` as VARCHAR while inserting an
# integer -- a SQL-side ownership predicate admits '7abc', ' 7' and '07' as customer 7. Both are
# invisible on SQLite, which is why one mutant in the removal run survives there.
run_one verify-pending-partial-payment-lookup-on-mysql \
  "MYSQL_SOCK=${SOCK}" "MYSQL_HOST=${HOST}" "MYSQL_PORT_NUM=${PORT}" \
  "MYSQL_DATABASE=p1ord02_pending_verify" "MYSQL_USER_NAME=${USER_NAME}" "MYSQL_PASS=${PASS}"

# P1-INV-24 / OWNER DECISION 3. The category cascade that destroys ProductReview rows, and the
# SET NULL migration that stops it. Entirely a foreign-key DELETE rule, so SQLite -- which does not
# enforce foreign keys unless asked and cannot ALTER a constraint at all -- can say nothing about it.
run_one verify-product-review-category-set-null-on-mysql \
  "MYSQL_SOCK=${SOCK}" "MYSQL_HOST=${HOST}" "MYSQL_PORT_NUM=${PORT}" \
  "MYSQL_DATABASE=p1inv24_verify" "MYSQL_USER_NAME=${USER_NAME}" "MYSQL_PASS=${PASS}"

# ---------------------------------------------------------------------------------------------
# THE FOUR SCRIPTS THAT WERE OFF THIS ROSTER, and why that mattered.
#
# A script nobody runs is documentation, not evidence. The F194 and F197 slices each wrote one and
# ran it by hand; the ledger audit of 2026-10-29 counted one as "off-roster" without naming the
# rest. These use the FRESH_MYSQL_* prefix rather than MYSQL_*, which is also why a reader
# scanning this file for them found nothing.
#
# Still deliberately absent: verify-gate-f-schema-on-mysql (it reads a production dump that is not
# in the repository), and the scripts that need no server at all --
# verify-menu-render-parity, verify-remote-fetch-pin-with-curl, verify-sec06-shopify-redirect,
# verify-backuprestore-module-gating, verify-module-order-mutation-route-cache,
# verify-order-document-route-cache, verify-order-chart-operator-branch.
# ---------------------------------------------------------------------------------------------

run_one verify-model-cache-isolation-on-mysql \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=modelcache_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

run_one verify-model-cache-flush-scope-on-mysql \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=flushscope_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

run_one verify-login-and-settings-cache-paths-on-mysql \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=logincache_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

run_one verify-empty-variant-name-wipe-on-mysql \
  "F199_MYSQL_SOCKET=${SOCK}" "F199_MYSQL_HOST=${HOST}" "F199_MYSQL_PORT=${PORT}" \
  "F199_MYSQL_DATABASE=f199_verify" "F199_MYSQL_USERNAME=${USER_NAME}" "F199_MYSQL_PASSWORD=${PASS}"

echo "==========================================================================="
echo "passed: ${#PASSED[@]}   failed: ${#FAILED[@]}"
if [ "${#FAILED[@]}" -gt 0 ]; then
  printf '  FAILED: %s\n' "${FAILED[@]}"
  exit 1
fi
echo "every MySQL verification script reported all assertions held."
