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
declare -a INVOKED=()
declare -a SKIPPED=()

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
  INVOKED+=("${label}")

  # Env assignments first, then `--`, then the script's own CLI arguments. About a third of these
  # scripts take `--socket=` / `--user=` / `--pass=` flags and read NO environment at all, so
  # before this they could not be placed on the roster without being handed a connection they
  # would silently ignore -- part of why fourteen of them sat off it.
  local envs=() args=()
  while [ "$#" -gt 0 ]; do
    if [ "$1" = "--" ]; then shift; args=("$@"); break; fi
    envs+=("$1"); shift
  done

  local status=0
  env "${envs[@]}" php "tests/Support/Security/bin/${label}.php" "${args[@]}" >"${log}" 2>&1 || status=$?

  # A SCRIPT THAT MEASURED NOTHING MUST NOT READ AS EVIDENCE. Several of these print their own
  # "SKIP" / "PRECONDITION NOT MET" banner and then exit 0 -- correct of them, and this roster
  # counted it as PASS. Measured: verify-reserved-role-name-collation-on-mysql reported PASS while
  # printing "SKIP: no MySQL reachable at 127.0.0.1 -- Access denied", i.e. asserting nothing at
  # all. A skip is now its own verdict, counted apart from the passes.
  #
  # TWO CONDITIONS, NOT ONE, AND THE SECOND WAS ADDED AFTER THE FIRST DRAFT GOT IT WRONG. Matching
  # the banner alone marked verify-empty-variant-name-wipe-on-mysql (13 checks, 0 failures) and
  # verify-setting-name-writer-on-mysql ("every check held") as having measured nothing, because
  # somewhere in their output sits the PROSE "skipped because its covered set was empty (C)" --
  # a sentence ABOUT a sub-case, not a verdict on the run. That is the census trap CLAUDE.md
  # records in three other syntaxes, reproduced here in a fourth: a scan satisfied or broken by
  # prose about the thing it measures. So a run counts as a skip only if it ALSO recorded no
  # check at all, and the banner must start the line rather than sit inside a sentence.
  if [ "${status}" -eq 0 ] \
     && grep -qE '^(SKIP|SKIPPED)\b|PRECONDITION (NOT MET|FAILED)' "${log}" \
     && ! grep -qiE '^[[:space:]]*(ok\b|\[ *OK *\]|\[PASS\]|PASS\b)|checks?[^0-9]{0,12}[1-9]' "${log}"; then
    echo "    SKIPPED  -- the script declined to measure (see ${log})"
    grep -iE '^[[:space:]]*(SKIP|SKIPPED|PRECONDITION (NOT MET|FAILED))' "${log}" | head -2 | sed 's/^/    /'
    SKIPPED+=("${label}")
    echo
    return 0
  fi

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

# P1-SEC-78 (and the review of its first form). The write-side rule for the sign-in image rows (StoredPathPolicy::setting) decided "is this such a row" in PHP,
# on the name as posted; the vendored lookups compare under the COLUMN's collation and the column keeps a name cut to ITS width. The script asks the engine which of
# ~200,000 candidate names a lookup would find once stored, and whether the hook refuses a poisoned value under each. It is run under three (column, connection) collation
# pairs, because the first form asked under the connection's and was red when they differed; the UCA 14 pair (utf8mb4_uca1400_ai_ci, MariaDB >= 10.10) is run by hand.
for pair in "utf8mb4_unicode_ci utf8mb4_unicode_ci" "utf8mb4_general_ci utf8mb4_unicode_ci" "utf8mb4_unicode_ci utf8mb4_general_ci"; do
  set -- ${pair}
  run_one verify-setting-name-writer-on-mysql \
    "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
    "FRESH_MYSQL_DATABASE=signinname_writer_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}" \
    "FRESH_COLUMN_COLLATION=${1}" "FRESH_CONNECTION_COLLATION=${2}"
done

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

# F205 -- the consent token's bindings AND the check-then-delete race, driven on two real
# connections. The suite runs on SQLite, where lockForUpdate() compiles to nothing, so the
# concurrency half of the owner's condition can only be asked here.
run_one verify-variant-removal-consent-on-mysql \
  "CONSENT_MYSQL_SOCKET=${SOCK}" "CONSENT_MYSQL_HOST=${HOST}" "CONSENT_MYSQL_PORT=${PORT}" \
  "CONSENT_MYSQL_DATABASE=consent_verify" "CONSENT_MYSQL_USERNAME=${USER_NAME}" "CONSENT_MYSQL_PASSWORD=${PASS}"

run_one verify-login-and-settings-cache-paths-on-mysql \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=logincache_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

