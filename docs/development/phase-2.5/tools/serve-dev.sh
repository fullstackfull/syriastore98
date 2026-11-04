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
