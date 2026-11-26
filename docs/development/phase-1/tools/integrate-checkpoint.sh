#!/usr/bin/env bash
#
# Store Syria — integration checkpoint.
#
# Builds a fresh integration branch from the development head plus every workstream
# branch, then runs the gates that CI runs. Deliberately NOT a push: publication is a
# separate, deliberate act, and `ci.yml` sets concurrency cancel-in-progress so a push
# cancels whatever run is in flight.
#
# It refuses rather than guesses: an unmerged branch, a dirty worktree or a missing
# vendor stops it with a named reason. A checkpoint that half-ran is worse than one
# that did not start, because its green is partial and reads as whole.
#
# Usage:  integrate.sh [--merge-only|--gates-only]
set -uo pipefail

LARAVEL=/home/user/syriastore98
FLUTTER=/home/user/StoreSyria_app98
INT=/home/user/wt/int
INTF=/home/user/wtf/int
DEV_BRANCH=feature/phase-06-saas-super-admin
FLUTTER_DEV=claude/sleepy-goldberg-nrec0f
MODE="${1:-}"

# Every Laravel workstream branch, in the order they are merged. GAP-140's branch keeps
# its own name because the owner handoff names it and it carries the unreviewed-WIP
# history deliberately.
# Merge order matters only for conflict legibility, not correctness: the integrator resolves by
# hand and this script REFUSES rather than guessing. GAP-140's branch keeps its own name because
# the owner handoff names it and it carries the unreviewed-WIP history deliberately.
#
# ws/integrator-gap315 is the integrator's own one-file change to a MySQL evidence script; it is
# listed last so a conflict there cannot mask a workstream's.
LARAVEL_BRANCHES=(
  claude/gap-140-refund-policy-wip
  ws/p06-ws1-apigates
  ws/p06-ws2-authz
  ws/p06-ws3-sfapi
  ws/p06-ws4-routes
  ws/p06-ws5-settings
  ws/p06-ws6-analytics
  ws/p06-ws7-i18n
  ws/p06-ws9-contrast
  ws/p06-ws10-orderstate
  ws/p06-ws11-money
  ws/p06-ws12-recovered
  ws/integrator-gap315
)
FLUTTER_BRANCHES=(
  ws/p06-ws8-flutter
  ws/p06-ws7-i18n
)

fail() { echo "INTEGRATION REFUSED: $*" >&2; exit 2; }
hdr()  { printf '\n========== %s ==========\n' "$*"; }