# F211 -- the CONSEQUENCE of F194, which the flush-scope script above stops short of asking. That
# one proves the payment keys are erased by an ordinary model save. This one proves what "erased"
# means to the control that wrote them: `claimGatewayReturn()` takes its claim with `Cache::add()`
# and reads false as "already claimed", so a missing key is an UN-TAKEN claim and the replayed
# gateway return is welcomed rather than merely unguarded. Drives the full chain: claim taken,
# replay refused, one Setting::updateOrCreate(), claim gone, SAME replay now permitted.
# F194/F211 ACTIVATION -- the owner's 2026-10-30 decision. Runs the F211 chain TWICE in one
# process: isolation OFF (the defect must reproduce, or the "after" proves nothing) and isolation ON
# (the claim must survive the identical Cachable save). Plus D-28a's no-stale-read condition and the
# boundary that activation does NOT make `cache:clear` safe, so it does not subsume F215.
run_one verify-replay-claim-with-model-cache-isolation-on-mysql \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=claimisolation_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

run_one verify-replay-claim-survives-a-model-save-on-mysql \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=replayclaim_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

# F216 / D-29.3 -- one capture is one row under REAL concurrency. The sequential contract is
# proven in GatewayReferenceIdempotencyTest; uniqueness under two simultaneous inserts is an
# ENGINE property, so only two real connections can measure it. Also drives failure before commit
# (a rollback must not strand the capture) and after commit (a committed claim must block it).
run_one verify-gateway-reference-idempotency-on-mysql \
  "OGR_MYSQL_SOCKET=${SOCK}" "OGR_MYSQL_HOST=${HOST}" "OGR_MYSQL_PORT=${PORT}" \
  "OGR_MYSQL_DATABASE=ogr_verify" "OGR_MYSQL_USERNAME=${USER_NAME}" "OGR_MYSQL_PASSWORD=${PASS}"

# F207 / D-29.2's "test concurrency, failure and retry" condition, which an adversarial sweep found
# ARGUED RATHER THAN DRIVEN -- the row read VERIFIED while the script it pointed at contained zero
# occurrences of concurrency, race, lock or a second connection. SQLite cannot answer it:
# lockForUpdate() compiles to nothing and the in-memory database is one connection, so a race is
# unobservable. Also records the transaction-wrapped deadlock as a hazard rather than discarding it.
run_one verify-cart-reconciliation-concurrency-on-mysql \
  "CARTREC_MYSQL_SOCKET=${SOCK}" "CARTREC_MYSQL_HOST=${HOST}" "CARTREC_MYSQL_PORT=${PORT}" \
  "CARTREC_MYSQL_DATABASE=cartrec_verify" "CARTREC_MYSQL_USERNAME=${USER_NAME}" "CARTREC_MYSQL_PASSWORD=${PASS}"

run_one verify-empty-variant-name-wipe-on-mysql \
  "F199_MYSQL_SOCKET=${SOCK}" "F199_MYSQL_HOST=${HOST}" "F199_MYSQL_PORT=${PORT}" \
  "F199_MYSQL_DATABASE=f199_verify" "F199_MYSQL_USERNAME=${USER_NAME}" "F199_MYSQL_PASSWORD=${PASS}"

# F234. The ONE question in this roster that the Feature suite cannot answer at all rather than
# merely answer badly: the plan initiators look their coupon up with `whereRaw('BINARY `code` = ?')`,
# which is MySQL-only. On SQLite that is a SYNTAX ERROR, so the request dies before it reaches the
# coupon block, and a Feature test asserting "no usage was persisted" passes for a reason with
# nothing to do with the defect. Two such tests were written, both passed, and both were deleted
# once driven. 43 files under app/Http/Controllers/ use that form.
run_one verify-plan-coupon-refusal-ordering-on-mysql \
  "F234_MYSQL_SOCKET=${SOCK}" "F234_MYSQL_HOST=${HOST}" "F234_MYSQL_PORT=${PORT}" \
  "F234_MYSQL_DATABASE=f234_verify" "F234_MYSQL_USERNAME=${USER_NAME}" "F234_MYSQL_PASSWORD=${PASS}"

# F229 / D-31. THREE properties here cannot be observed on SQLite at all, which is why the owner's
# instruction asks for real MariaDB concurrency evidence rather than a SQLite claim standing in for
# one: (1) two callbacks racing for one intent -- SQLite :memory: is one connection in one process,
# so there is no second writer to lose; (2) DECIMAL(16,4), which MySQL enforces and SQLite ignores
# by type affinity (449.10 reads back as '449.1' there and '449.1000' here -- the same affinity
# difference that let P01-SEC-13 survive a green suite); (3) the UNIQUE constraint on `reference`.
run_one verify-plan-purchase-intent-on-mysql \
  "F229_MYSQL_SOCKET=${SOCK}" "F229_MYSQL_HOST=${HOST}" "F229_MYSQL_PORT=${PORT}" \
  "F229_MYSQL_DATABASE=f229_verify" "F229_MYSQL_USERNAME=${USER_NAME}" "F229_MYSQL_PASSWORD=${PASS}"

