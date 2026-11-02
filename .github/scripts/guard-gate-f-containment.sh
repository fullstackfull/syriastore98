#!/usr/bin/env bash
#
# GATE F GUARD -- runs with nothing but bash, grep, find and php. INVERTED 2026-11-02.
#
# ════════════════════════════════════════════════════════════════════════════════════════
#  WHAT THIS USED TO ASSERT, AND WHY IT NO LONGER DOES.
# ════════════════════════════════════════════════════════════════════════════════════════
#
# Until 2026-11-02 this script asserted that **no** discoverable migration creates any of the
# six GATE F money tables, because their column types were not derivable from $fillable and
# inventing them was forbidden. Its own closing instruction was:
#
#   "WHEN GATE F OPENS, update this script and GateFSchemaContainmentTest.php in the SAME
#    commit that adds the real migrations, by removing the table from GATE_F_TABLES below.
#    Do not relax the match to make a new migration pass."
#
# GATE F opened. The types are no longer guesses: they were MEASURED from information_schema
# on a restored copy of the production dump on 2026-10-24, recorded in
# docs/GATE-F-VERIFIED-SCHEMA.md section 2, and carried verbatim by
# App\Support\Database\GateFProductionSchema.
#
# AND THE UPDATE WAS MISSED ON THE FIRST ATTEMPT, which is worth recording here because it is
# the reason this script exists at all. The PHPUnit twin was inverted and THIS FILE WAS NOT, so
# the `static` job failed on all six tables while every local PHPUnit run was green. The
# instruction above said "in BOTH" and was followed in one place. CI caught it; a census keyed
# on the test name and not on the property is how that happens.
#
# ════════════════════════════════════════════════════════════════════════════════════════
#  WHY THIS IS A SHELL SCRIPT, AND WHY THAT MATTERS MORE NOW THAN BEFORE.
# ════════════════════════════════════════════════════════════════════════════════════════
#
# tests/Unit/Database/GateFSchemaContainmentTest.php asserts the same properties, but it only
# runs inside the `tests` job, which is gated behind the ALLOW_COMPOSER_INSTALL repository
# variable because three packages in composer.lock cannot be fetched on a restricted runner.
# On most runners the strongest guard this project has over six money-bearing tables never
# executes. This script needs no vendor/, so it runs in the `static` job on every push and
# pull request -- which makes it the copy that actually holds the line.
#
# ════════════════════════════════════════════════════════════════════════════════════════
#  THE PROPERTIES, now the inverse of the original and strictly stronger.
# ════════════════════════════════════════════════════════════════════════════════════════
#
#   1. Each of the six money tables is created by EXACTLY ONE discoverable migration.
#      ZERO means the migration was reverted or renamed, so a fresh environment silently
#      lacks a money table production has. MORE THAN ONE is the ORIGINAL GATE F hazard in the
#      only form it can still take: whichever runs first creates the table, the other becomes
#      a hasTable() no-op, and if the loser is the measured one the wrong types become
#      permanent and invisible.
#
#   2. Each of those migrations calls GateFSchemaGuard::assertCompatible(). A bare
#      Schema::hasTable() guard proves a CREATE would fail, not that skipping it is safe.
#
#   3. No GATE F migration drops anything on rollback. Measured, not reasoned: two of the
#      original drafts dropped a column BECAUSE it existed, and migrate:rollback against a
#      copy of production destroyed all 45 products.cost_price values and both delivery_boys
#      commission columns with their data, having created nothing.
#
#   4. cod_collection_events is still created by NOTHING. It is the one object production
#      genuinely lacks (decision D-15) and creating it silently activates COD settlement.
#
#   5. The superseded drafts are still parked, still non-runnable, and the README still says
#      SUPERSEDED -- so nobody promotes a provisional money-table definition over a measured
#      one.
#
#   6. NO TEST CREATES A MONEY TABLE. Added 2026-11-02, and it is the property this guard was
#      missing: eleven test files carried
#          if (! Schema::hasTable('<money table>')) { Schema::create(...); }
#      as a workaround while the tables were undeclared. Those blocks built the table from
#      COLUMNS ONLY -- none of production's 3 unique keys, none of its 12 foreign keys -- so
#      removing or renaming one migration would have left the suite GREEN against a fabricated
#      money schema. Property 1 cannot see them, because a Schema::create() inside a test is
#      not a discoverable migration.
#
#      tests/Unit/Database/GateFCoverageLedgerTest asserts the same thing and more (it also
#      forbids BRANCHING on absence), but it runs only in the `tests` job -- see the paragraph
#      above on why that job is gated. This is the copy that runs everywhere.
#
# COMMENTS ARE STRIPPED before properties 2 and 3 are evaluated, with `php -r` and
# token_get_all. Every one of these migrations EXPLAINS in prose what its down() deliberately
# does not do, so a raw grep for `dropIfExists` matches the explanation. Three ratchets in this
# repository have been tripped or satisfied by their own comments; this is not going to be the
# fourth.
#
# Usage: guard-gate-f-containment.sh [repo-root]
set -euo pipefail

