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
# IT DESTROYS THIS FIXTURE. Not "may" -- it did, on 2026-11-05 at 10:42.
#
# phpunit.xml pins the suite to sqlite `:memory:` with CACHE_STORE=array. A real environment
# variable WINS over phpunit.xml, so an exported DB_DATABASE points the WHOLE SUITE at this file.
# The suite then does what a test suite does: it migrates a clean schema. `p2dev.sqlite` came out
# the other side with all 133 tables present and EVERY ROW GONE -- no merchant, no store, no
# orders -- and `set-admin-preview-state.php` answered `error: no user merchant@example.test` for
# every harness that depends on it.
#
# The visible symptom is milder than the real damage and that is the trap:
#
#     with the four variables exported   297 failed, 899 passed   <- looks like a code regression
#     same commit, same minute, without  1196 passed
#
# A reader sees 297 failures, re-runs without the leak, sees green, and concludes it was a fluke.
# The fixture is already gone by then, and it is GITIGNORED, so `git status` says nothing.
#
# RECOVERY, in this order:
#   1. `database/seed-p2dev.php` is the canonical creator (see phase-2/RESUME-POINT-2026-10-27.md).
#   2. Failing that, a copy: the one used on 2026-11-05 was an earlier snapshot of this file.
#   3. EITHER WAY, THEN:
#        rm -rf storage/framework/cache/data/*
#        docs/development/phase-2.5/tools/seed-dev-interaction-fixtures.php
#      The cache clear is NOT optional. 79 of the 95 models are `Cachable`, the file cache was
#      populated while the database was empty, and the seeder refused with "fixture is missing a
#      store or merchant@example.test" against a restored database that demonstrably had both.
#
#   4. AND THEN DELETE THE TWO PREVIEW SETTINGS ROWS. This step is new on 2026-11-06 and it cost
#      an hour, because every symptom points at the stylesheets rather than at ownership:
#
#        php -r '$d=new PDO("sqlite:database/p2dev.sqlite");
#                $d->exec("delete from settings where name in (\"cust_darklayout\",\"SITE_RTL\")");'
#        php artisan cache:clear        # with the three variables PREFIXED, never exported
#
#      WHY. `getAdminAllSetting()` (helper.php:740) filters on `created_by`, and `creatorId()`
#      returns a type-`admin` user's OWN id -- so the merchant fixture needs rows owned by HER
#      (id 2). After a rebuild the two rows come back owned by id 1, and
#      `App\Services\SettingOwnership::keep` -- P1-PLT-07, a deliberate security control -- makes
#      an UPDATE unable to change a row's owner: "An update may change the value; it may never
#      change who the row belongs to." So `set-admin-preview-state.php` reports success, the value
#      really is written, and every harness still reads the LIGHT theme and fails its own premise
#      with `data-ss-theme [null]`. Deleting the rows is the only route, because the tool's INSERT
#      path stamps the first `type = 'admin'` user and `keep` leaves a console insert alone.
#
#      MEASURED: before, `check-dark-form-surfaces-live.cjs` could not get past its premises;
#      after the delete and one run of the state tool, owner=2 and it reports 20 checks 0 failed.
#      Do NOT "fix" this by making the tool rewrite `created_by` -- that is the thing P1-PLT-07
#      exists to prevent, and it was tried first.
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