# #204. The plan-payment settlement, driven through the REAL service against a database built by
# the project's own migrations. It exists because the independent financial review found a defect
# here that no SQLite test can see: under REPEATABLE READ the loser of a real race read its own
# transaction snapshot, the intent still looked `pending`, and a lost race was reported AND AUDITED
# as a mismatching AMOUNT -- a false record, and an exit 1. `lockForUpdate()` is what makes that
# re-read current, and on SQLite it compiles to nothing, so the suite passes with the fix and
# without it. The script also caught a defect in the fix for another finding on its first run.
run_one verify-plan-payment-reconciliation-on-mysql \
  "PPR_MYSQL_SOCKET=${SOCK}" "PPR_MYSQL_HOST=${HOST}" "PPR_MYSQL_PORT=${PORT}" \
  "PPR_MYSQL_DATABASE=ppr_verify" "PPR_MYSQL_USERNAME=${USER_NAME}" "PPR_MYSQL_PASSWORD=${PASS}"

# #204. The disabled-application payment boundary. The owner's rule has the word IDEMPOTENT in it,
# and what makes a payment return idempotent is ONE conditional UPDATE from `initiated` -- which
# only means something where two writers can really contend. SQLite :memory: is one connection in
# one process, so the suite can show the claim holds sequentially and cannot show it holds under a
# race. THIS SCRIPT ALSO FOUND A REAL DEFECT AND CHANGED THE CODE: under `utf8mb4_general_ci` -- the
# collation the server actually reports -- the completion claim matched a reference differing only in
# letter case, the P1-INV-18 shape again. `ModulePaymentLedger::isWellFormedReference()` and the
# column's own collation now close it, and the script asserts the engine-independent half.
run_one verify-module-payment-boundary-on-mysql \
  "MPB_MYSQL_SOCKET=${SOCK}" "MPB_MYSQL_HOST=${HOST}" "MPB_MYSQL_PORT=${PORT}" \
  "MPB_MYSQL_DATABASE=mpb_verify" "MPB_MYSQL_USERNAME=${USER_NAME}" "MPB_MYSQL_PASSWORD=${PASS}"

# E13-F. Two concurrent replacement attempts on one order, which the handoff listed as NOT RUN and
# which the Feature suite cannot run: SQLite has no row locks worth the name and one PHP process
# cannot hold two statements in flight. The property under test is narrow and load-bearing --
# `orders.replaced_order_id` is INDEXED and NOT UNIQUE (the migration explains why a real FK and a
# UNIQUE index were both declined), so the database will NOT refuse a duplicate and
# `OrderReplacement`'s in-transaction `exists()` check is the only control. The script asserts the
# non-uniqueness first, because if that index were unique every other check would pass on the
# strength of a constraint production does not have.
run_one verify-order-replacement-concurrency-on-mysql \
  "E13F_MYSQL_SOCKET=${SOCK}" "E13F_MYSQL_HOST=${HOST}" "E13F_MYSQL_PORT=${PORT}" \
  "E13F_MYSQL_DATABASE=e13f_verify" "E13F_MYSQL_USERNAME=${USER_NAME}" "E13F_MYSQL_PASSWORD=${PASS}"

# P5-2 -- the Phase 05 metric layer. Ten money query objects whose tenant predicates, DECIMAL
# precision and GROUP BY shape only mean anything on the production driver. It forces
# ONLY_FULL_GROUP_BY on, which this container's server does NOT enable by default, because testing
# under the stricter mode is the only direction safe to be wrong in. It also drives a genuinely
# NULL `orders.currency_code` row -- the shape `2026_10_12_000102` deliberately left unbackfilled --
# and proves `total()` really throws before the fold, which is the HTTP 500 that P5-3a prevents.
# (The duplicate `run_one verify-metric-layer-on-mysql` that stood here until 2026-11-25 has been
# removed. It ran the same script a second time without `FRESH_MYSQL_DATABASE`, so the two
# invocations differed only in which database they created and dropped; the surviving registration
# further down names `metriclayer_verify` explicitly and is the one kept. Found by the Phase 05
# workstream, which also showed the later block's comment was wrong about this -- see there.)

# P5-3b -- the merchant sales report's month window. THIS ONE IS NOT A CONVENIENCE: ReportController
# calls MONTH() 36 times and YEAR() 9 times inside selectRaw and SQLite has neither, so every
# handler in that file answers HTTP 500 under the Feature suite and CANNOT be covered by it. This
# script is the only executable evidence those surfaces have. It measures the figure a merchant is
# shown for "last month" and keeps negative controls for an out-of-period order and another
# tenant's order, so a widened bound cannot pass it.
run_one verify-report-window-boundary-on-mysql \
  "SESSION_DRIVER=array" \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

