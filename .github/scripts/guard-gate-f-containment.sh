#!/usr/bin/env bash
#
# GATE F CONTAINMENT GUARD -- runs with nothing but bash, grep and find.
#
# WHY THIS EXISTS AS A SHELL SCRIPT when tests/Unit/Database/GateFSchemaContainmentTest.php
# already asserts the same property: that PHPUnit test only runs inside the `tests` job,
# which is gated behind the ALLOW_COMPOSER_INSTALL repository variable because three
# packages in composer.lock cannot be fetched on a restricted runner. In other words, on
# most runners the strongest guard the project has over six money-bearing tables never
# executes at all. This script needs no vendor/, so it runs in the `static` job on every
# push and pull request.
#
# THE INVARIANT. Six tables are read and written by live code and created by NO migration:
#   store_wallets, store_wallet_transactions, store_withdrawals,
#   store_order_financials, currency_rates, delivery_earnings
# Their column types are not derivable from $fillable, so inventing them is forbidden.
# Provisional drafts are parked in database/migrations/_gate_f_pending/*.php.draft, where
# Laravel's non-recursive `*_*.php` migration glob cannot reach them.
#
# WHY IT IS WORTH BLOCKING ON. Seven of the suite's eight skips exist only because three
# of these tables are absent, so there is standing pressure to "fix" the skips by creating
# the tables. The surrounding migrations use Schema::hasTable() guards, which means a
# wrong definition that runs first makes the later CORRECT migration a silent no-op -- the
# guessed column types then become permanent AND invisible. A green board bought that way
# is far worse than a reported gap on six money tables.
#
# WHEN GATE F OPENS, update this script and GateFSchemaContainmentTest.php in the SAME
# commit that adds the real migrations, by removing the table from GATE_F_TABLES below.
# Do not relax the match to make a new migration pass.
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

# ---------------------------------------------------------------------------------------
# 1. No discoverable migration may CREATE any GATE F table.
#
# Matches Schema::create and Schema::createIfNotExists, in either quote style, allowing
# interior whitespace and an optional ->connection(...) hop. Anchored on the closing quote
# so `store_wallets` does not match `store_wallets_archive`, and restricted to create so a
# legitimate Schema::table() alter is not flagged.
# ---------------------------------------------------------------------------------------
for table in "${GATE_F_TABLES[@]}"; do
  pattern="Schema::[[:space:]]*(connection[[:space:]]*\([^)]*\)[[:space:]]*->[[:space:]]*)?create(IfNotExists)?[[:space:]]*\([[:space:]]*['\"]${table}['\"]"

  hits="$(grep -ElE "$pattern" $(cat "$migrations_list") 2>/dev/null || true)"

  if [ -n "$hits" ]; then
    fail=1
    echo "::error::A discoverable migration creates the GATE F table '${table}':"
    while IFS= read -r f; do
      [ -n "$f" ] && echo "::error::    $f"
    done <<< "$hits"
  fi
done

if [ "$fail" -ne 0 ]; then
  cat <<'MSG'
::error::
::error::Inventing these schemas is forbidden: their column types are not derivable from
::error::$fillable, and because the surrounding migrations use Schema::hasTable() guards, a
::error::wrong definition that runs first makes the later CORRECT migration a silent no-op
::error::and freezes the guessed types permanently.
::error::
::error::If GATE F has opened deliberately, remove the table from GATE_F_TABLES in BOTH
::error::.github/scripts/guard-gate-f-containment.sh and
::error::tests/Unit/Database/GateFSchemaContainmentTest.php, in the same commit that adds
::error::the real migration.
MSG
  exit 1
fi

# ---------------------------------------------------------------------------------------
# 2. The drafts must still be parked, and still be drafts. If the staging directory were
#    deleted, check 1 would start passing for the wrong reason: nothing creates the tables
#    because nothing remembers they are owed.
# ---------------------------------------------------------------------------------------
pending="$ROOT/database/migrations/_gate_f_pending"

if [ ! -d "$pending" ]; then
  echo "::error::$pending is gone. The six money tables are still undeclared, so losing"
  echo "::error::the drafts loses the only record of what the schema owes."
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

for table in "${GATE_F_TABLES[@]}"; do
  if ! grep -rqs -- "$table" "$pending"; then
    echo "::error::No parked draft mentions '${table}'. Either the draft was deleted or the"
    echo "::error::table was renamed; both need a deliberate decision, not silence."
    exit 1
  fi
done

echo "GATE F containment intact: 0 of the 6 money tables are created by any discoverable"
echo "migration, and all 6 still have a parked, non-runnable draft ($drafts drafts found)."