ROOT="${1:-.}"

GATE_F_TABLES=(
  store_wallets
  store_wallet_transactions
  store_withdrawals
  store_order_financials
  currency_rates
  delivery_earnings
)

# The nine non-money SCHEMA-01 tables are checked for properties 2 and 3 as well: they hold
# live production data too, so a destructive down() on zid_orders is no better than one on a
# wallet. They are NOT subject to property 1's "exactly one", because audit_logs is created by
# a migration that predates GATE F and the count is asserted by the PHPUnit twin.
GATE_F_OTHER_TABLES=(
  audit_logs
  otp_integrations
  chatwoot_integrations
  chatwoot_notification_templates
  delivery_boy_devices
  delivery_boy_notifications
  zid_stores
  zid_orders
  zid_events
)

fail=0

# ---------------------------------------------------------------------------------------
# Collect every migration file Laravel can actually discover: the root tree plus each
# addon module's own tree. Mirrors GateFSchemaContainmentTest::discoverableMigrationFiles()
# and MigrationHygieneTest::allMigrationFiles() so all three agree on what "a migration" is.
# The parked drafts are excluded by extension: they are *.php.draft, not *.php.
# ---------------------------------------------------------------------------------------
migrations_list="$(mktemp)"
trap 'rm -f "$migrations_list"' EXIT

find "$ROOT/database/migrations" -maxdepth 1 -name '*.php' -type f >> "$migrations_list" 2>/dev/null || true
find "$ROOT/packages/workdo" -path '*/src/database/migrations/*.php' -type f >> "$migrations_list" 2>/dev/null || true

count="$(wc -l < "$migrations_list" | tr -d ' ')"

# Refuse to pass vacuously. If the globs matched nothing -- wrong cwd, a moved tree, a
# renamed directory -- every check below would trivially succeed while verifying nothing.
if [ "$count" -lt 200 ]; then
  echo "::error::Found only $count migration files. Expected 200+. The scan is wrong, so"
  echo "::error::this guard would pass without checking anything. Refusing to pass."
  exit 1
fi
echo "Scanning $count discoverable migration files across both trees."

# Blank out comments, preserving everything else. Falls back loudly rather than silently.
code_only() {
  if command -v php >/dev/null 2>&1; then
    php -r '
      $out = "";
      foreach (token_get_all(file_get_contents($argv[1])) as $t) {
        if (is_array($t)) {
          $out .= in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)
            ? str_repeat(" ", strlen($t[1])) : $t[1];
          continue;
        }
        $out .= $t;
      }
      echo $out;
    ' "$1" 2>/dev/null || cat "$1"
  else
    echo "::warning::php is not on PATH, so comments cannot be stripped: properties 2 and 3" >&2
    echo "::warning::are evaluated against raw text and may report a migration for its own" >&2
    echo "::warning::explanatory prose." >&2
    cat "$1"
  fi
}

creators_of() {
  local table="$1"
  local pattern="Schema::[[:space:]]*(connection[[:space:]]*\([^)]*\)[[:space:]]*->[[:space:]]*)?create(IfNotExists)?[[:space:]]*\([[:space:]]*['\"]${table}['\"]"
  grep -ElE "$pattern" $(cat "$migrations_list") 2>/dev/null || true
}