# P5-3f -- the four "Top 5" charts. Also NOT a convenience: the category and brand queries use
# SUBSTRING_INDEX and the rest of ReportController uses MONTH()/YEAR(), none of which SQLite has,
# so top_product() cannot run under the Feature suite either. The script seeds SEVEN items so a
# correct top five must exclude two, and spells one payment method four ways so a case-sensitive
# grouping shows up as four bars competing for five slots.
run_one verify-top-five-reports-on-mysql \
  "SESSION_DRIVER=array" \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

# D8-1 independent review, F1 and F2 -- the consent ledger under concurrency. SQLite compiles lockForUpdate() to
# nothing and :memory: is one connection, so neither race is observable in the suite. This uses several REAL PROCESSES
# on a real server running the REAL ledger, and forces the interleaving with a query listener. Before the fix: log
# `1:granted 2:granted 3:withdrawn` with the projection `granted` (a withdrawal lost in the table the send gate reads), and
# a `1213 Deadlock found` that rolled back a first-ever event. After: both hold; with the deadlock retry removed, S2 fails.
run_one verify-consent-ledger-concurrency-on-mysql \
  "CONSENT_MYSQL_SOCKET=${SOCK}" "CONSENT_MYSQL_HOST=${HOST}" "CONSENT_MYSQL_PORT=${PORT}" \
  "CONSENT_MYSQL_USERNAME=${USER_NAME}" "CONSENT_MYSQL_PASSWORD=${PASS}"

# GAP-222 -- the wallet currency presentation. The one question SQLite cannot answer about it:
# `store_wallets.currency_code` is a VARCHAR, and MySQL's default collation is case-INSENSITIVE and
# PAD SPACE while SQLite's is BINARY. So 'SYP', 'syp' and 'SYP  ' are ONE `GROUP BY` bucket here and
# THREE in the suite -- and the bucket COUNT is what decides whether the tile shows a total or
# refuses one and lists the amounts. MEASURED: one bucket summing 60.00 on this engine. The
# `strtoupper(trim())` in MoneyText::buckets() is what makes the two engines agree, and it runs AFTER
# the engine has grouped. Also covers the DECIMAL(15,2) SUM() coming back as a STRING (a missed cast
# is invisible under SQLite), the zero-bucket flag both callers depend on, and MySQL coercing
# `store_id = '7abc'` to 7 where SQLite matches nothing.
run_one verify-gap222-wallet-currency-on-mysql \
  "GAP222_MYSQL_SOCKET=${SOCK}" "GAP222_MYSQL_USERNAME=${USER_NAME}" "GAP222_MYSQL_PASSWORD=${PASS}"

# GAP-223(a) -- the last-month window, selecting real rows on an overflow day. Two reasons it cannot
# be a test: the bug needs a particular day (33 in every five years, and Carbon::setTestNow() does
# not move date()/strtotime()), and ReportController's chart handlers use MONTH(/YEAR( in selectRaw,
# which sqlite does not implement, so a Feature test against them answers 500. MEASURED: on
# 31 March 2026 the old `-1 month` bounds select MARCH's 3,000.00 across 2 orders under a card
# labelled "February 2026", while February's 700.00 across 3 orders is absent entirely. The fixed
# bounds select February, carry a day count of 28 that matches the chart axis, keep both ends
# inclusive, and are byte-identical to the old ones on every NON-overflow day -- which is what keeps
# the change bounded. CalendarPeriodTest covers the derivation on all 1,827 days; this covers the rows.
run_one verify-gap223-lastmonth-window-on-mysql \
  "GAP223_MYSQL_SOCKET=${SOCK}" "GAP223_MYSQL_USERNAME=${USER_NAME}" "GAP223_MYSQL_PASSWORD=${PASS}"

# ANA-009. The alert's dedup key against the column types it lands in: a string in an
# `unsignedBigInteger` is stored verbatim by SQLite, coerced to 0 by a non-strict MySQL and refused
# with 1366 by a strict one. The script proves the OLD shape fails both ways and the shipped one
# round-trips, which is the only engine that can answer it.
run_one verify-ana009-alert-key-on-mysql \
  "ANA009_MYSQL_SOCKET=${SOCK}" "ANA009_MYSQL_USERNAME=${USER_NAME}" "ANA009_MYSQL_PASSWORD=${PASS}"