merge_all() {
  local repo="$1" wt="$2" base="$3" intbranch="$4"; shift 4
  local -a branches=("$@")

  hdr "merge into ${intbranch} (${repo})"
  # `worktree remove` refuses while the directory holds untracked files -- and it always
  # does, because a gate run leaves vendor/, storage/ and the junit logs behind. So remove
  # the registration, then the directory, then prune the stale administrative entry. This
  # is safe precisely BECAUSE the integration worktree is derived: it holds no work that is
  # not already on a workstream branch, which is why it is rebuilt from scratch each time
  # rather than updated in place.
  git -C "$repo" worktree remove --force "$wt" 2>/dev/null
  rm -rf "$wt"
  git -C "$repo" worktree prune 2>/dev/null
  git -C "$repo" branch -D "$intbranch" 2>/dev/null
  # stderr too: `worktree add` writes its checkout progress there, 60k lines of it.
  git -C "$repo" worktree add -b "$intbranch" "$wt" "$base" >/dev/null 2>&1 \
    || fail "could not create the integration worktree at ${wt} (branch ${intbranch}, base ${base})"

  local conflicted=0
  for br in "${branches[@]}"; do
    git -C "$repo" rev-parse --verify --quiet "$br" >/dev/null || { echo "  SKIP   ${br} (does not exist)"; continue; }
    # A branch whose tip equals the integration head has nothing to merge RIGHT NOW -- which is
    # not the same as having nothing to contribute. ws9 was skipped this way while its agent was
    # still working, and its finished commit was absent from the checkpoint with no sign of it.
    # Recorded as SKIP-EMPTY so the run's own output distinguishes "merged nothing" from
    # "never considered".
    if [ "$(git -C "$wt" rev-parse HEAD)" = "$(git -C "$repo" rev-parse "$br")" ]; then
      git -C "$repo" rev-parse "$br" > "${wt}/.integrate-tip-$(echo "$br" | tr '/' '-')"
      echo "  SKIP-EMPTY ${br} @ $(git -C "$repo" rev-parse --short "$br") -- tip equals the integration head; if this workstream is still live its work is NOT in this checkpoint"
      continue
    fi
    if git -C "$wt" merge --no-edit --no-ff "$br" >/dev/null 2>&1; then
      git -C "$repo" rev-parse "$br" > "${wt}/.integrate-tip-$(echo "$br" | tr '/' '-')"
      echo "  OK     ${br}  @ $(git -C "$repo" rev-parse --short "$br")"
    else
      echo "  CONFLICT ${br}:"
      git -C "$wt" diff --name-only --diff-filter=U | sed 's/^/           /'
      git -C "$wt" merge --abort
      conflicted=1
    fi
  done
  [ "$conflicted" -eq 0 ] || fail "one or more branches conflict — resolve by hand, do not let this script guess"

  # Test runs write cover images under uploads/2/ and uploads/uploads/2/, which .gitignore
  # deliberately does NOT ignore because store 2 is a real store id with tracked files.
  git -C "$wt" status --porcelain | awk '/^\?\? uploads\//{print $2}' | while read -r f; do rm -f "$wt/$f"; done

  echo
  echo "  merged head: $(git -C "$wt" rev-parse --short HEAD)"
  echo "  commits on top of ${base}: $(git -C "$wt" log --oneline "${base}..HEAD" | wc -l)"

  # ---------------------------------------------------------------------------------------------
  # A CHECKPOINT AGAINST A MOVING BRANCH PHOTOGRAPHS A HALF-FINISHED BRANCH, and the failure it
  # produces is indistinguishable from a real integration defect.
  #
  # Measured 2026-11-26: a checkpoint merged ws12 at dc55d6137 while that workstream was still
  # committing. Five commits later it reached d66dcd1bb -- titled "the packing list's six strings
  # had no Arabic, and the Unit gate caught it". So the checkpoint's Unit suite went RED on exactly
  # the defect the workstream had already fixed, and 30 minutes of Feature run were spent on a tree
  # CI would never have evaluated (Unit red means Feature is skipped entirely). Seven of thirteen
  # branches were stale by the time the gates finished; one (ws9) had been SKIPPED as "nothing to
  # merge" because its tip still equalled the base, and had committed since.
  #
  # So the tips are recorded at merge time and RE-READ here. A branch that moved during the run
  # invalidates the checkpoint, and this says so rather than letting the gates report on a tree that
  # no longer corresponds to any branch.
  # ---------------------------------------------------------------------------------------------
  local moved=0
  for br in "${branches[@]}"; do
    git -C "$repo" rev-parse --verify --quiet "$br" >/dev/null || continue
    local was now
    was=$(cat "${wt}/.integrate-tip-$(echo "$br" | tr '/' '-')" 2>/dev/null)
    now=$(git -C "$repo" rev-parse "$br")
    if [ -n "$was" ] && [ "$was" != "$now" ]; then
      echo "  STALE  ${br}: merged $(git -C "$repo" rev-parse --short "$was"), now $(git -C "$repo" rev-parse --short "$now")"
      moved=1
    fi
  done
  [ "$moved" -eq 0 ] || fail "one or more branches moved during the merge. This checkpoint is a photograph of work in progress -- wait until every workstream has stopped, then run it again. Do NOT run the gates on it: a red Unit suite here is as likely to be a half-merged branch as a real defect."
}