# ---------------------------------------------------------------------------------------
# 1. Each money table is created by EXACTLY ONE discoverable migration.
# ---------------------------------------------------------------------------------------
for table in "${GATE_F_TABLES[@]}"; do
  hits="$(creators_of "$table")"
  n=0
  [ -n "$hits" ] && n="$(printf '%s\n' "$hits" | grep -c . || true)"

  if [ "$n" -ne 1 ]; then
    fail=1
    echo "::error::'${table}' must be created by exactly ONE discoverable migration, and is"
    echo "::error::created by ${n}:"
    if [ "$n" -eq 0 ]; then
      echo "::error::    (nothing)"
      echo "::error::ZERO means the GATE F migration was reverted or renamed. The table exists"
      echo "::error::in production with live data, so a fresh environment no longer gets it."
    else
      while IFS= read -r f; do [ -n "$f" ] && echo "::error::    $f"; done <<< "$hits"
      echo "::error::MORE THAN ONE is the original GATE F hazard in the only form it can still"
      echo "::error::take: whichever runs first creates the table, the other becomes a"
      echo "::error::hasTable() no-op, and if the loser is the measured one the wrong types"
      echo "::error::become permanent and invisible. Delete the duplicate; do not reorder it."
    fi
  fi
done

# ---------------------------------------------------------------------------------------
# 2 and 3. Every migration that creates ANY of the fifteen must verify structure rather
#          than name, and must not drop anything on rollback.
# ---------------------------------------------------------------------------------------
checked_list="$(mktemp)"
trap 'rm -f "$migrations_list" "$checked_list"' EXIT

for table in "${GATE_F_TABLES[@]}" "${GATE_F_OTHER_TABLES[@]}"; do
  creators_of "$table" >> "$checked_list"
done
sort -u "$checked_list" -o "$checked_list"

for f in $(cat "$checked_list"); do
  [ -n "$f" ] || continue
  src="$(code_only "$f")"

  if ! printf '%s' "$src" | grep -q 'GateFSchemaGuard::assertCompatible'; then
    fail=1
    echo "::error::$f creates a SCHEMA-01 table but never calls"
    echo "::error::GateFSchemaGuard::assertCompatible(). Schema::hasTable() alone proves a"
    echo "::error::CREATE would fail, not that skipping it is safe: a wrong-typed table would"
    echo "::error::make this migration a silent no-op while migrate:status reported it 'Ran'."
  fi

  for needle in 'dropIfExists' 'Schema::drop' 'dropColumn' 'truncate'; do
    if printf '%s' "$src" | grep -q -- "$needle"; then
      fail=1
      echo "::error::$f calls ${needle}(). Every one of the fifteen SCHEMA-01 tables exists in"
      echo "::error::production WITH LIVE DATA, so a rollback that dropped one is a destructive"
      echo "::error::production operation wearing a routine command's clothes. Two of the"
      echo "::error::original drafts did exactly that, measured."
    fi
  done
done

# ---------------------------------------------------------------------------------------
# 4. cod_collection_events must still be created by nothing.
# ---------------------------------------------------------------------------------------
cod_hits="$(creators_of cod_collection_events)"
if [ -n "$cod_hits" ]; then
  fail=1
  echo "::error::'cod_collection_events' is created by:"
  while IFS= read -r f; do [ -n "$f" ] && echo "::error::    $f"; done <<< "$cod_hits"
  echo "::error::THIS ACTIVATES COD SETTLEMENT. The table is absent from production BY DESIGN"
  echo "::error::(decision D-15, measured in docs/GATE-F-VERIFIED-SCHEMA.md section 5):"
  echo "::error::CodCollectionService cannot record an event without it, codMayBeSettled()"
  echo "::error::returns false, and a COD order therefore cannot be settled by a collection"
  echo "::error::event. It is also the premise of the open Phase 01 item R2 -- 17 COD orders"
  echo "::error::recorded paid with no collection evidence, which CANNOT exist while this table"
  echo "::error::does not. Creating it is an owner decision with a settlement consequence."
fi

if [ "$fail" -ne 0 ]; then
  cat <<'MSG'
::error::
::error::THE MEASUREMENT IS THE AUTHORITY -- docs/GATE-F-VERIFIED-SCHEMA.md section 2, carried
::error::verbatim by App\Support\Database\GateFProductionSchema. Do not relax a check here to
::error::make a migration pass, and do not edit a migration to improve on production: widening
::error::a live money column is a separate, deliberate change with its own rounding
::error::consequences for the rows already stored.
::error::
::error::This script and tests/Unit/Database/GateFSchemaContainmentTest.php assert the same
::error::properties and must be changed in the SAME commit. The PHPUnit twin runs only in the
::error::`tests` job; this one runs everywhere, which is why it is the one that catches a miss.
MSG
  exit 1