# P5-2. THE WHOLE METRIC LAYER.
#
# THIS COMMENT USED TO SAY "it was NOT registered here until 2026-11-24 -- so a green run of this
# runner never covered Phase 05's financial evidence at all", AND THAT WAS FALSE: a second
# registration of this same script already stood earlier in this file, so the runner did cover it.
# The duplicate has been removed (see the note above where it was) and this claim corrected, both
# on 2026-11-25. Recorded rather than deleted because a runner that silently ran one script twice
# is the kind of thing a reader needs to be told once. Every metric aggregates with a
# GROUP BY over a currency column written through selectRaw, which ONLY_FULL_GROUP_BY can reject and
# SQLite cannot; and the net-receipts agreement in its §6 is between two DECIMAL(15,2) computations
# that SQLite turns into floats, so on SQLite they agree for the wrong reason. 65 checks.
run_one verify-metric-layer-on-mysql \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=metriclayer_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

# P5-R12 / ANA-RED. The loyalty figure ACROSS CURRENCIES, which the script above cannot answer
# because every order in its fixture is USD or NULL -- it has no two-currency case anywhere. The one
# that matters: a merchant whose redemptions span currencies must get two separate buckets and a
# REFUSAL from total(), not 15 USD added to 2000 SYP under one symbol. Also that `usd` and `USD` fold
# into one bucket under the server's collation and not into a false "mixed currency", which PHP's own
# comparison would get wrong. 12 checks.
#
# BOTH SCRIPTS ARE CAUGHT BY CRITERION 1 ONLY, and that was measured rather than assumed: they print
# `  FAIL  <what>` where run_one's criterion 2 greps for `[FAIL]`, so that grep finds nothing in
# their logs. A deliberately broken assertion was driven through one of them -- exit status 1, zero
# `[FAIL]` matches -- so the exit code is the live detector here. The grep was NOT broadened to
# `^\s*FAIL\s`: forty-three existing scripts write to these logs and none of their passing output
# has been read, so a wider pattern could fail a passing script for a word in its prose.
run_one verify-loyalty-redemption-currency-on-mysql \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=loyaltyredemption_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

# ANA-005 -- "Payments received" on the sales report. REGISTERED 2026-11-25, and `GAP-171` named its
# absence by name: "57 MySQL harnesses exist, the runner registers 42, the ANA-005 one is among the
# fifteen it does not, and CI runs none." The script itself has existed and passed since the slice
# shipped; nothing but this line was missing, so a green run of this runner never covered it. 22
# checks: the clamp to the ledger's verified coverage, the refusal for a window that ends before the
# records begin, and a second tenant's decoy ledger.
#
# `SESSION_DRIVER=array` IS LOAD-BEARING HERE, and this is why the registration is not a one-word
# change. The script signs a principal in to drive the report handler; `config/session.php` defaults
# to the `database` driver, and in an environment with no `.env` that driver resolves against the
# DEFAULT connection -- still sqlite at the moment `Auth::login()` migrates the session -- so the
# run dies with "Database file at path [database/database.sqlite] does not exist" after two of its
# twenty-two checks. MEASURED both ways on MariaDB 10.11: without the override, 2 checks / 1 failed;
# with it, 22 / 0.
run_one verify-ana005-sales-collection-on-mysql \
  "SESSION_DRIVER=array" \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=ana005_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

# GAP-154 / GAP-171 -- the merchant analytics screen's three grouped reads, which `GAP-154` asks for
# as "a MySQL harness for the screen's GROUP BY reads" and `GAP-171` lists among five items with no
# MySQL evidence at all. It forces `ONLY_FULL_GROUP_BY` on, which this container's server does not
# enable by default, because a query that passes under the stricter mode cannot be broken by a
# stricter server.
#
# IT FOUND A LIVE DEFECT ON ITS FIRST RUN, which is the reason to run it rather than to trust the
# suite: `GROUP BY order_source` is case-INSENSITIVE on this engine, so three orders spelled
# `POS`, `pos`, `pos` are ONE group represented by whichever row the engine reached first -- and
# `Order::orderSourceLabel()` is a `match` on lower-case constants, so the whole group was shown to
# the merchant as "POS (not a source this version knows)" with a correct count. SQLite's BINARY
# collation makes that two groups and hides it. 12 checks; with the fold reverted, 2 of them fail.
#
# Like the two scripts above it prints `  FAIL  <what>`, which `run_one`'s `[FAIL]` grep does not
# match, so the EXIT CODE is the live detector -- measured: the reverted fold exits 1.
run_one verify-analytics-breakdowns-on-mysql \
  "SESSION_DRIVER=array" \
  "BREAKDOWN_MYSQL_SOCKET=${SOCK}" "BREAKDOWN_MYSQL_HOST=${HOST}" "BREAKDOWN_MYSQL_PORT=${PORT}" \
  "BREAKDOWN_MYSQL_DATABASE=breakdowns_verify" "BREAKDOWN_MYSQL_USERNAME=${USER_NAME}" "BREAKDOWN_MYSQL_PASSWORD=${PASS}"