laravel_gates() {
  # GAP-314: `php artisan test` IS NOT SAFE TO RUN TWICE CONCURRENTLY IN ONE WORKTREE. A
  # Storage::fake() disk lives at storage/framework/testing/disks/, shared by every PHPUnit
  # process in that tree, so two runs collide and PlatformUploaderTypeTest's
  # `assertSame([], $this->stored())` fails exactly like a merge regression. Measured: alone the
  # Security suite is 1 skipped / 7,039 passed / 0 failed. Every gate below therefore runs
  # SEQUENTIALLY in this one worktree, and nothing here may be backgrounded to "save time" --
  # at an integration checkpoint a flapping assertion is indistinguishable from a real conflict,
  # which is the most expensive place in the project to be confused.
  hdr "Laravel gates"
  [ -d "$INT" ] || fail "no integration worktree at ${INT}"
  # Each worktree needs its OWN vendor: composer's autoloader derives $baseDir from
  # __DIR__, so through a SYMLINK it resolves to the main checkout and every test runs
  # against the wrong tree. Measured. Hard-linked copy costs almost no disk.
  if [ ! -f "$INT/vendor/autoload.php" ] || [ -L "$INT/vendor" ]; then
    rm -rf "$INT/vendor"
    cp -al "$LARAVEL/vendor" "$INT/vendor" || fail "could not hard-link vendor into ${INT}"
  fi
  mkdir -p "$INT/bootstrap/cache" "$INT/storage/framework/cache/data" \
           "$INT/storage/framework/sessions" "$INT/storage/framework/views" \
           "$INT/storage/logs" "$INT/storage/app/public"

  local resolved
  resolved=$(cd "$INT" && APP_ENV=testing php -r \
    'require "vendor/autoload.php"; echo (new ReflectionClass("App\\Models\\Setting"))->getFileName();' 2>/dev/null)
  case "$resolved" in
    "$INT"/*) echo "  autoloader resolves inside the integration worktree: OK" ;;
    *) fail "autoloader resolves to ${resolved} — tests would run against the WRONG TREE" ;;
  esac

  ( cd "$INT" && php artisan view:clear >/dev/null 2>&1 )

  hdr "php -l on every tracked PHP file"
  ( cd "$INT" && git ls-files -z '*.php' | xargs -0 -n1 -P4 php -l 2>&1 | grep -v '^No syntax errors' | head -20 )
  echo "  (no output above = clean)"

  hdr "Unit suite (BLOCKING)"
  ( cd "$INT" && APP_ENV=testing php artisan test --testsuite=Unit 2>&1 | grep -vE '^\s*WARN|Metadata' | tail -4 )

  hdr "Contract suite (BLOCKING — the Flutter API contract)"
  ( cd "$INT" && APP_ENV=testing php artisan test --testsuite=Contract 2>&1 | grep -vE '^\s*WARN|Metadata' | tail -4 )

  hdr "Cross-repo API contract"
  ( cd "$INT" && php tests/Support/ApiContract/bin/check-contract.php 2>&1 | tail -6 )

  hdr "Feature suite (BLOCKING on the known-outcome SET)"
  ( cd "$INT" && APP_ENV=testing php artisan test --testsuite=Feature \
      --log-junit storage/logs/feature-junit.xml \
      --log-teamcity storage/logs/feature-teamcity.txt 2>&1 \
      | grep -vE '^\s*WARN|Metadata' | tail -30 )

  hdr "The Feature outcome set must match the record (BLOCKING)"
  ( cd "$INT" && php .github/scripts/assert-known-test-outcomes.php \
      storage/logs/feature-junit.xml .github/known-test-outcomes.json \
      storage/logs/feature-teamcity.txt 2>&1 | tail -20 )

  hdr "composer audit ceiling"
  ( cd "$INT" && COMPOSER_ALLOW_SUPERUSER=1 composer audit --locked --format=plain 2>&1 \
      | grep -iE 'Found [0-9]+ security|no security' )
  echo "  ceiling in ci.yml: $(grep -oE "AUDIT_CEILING: '[0-9]+'" "$INT/.github/workflows/ci.yml" | tail -1)"

  hdr "MySQL/MariaDB roster"
  ( cd "$INT" && MYSQL_SOCK=/var/run/mysqld/mysqld.sock MYSQL_USER_NAME=root MYSQL_PASS= \
      SS_PROBE_USER=ssprobe SS_PROBE_PASS=ssprobe \
      tests/Support/Security/bin/run-mysql-verifications.sh 2>&1 | tail -6 )
}

flutter_gates() {
  hdr "Flutter gates"
  [ -d "$INTF" ] || fail "no Flutter integration worktree at ${INTF}"
  export PATH="/opt/flutter-sdk/flutter/bin:$PATH"
  ( cd "$INTF" && flutter pub get 2>&1 | tail -2 )
  hdr "flutter analyze — 0 errors, 0 warnings, infos ratcheted"
  ( cd "$INTF" && flutter analyze 2>&1 | tail -2 )
  ( cd "$INTF" && flutter analyze 2>&1 | grep -oE '^   (error|warning|info) •' | sort | uniq -c )
  echo "  ceiling in the app's ci.yml: $(grep -oE "INFO_CEILING: '[0-9]+'" "$FLUTTER/.github/workflows/ci.yml" | tail -1)"
  hdr "flutter test (BLOCKING, must be 100%)"
  ( cd "$INTF" && flutter test 2>&1 | tail -3 )
}

case "$MODE" in
  --gates-only) ;;
  *)
    merge_all "$LARAVEL" "$INT"  "$DEV_BRANCH"  int/checkpoint  "${LARAVEL_BRANCHES[@]}"
    merge_all "$FLUTTER" "$INTF" "$FLUTTER_DEV" intf/checkpoint "${FLUTTER_BRANCHES[@]}"
    ;;
esac

case "$MODE" in
  --merge-only) echo; echo "merge-only: gates not run."; exit 0 ;;
esac

laravel_gates
flutter_gates

hdr "DONE"
echo "Nothing was pushed. Read every gate above before claiming anything."