fi

# ---------------------------------------------------------------------------------------
# 5. The superseded drafts must still be parked, still be drafts, and still say so.
# ---------------------------------------------------------------------------------------
pending="$ROOT/database/migrations/_gate_f_pending"

if [ ! -d "$pending" ]; then
  echo "::error::$pending is gone. It is the only record of what these definitions looked like"
  echo "::error::BEFORE they were measured -- 22 money-scale contradictions, four enums turned"
  echo "::error::into varchar(255), and none of production's 3 unique keys or 12 foreign keys."
  echo "::error::That record is why the promoted migrations are generated from a measurement."
  exit 1
fi

runnable="$(find "$pending" -maxdepth 1 -name '*.php' -type f 2>/dev/null || true)"
if [ -n "$runnable" ]; then
  echo "::error::A GATE F draft is named *.php and can therefore match Laravel's '*_*.php'"
  echo "::error::migration glob if this directory is ever registered as a migration path."
  echo "::error::Drafts must be *.php.draft:"
  echo "$runnable" | while IFS= read -r f; do [ -n "$f" ] && echo "::error::    $f"; done
  exit 1
fi

drafts="$(find "$pending" -maxdepth 1 -name '*.php.draft' -type f 2>/dev/null | wc -l | tr -d ' ')"
if [ "$drafts" -eq 0 ]; then
  echo "::error::$pending holds no *.php.draft files."
  exit 1
fi

if [ ! -f "$pending/README.md" ] || ! grep -q 'SUPERSEDED' "$pending/README.md"; then
  echo "::error::$pending/README.md does not say the drafts are SUPERSEDED. A reader who finds a"
  echo "::error::provisional money-table definition with no such note may promote it over the"
  echo "::error::measured migration."
  exit 1
fi

# ---------------------------------------------------------------------------------------
# 6. No test may CREATE a GATE F money table. See the header for what this closes.
#
# Comments are stripped first, for the reason property 2 and 3 strip them: several of those
# files now EXPLAIN in prose what they no longer do, and a raw grep matches the explanation.
# ---------------------------------------------------------------------------------------
if [ -d "$ROOT/tests" ]; then
  test_offenders=""

  while IFS= read -r tf; do
    [ -n "$tf" ] || continue

    # Skip the ledger test itself: it names the tables in order to forbid them.
    case "$tf" in
      */tests/Unit/Database/GateFCoverageLedgerTest.php) continue ;;
    esac

    code="$(php -r '
      $s = file_get_contents($argv[1]); $out = "";
      foreach (token_get_all($s) as $t) {
        if (is_array($t)) {
          $out .= in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)
            ? str_repeat("\n", substr_count($t[1], "\n")) : $t[1];
        } else { $out .= $t; }
      }
      echo $out;' "$tf" 2>/dev/null || true)"

    for t in "${GATE_F_TABLES[@]}"; do
      if printf '%s' "$code" | grep -Eq "Schema::create\\(\\s*['\"]${t}['\"]"; then
        test_offenders="${test_offenders}${tf} creates ${t}"$'\n'
      fi
    done
  done <<< "$(find "$ROOT/tests" -name '*.php' -type f 2>/dev/null || true)"

  if [ -n "$test_offenders" ]; then
    echo "::error::A TEST CREATES A GATE F MONEY TABLE. That is forbidden since GATE F closed on"
    echo "::error::2026-11-02: a Schema::create() inside a test is not a discoverable migration, it"
    echo "::error::carries none of production's unique keys or foreign keys, and property 1 above"
    echo "::error::cannot see it -- so it would make the suite green against a fabricated money"
    echo "::error::schema if the real migration were ever reverted. Assert the migration is present"
    echo "::error::instead: assertTrue(Schema::hasTable(...))."
    printf '%s' "$test_offenders" | while IFS= read -r line; do
      [ -n "$line" ] && echo "::error::    $line"
    done
    exit 1
  fi
fi

echo "GATE F intact: each of the 6 money tables is created by exactly one discoverable"
echo "migration; every migration that creates one of the 15 verifies structure and drops"
echo "nothing on rollback; cod_collection_events is created by nothing; no test creates a money"
echo "table; and $drafts superseded drafts are still parked and non-runnable."