# GAP-192 / SAAS-007 -- the PLATFORM's own revenue (what merchants pay Store Syria), which was the
# one financial surface in this repository with no MySQL harness beside it. An independent financial
# review said so; this is that evidence. Its receipts half groups `plan_orders` in the database on
# `payment_status`, `price_currency` and `is_refund`, and all three behave differently on the two
# engines.
#
# IT REFUTED ITS OWN AUTHOR'S FINDING ON THE FIRST RUN, which is the reason to run it rather than to
# reason about it: `plan_orders.price` is declared `$table->float('price')`, so a money figure summed
# off it looked like GAP-217's float-precision basis, and `MetricCaveat::FLOAT_PRECISION` had already
# been attached to the figure. Laravel 11's `float()` emits a MySQL **`double`** -- `2500000.49` at
# SYP scale stores and reads back exactly, while a hand-written `float` column on the same server
# loses the minor units. The caveat came back out. Check [6] carries both halves so the next reader
# does not repeat it.
#
# It also measures, side by side on the same rows in one run, that MySQL folds `Succeeded` and
# `succeeded` into ONE group where SQLite returns TWO -- the claim `planOrderGroups()`'s docblock
# makes in prose and nothing executed.
#
# Like its neighbours it prints `  FAIL  <what>`, which `run_one`'s `[FAIL]` grep does not match, so
# the EXIT CODE is the live detector.
run_one verify-saas-revenue-on-mysql \
  "SESSION_DRIVER=array" \
  "SAASREV_MYSQL_SOCKET=${SOCK}" "SAASREV_MYSQL_HOST=${HOST}" "SAASREV_MYSQL_PORT=${PORT}" \
  "SAASREV_MYSQL_DATABASE=saasrev_verify" "SAASREV_MYSQL_USERNAME=${USER_NAME}" "SAASREV_MYSQL_PASSWORD=${PASS}"

# GAP-150 / `P05-02` -- the store's inventory valuation, moved out of `StoreFinanceController::index()`
# into the metric layer. Its precision disclosure is a CLAIM ABOUT COLUMN TYPES, and those differ by
# engine: `products.cost_price` is `decimal(15,2)` (exact, no caveat) while `products.price`,
# `sale_price` and `product_variants.price` are all `double` (caveat). The tables here are built from
# the MEASURED production types rather than from the migration text -- which matters because
# Laravel's `float()` emits a MySQL `double`, and reading the migration instead of the server
# attached a precision caveat elsewhere in this repository that had to be withdrawn the same day.
#
# It also drives the two cases most likely to diverge by engine: the operator's TWO-STORE scope with
# a variant row of store B hanging off store A's product, which must be excluded; and cost 0.10
# against retail 0.30, where the profit must be 0.20 and not 0.19999999999999998.
run_one verify-store-inventory-valuation-on-mysql \
  "SESSION_DRIVER=array" \
  "INVMETRIC_MYSQL_SOCKET=${SOCK}" "INVMETRIC_MYSQL_HOST=${HOST}" "INVMETRIC_MYSQL_PORT=${PORT}" \
  "INVMETRIC_MYSQL_DATABASE=inv_metric_verify" "INVMETRIC_MYSQL_USERNAME=${USER_NAME}" "INVMETRIC_MYSQL_PASSWORD=${PASS}"

# ===========================================================================================
# THE FOURTEEN THAT WERE OFF THIS ROSTER UNTIL 2026-11-26, and the two that still are.
#
# Measured: 64 `*-on-mysql.php` scripts exist under this directory and this roster invoked 52.
# The twelve-plus-two difference was not a decision -- every one of them was written, committed
# and then never run by the only thing that runs them. The roster reported
# "every MySQL verification script reported all assertions held", which was true of the roster
# and false of the directory. The completeness check at the bottom of this file now makes that
# divergence fail instead of read as success.
#
# Most of these take CLI FLAGS rather than environment variables, which is why they could not
# simply be appended before `run_one` learned to pass script arguments after `--`.
#
# ONE OF THEM WAS ALSO MEASURING THE WRONG MIGRATION. verify-theme-order-theme-id-migration
# rolled back with `migrate:rollback --step=1`, i.e. "the most recent migration" -- correct only
# while 2026_11_15_000100 WAS the most recent. Ten migrations have landed since, so it reverted
# `add_acknowledgement_to_plan_purchase_intents_table` instead and reported 3 FAILs that read as
# "this migration's down() leaves its column behind". It does not: by path, 20 checks, 0 failed,
# 0 inconclusive on 10.11.14-MariaDB.
if [ -n "${SOCK}" ]; then
  CLI_CONN=(--socket="${SOCK}" --user="${USER_NAME}" --pass="${PASS}")
else
  CLI_CONN=(--host="${HOST}" --port="${PORT}" --user="${USER_NAME}" --pass="${PASS}")
fi

