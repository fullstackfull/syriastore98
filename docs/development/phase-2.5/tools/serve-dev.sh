#!/bin/sh
#
# The merchant dev server the Phase 2.5 live harnesses expect on 127.0.0.1:8899.
# =================================================================================================
#
# Every `*-live.cjs`, `probe-*.cjs`, `render-matrix.cjs` and `find-offbrand-colours.cjs` in this
# directory assumes a server is already running there, signed-in-able as merchant@example.test.
# NOTHING IN THE REPOSITORY SAID HOW TO START IT, and that cost a round after a container restart:
# the server came up, bound the port, and answered 500 on every route.
#
# THE THREE VARIABLES ARE NOT OPTIONAL AND ONE OF THEM IS NOT OBVIOUS.
#
#   DB_DATABASE    .env sets DB_CONNECTION=sqlite and NO DB_DATABASE, so the default is
#                  database/database.sqlite, which does not exist. The fixture is p2dev.sqlite.
#
#   CACHE_STORE    .env says `database`. The harness tools write `cust_darklayout` through
#                  Eloquent and then read it back from the server; they pass CACHE_STORE=file, and
#                  a server on a different cache store serves the pre-write row. That is the
#                  defect recorded in PHASE-02 section 4 -- "my own dark-mode screenshots were not
#                  dark" -- and it reproduces the moment these disagree.
#
#   SESSION_DRIVER .env says `database`. THE p2dev FIXTURE HAS 133 TABLES AND `sessions` IS NOT
#                  ONE OF THEM, and no migration under database/migrations/ creates it. Without
#                  this line every route answers 500 with "no such table: sessions" -- including
#                  /login, so the failure looks like a broken application rather than a missing
#                  dev setting.
#
# DO NOT `export` THESE AND THEN RUN THE TEST SUITE IN THE SAME SHELL.
#
# MEASURED, by reproducing it on purpose rather than guessing after the fact:
#
#     with DB_CONNECTION/DB_DATABASE/CACHE_STORE/SESSION_DRIVER exported   297 failed, 899 passed
#     same commit, same minute, without them                              1196 passed
#
# phpunit.xml pins the suite to sqlite `:memory:` with CACHE_STORE=array. A real environment
# variable WINS over phpunit.xml, so an exported DB_DATABASE silently points the whole suite at
# the dev fixture -- 133 tables of seeded merchant data instead of a clean migrated schema -- and
# CACHE_STORE=file leaves `Cachable` models serving rows between tests. It fails loudly and in
# bulk, which is the good case; what makes it worth a warning is that the failures look like a
# code regression and nothing in the output names the cause.
#
# Run the harness in its own shell, or prefix the variables onto the single command that needs
# them (`DB_DATABASE=... node render-matrix.cjs ...`) rather than exporting them.
#
# usage:  docs/development/phase-2.5/tools/serve-dev.sh   (leave it running; harnesses connect to it)
#
set -e
export DB_CONNECTION=sqlite
export DB_DATABASE="$(cd "$(dirname "$0")/../../../.." && pwd)/database/p2dev.sqlite"
export CACHE_STORE=file
export SESSION_DRIVER=file

[ -f "$DB_DATABASE" ] || { echo "fixture missing: $DB_DATABASE" >&2; exit 1; }

cd "$(dirname "$0")/../../../.."
exec php artisan serve --host=127.0.0.1 --port=8899