# GAP-295 -- verify-theme-publish-concurrency-on-mysql WAS THE ONE FLAKY SCRIPT ON THIS ROSTER,
# and the finding was not a failing assertion but a VARYING one: the same tree and server reported
# "20 checks, 0 failed" and then "18 checks, 1 failed". MEASURED ROOT CAUSE: its racing child had to
# boot a Laravel application before parking, and the parent released the barrier after a FIXED
# usleep(400000). Boot-to-barrier measures 363-476ms on an IDLE machine -- 6 of 10 samples already
# over the 400ms budget -- and 1997-6592ms under the load of a roster run, 5x to 16x over. So under
# the very conditions this roster creates the child found `.go` already present, never parked, and
# the two publishes ran at whatever offset the scheduler produced. The dangerous outcome was the
# PASS: a late child is usually refused cleanly, so the assertions held while measuring something
# that was not a race.
#
# Now a two-phase handshake (the child writes `.racer-ready` BEFORE waiting; the parent blocks on
# it), an assertion that the child really was parked, every check unconditional, and the check COUNT
# declared and asserted so an aborted section is a named shortfall instead of a quieter pass.
for label in \
  verify-gate-f-migrations-on-mysql \
  verify-interrupted-theme-migration-on-mysql \
  verify-theme-cache-flush-after-commit-on-mysql \
  verify-theme-order-theme-id-migration-on-mysql \
  verify-theme-publish-concurrency-on-mysql \
  verify-theme-version-concurrency-on-mysql \
  verify-theme-version-integrity-on-mysql \
  verify-pos-sale-claim-concurrency-on-mysql \
  verify-store-wallet-provisioning-on-mysql \
  verify-dashboard-top-sales-scope-on-mysql
do
  run_one "${label}" "SESSION_DRIVER=array" -- "${CLI_CONN[@]}"
done

# Its own CEU_* convention, and it DOES speak unix socket.
run_one verify-customer-email-uniqueness-on-mysql \
  "CEU_MYSQL_SOCKET=${SOCK}" "CEU_MYSQL_HOST=${HOST}" "CEU_MYSQL_PORT=${PORT}" \
  "CEU_MYSQL_USERNAME=${USER_NAME}" "CEU_MYSQL_PASSWORD=${PASS}"

# THESE TWO ARE TCP-ONLY AND WILL REPORT **SKIPPED**, NOT PASSED, AGAINST A DEFAULT MariaDB.
# Neither accepts a socket, and MariaDB's root authenticates through `unix_socket`, which PDO
# cannot use over TCP -- verify-dashboard-rankings' own docblock says so and asks for a dedicated
# account. To make them measure something, create one on the throwaway server and point them at it:
#
#   mysql -u root -e "CREATE USER 'ssprobe'@'127.0.0.1' IDENTIFIED BY 'ssprobe'; \
#                     GRANT ALL PRIVILEGES ON *.* TO 'ssprobe'@'127.0.0.1'; FLUSH PRIVILEGES;"
#   SS_PROBE_USER=ssprobe SS_PROBE_PASS=ssprobe MYSQL_USER_NAME=ssprobe MYSQL_PASS=ssprobe \
#     tests/Support/Security/bin/run-mysql-verifications.sh
#
# Verified that way on 10.11.14-MariaDB: rankings 13 checks 0 failed; reserved-role-name all
# checks passed. Left as a documented SKIP by default rather than creating an account as a side
# effect of running the suite.
run_one verify-dashboard-rankings-on-mysql \
  "SS_PROBE_USER=${SS_PROBE_USER:-ssprobe}" "SS_PROBE_PASS=${SS_PROBE_PASS:-ssprobe}"

# NOTE: verify-reserved-role-name-collation-on-mysql is NOT added here -- it was already on the
# roster further up, and adding it again ran it twice and listed it twice in the summary. Its
# collation fix (it asked for MySQL 8's `utf8mb4_0900_ai_ci`, which MariaDB does not implement,
# instead of the `utf8mb4_unicode_ci` this application actually configures) is in the script.
# To make it MEASURE rather than skip on MariaDB, give the run the probe account described above.

# ===========================================================================================
# GAP-121 / P04-EV-01 -- the four money paths. The row: "Coupon allocation, tax banding, refund
# totals and the new transaction boundary have no MySQL verification; production is MySQL with
# CACHE_STORE=database." These four close that, one script per path. Each states in its own header
# what about its path is GENUINELY engine-specific and what is not, because padding a harness with
# properties that do not depend on the engine is how a directory of evidence stops being evidence.
#
# Two of them MEASURE AND DO NOT FIX, deliberately. The tax band is an open owner decision -- the
# double-applied band charges 267.00 where 245.00 is correct, pinned by
# Gap143ShippingTaxOrderTotalsTest so a fix cannot pass unnoticed -- and CLAUDE.md §2 reserves
# "material changes to prices/payments" to the owner. verify-tax-band-totals also REPORTS a second,
# separate finding it did not fix: tax_methods.priority is a VARCHAR, so the server's ORDER BY is
# lexicographic and "10" precedes "9". verify-refund-total-coercion likewise reports that a NULL
# orders.final_price gives a ZERO refund ceiling.
run_one verify-coupon-allocation-on-mysql \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=coupon_allocation_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

run_one verify-tax-band-totals-on-mysql \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=tax_band_totals_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

run_one verify-refund-total-coercion-on-mysql \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=refund_total_coercion_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

# Two NAMED CONNECTIONS, so there is a real second server session to observe the boundary from --
# the thing phpunit.xml's SQLite :memory: cannot provide at all, and the reason the suite's own
# OrderWriteTransactionTest docblock calls its assertions structural.
run_one verify-order-write-transaction-on-mysql \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=order_write_transaction_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

# GAP-294 -- WHAT A NULL `orders.final_price` MEANS. A null gives a ZERO refund ceiling, so every
# refund on that order is refused 422 (measured by verify-refund-total-coercion). This script
# MEASURES AND RECOMMENDS; it applies no migration, because the proposed `NOT NULL DEFAULT 0` is not
# schema-only -- it REWRITES existing NULLs to 0, irreversibly, and a NULL total ("unknown") is a
# different claim from a 0.00 total ("nothing to pay"). It also establishes that the state is NOT
# reachable through the application: no first-party line assigns a null to the attribute, and the
# one function every order write passes its total through cannot return null. The deciding figure --
# how many production rows carry a NULL -- is NOT measurable in this tree (incident #120 forbids a
# dump here), so the script prints the query for an operator instead.
run_one verify-null-final-price-ceiling-on-mysql \
  "FRESH_MYSQL_SOCKET=${SOCK}" "FRESH_MYSQL_HOST=${HOST}" "FRESH_MYSQL_PORT=${PORT}" \
  "FRESH_MYSQL_DATABASE=null_final_price_verify" "FRESH_MYSQL_USERNAME=${USER_NAME}" "FRESH_MYSQL_PASSWORD=${PASS}"

echo "==========================================================================="
echo "passed: ${#PASSED[@]}   failed: ${#FAILED[@]}   skipped: ${#SKIPPED[@]}"
if [ "${#SKIPPED[@]}" -gt 0 ]; then
  printf '  SKIPPED (measured nothing): %s\n' "${SKIPPED[@]}"
fi

# ===========================================================================================
# COMPLETENESS. EVERY SCRIPT ON DISK IS EITHER INVOKED ABOVE OR NAMED HERE WITH A REASON.
#
# Without this, a script could be written, committed and never run, while this roster went on
# printing "every MySQL verification script reported all assertions held" -- which is what
# happened to fourteen of them. The roster is hand-written on purpose (each script has its own
# env prefix and its own prose explaining why it exists), so the guard against drift has to be a
# check rather than a convention.
#
# The two exemptions need a RESTORED PRODUCTION DATABASE, which this container must not contain:
# incident #120 is an exposed database dump, and committing or restoring one here is forbidden.
# They are not skippable-by-connection like the TCP pair above -- they have no input at all.
declare -a NOT_RUNNABLE_WITHOUT_A_PRODUCTION_RESTORE=(
  verify-gate-f-schema-on-mysql          # takes a restored database NAME to inspect; no name, no subject
  verify-p1sec44-reconciliation-on-mysql # wants a production-shaped dump at storesyria_ecommercego.sql
)

declare -a UNACCOUNTED=()
for f in tests/Support/Security/bin/*-on-mysql.php; do
  base="$(basename "${f}" .php)"
  seen=0
  for inv in "${INVOKED[@]}"; do [ "${inv}" = "${base}" ] && seen=1 && break; done
  for ex in "${NOT_RUNNABLE_WITHOUT_A_PRODUCTION_RESTORE[@]}"; do [ "${ex}" = "${base}" ] && seen=1 && break; done
  [ "${seen}" -eq 0 ] && UNACCOUNTED+=("${base}")
done

if [ "${#UNACCOUNTED[@]}" -gt 0 ]; then
  echo
  echo "INCOMPLETE ROSTER: these scripts exist and this runner never invokes them."
  printf '  %s\n' "${UNACCOUNTED[@]}"
  echo "Add a run_one call for each, or name it in NOT_RUNNABLE_WITHOUT_A_PRODUCTION_RESTORE with"
  echo "a reason. A script nobody runs is not evidence."
  exit 1
fi

if [ "${#FAILED[@]}" -gt 0 ]; then
  printf '  FAILED: %s\n' "${FAILED[@]}"
  exit 1
fi
echo "every MySQL verification script on disk is accounted for, and every script that measured"
echo "anything reported all assertions held."
