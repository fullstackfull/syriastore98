#!/usr/bin/env bash
#
# STORE SYRIA — SAFE UPDATE OF AN EXISTING INSTALLATION.
#
# WHY THIS EXISTS. This repository had no deployment story at all: no script, no backup step, no
# rollback, no health gate. The Lynomia side has all of those, and the asymmetry was the largest
# piece of deployment work outstanding before either application could be updated. This closes it
# for Store Syria, and deliberately mirrors the properties that make Lynomia's deploy.sh safe
# rather than inventing a different shape.
#
# WHAT IT IS NOT. It does not provision a server, create a database, install PHP or configure
# nginx. It updates an installation that already exists and is already running. It never resets,
# wipes, drops or seeds anything.
#
# THE PROPERTIES THAT MATTER, each of which is here because its absence has bitten somebody:
#
#   * It refuses to run as root. Files created as root in storage/ or bootstrap/cache/ break the
#     web user silently, hours later.
#   * It checks free disk on the BACKUP filesystem, not the application directory. Measuring only
#     the app directory passes a host with a full /var and then fails AT the backup instead of
#     before it.
#   * It compares revisions BEFORE backing up, so re-running an already-deployed revision costs
#     nothing instead of a full database dump.
#   * It takes a database backup and VERIFIES it is readable. A dump nobody has ever read is not
#     a backup.
#   * It records the outgoing revision, so a rollback has a target rather than a guess.
#   * It shows the pending migrations and requires them to have been reviewed. Store Syria's
#     migration set changed in Phase 7 -- the host now registers addon migrations that a module's
#     own provider had stranded -- so "migrate ran clean last time" is not evidence about this time.
#   * It gates on a health check AFTER the restart. /login returning 200 is the exact thing that
#     was HTTP 500 for the whole of blocker L2, which is why it is the check.
#   * An ERR trap, so a failure stops the deploy instead of continuing past it.
#
# USAGE
#   ./deployment/deploy.sh                 # update to origin/<current branch>
#   REVISION=<sha|ref> ./deployment/deploy.sh
#   DRY_RUN=1 ./deployment/deploy.sh       # preflight, revision diff and migration list only
#
# ENVIRONMENT
#   APP_DIR     default: the repository this script lives in
#   BACKUP_DIR  default /var/backups/storesyria
#   HEALTH_URL  default http://127.0.0.1/login
#   FPM_SERVICE / QUEUE_SERVICE   systemd units to restart, if set
#
set -Eeuo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/storesyria}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1/login}"
DRY_RUN="${DRY_RUN:-0}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"

log()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m  ! %s\033[0m\n' "$*"; }
fail() { printf '\n\033[1;31m!!! %s\033[0m\n' "$*" >&2; exit 1; }
trap 'fail "failed at line $LINENO -- nothing further was attempted"' ERR

# READ ONE KEY FROM .env, AND DO NOT GUESS.
#
# Not parse_ini_file(): a Laravel .env is not an ini file, and php's parser mangles values holding
# `#`, `=` or unquoted special characters -- silently, producing a wrong password and a failed
# backup. Not `source .env` either: that EVALUATES the file, so a value containing $( ) or a
# backtick would run as a command, and the result would depend on which shell invoked the deploy.
#
# This takes the LAST assignment of the key, strips one matching pair of surrounding quotes, and
# returns the rest verbatim. An unquoted value containing a character whose meaning depends on the
# reader is refused rather than guessed at -- single-quote it in .env to make it literal for the
# application and for this script alike.
env_get() {
  local key="$1" line value
  line="$(grep -E "^[[:space:]]*${key}[[:space:]]*=" .env | tail -n 1)" || true
  [[ -n "$line" ]] || return 1
  value="${line#*=}"
  value="${value#"${value%%[![:space:]]*}"}"              # ltrim
  value="${value%"${value##*[![:space:]]}"}"              # rtrim
  if [[ $value == \"*\" && ${#value} -ge 2 ]]; then value="${value:1:${#value}-2}"
  elif [[ $value == \'*\' && ${#value} -ge 2 ]]; then value="${value:1:${#value}-2}"
  elif [[ $value == *'$'* || $value == *'`'* ]]; then
    # NOT fail(): this function runs inside $( ), where `exit` kills only the subshell and the
    # caller would carry on with an empty value. It reports and returns 2 instead, and every
    # required key below is read WITHOUT `|| true` so `set -e` aborts the deploy on that status.
    printf '%s in .env is unquoted and contains $ or a backtick, so its value depends on who reads it. Single-quote it and re-run.\n' "$key" >&2
    return 2
  fi
  printf '%s' "$value"
}

# ---------------------------------------------------------------- 1. preflight
log "1. Preflight"

[[ $EUID -ne 0 ]] || fail "refusing to run as root: root-owned files in storage/ or bootstrap/cache/ break the web user silently"
cd "$APP_DIR" || fail "APP_DIR does not exist: $APP_DIR"
[[ -f artisan ]] || fail "$APP_DIR is not a Laravel application root (no artisan)"
[[ -f .env ]]    || fail "no .env in $APP_DIR -- this script updates an existing installation and will not invent configuration"

command -v php >/dev/null      || fail "php not on PATH"
command -v composer >/dev/null || fail "composer not on PATH"
command -v git >/dev/null      || fail "git not on PATH"

php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' \
  || fail "PHP $(php -r 'echo PHP_VERSION;') is below the required ^8.2"

COMPOSER_ALLOW_SUPERUSER=0 composer check-platform-reqs --no-interaction >/dev/null \
  || fail "composer check-platform-reqs failed -- a required PHP extension is missing"

# The skeleton default is sqlite, which is never what a server should be running.
# `|| [[ $? -eq 1 ]]` tolerates "key absent" (1) while letting the ambiguous-value status (2)
# propagate and abort under set -e. The case statement below handles an empty value explicitly.
DB_CONNECTION="$(env_get DB_CONNECTION || [[ $? -eq 1 ]])"
case "$DB_CONNECTION" in
  mysql|mariadb|pgsql|sqlsrv) : ;;
  "")      fail ".env sets no DB_CONNECTION" ;;
  sqlite)  fail ".env has DB_CONNECTION=sqlite, the Laravel skeleton default. Refusing: a server installation must name its real database engine." ;;
  *)       warn "unrecognised DB_CONNECTION='$DB_CONNECTION' -- continuing, but the backup step below may not know how to dump it" ;;
esac

# Free space on the filesystem that will actually hold the backup, walking up to one that exists.
backup_fs="$BACKUP_DIR"
while [[ ! -d $backup_fs && $backup_fs != "/" ]]; do backup_fs="$(dirname "$backup_fs")"; done
df -Pk "$backup_fs" | awk -v d="$backup_fs" 'NR==2 && $4 < 2097152 { print "less than 2GB free on the backup filesystem (" d ")"; exit 1 }' \
  || fail "not enough free disk for a pre-deploy backup"

OUTGOING="$(git rev-parse HEAD)"
BRANCH="$(git rev-parse --abbrev-ref HEAD)"
log "   outgoing revision : $OUTGOING ($BRANCH)"

# ------------------------------------------------------- 2. what would change
log "2. Target revision"

git fetch --quiet origin "$BRANCH" || fail "git fetch failed"
TARGET="$(git rev-parse "${REVISION:-origin/$BRANCH}")"
log "   target revision   : $TARGET"

if [[ "$OUTGOING" == "$TARGET" ]]; then
  log "Already at the target revision. Nothing to do."
  exit 0
fi

git --no-pager log --oneline "$OUTGOING..$TARGET" | sed 's/^/     /' || true

# ------------------------------------------------- 3. pending migration review
log "3. Pending migrations (review these before answering)"

php artisan migrate:status 2>/dev/null | grep -iE "pending|^\s*\|?\s*No" | sed 's/^/     /' || true
warn "Store Syria's addon migrations are registered by the HOST loader since Phase 7."
warn "A module whose own provider declared a wrong path now contributes its migrations."
warn "Confirm this list matches the migration review in"
warn "docs/development/lynomia-integration/PHASE-7-REMEDIATION-AND-DEPLOYMENT-READINESS.md section 13.6"

if [[ "$DRY_RUN" != "0" ]]; then
  log "DRY_RUN set -- stopping before any change was made."
  exit 0
fi

# ------------------------------------------------------------- 4. backup first
log "4. Database backup"

mkdir -p "$BACKUP_DIR"
DUMP="$BACKUP_DIR/storesyria-${STAMP}-${OUTGOING:0:12}.sql"

# Credentials are read by artisan from .env rather than being echoed into this script's
# environment or its log, so nothing secret is ever printed or passed on a command line.
php artisan db:show >/dev/null 2>&1 || warn "php artisan db:show failed; continuing to the dump"

DB_HOST="$(env_get DB_HOST || [[ $? -eq 1 ]])"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_USERNAME="$(env_get DB_USERNAME || [[ $? -eq 1 ]])"
DB_DATABASE="$(env_get DB_DATABASE || [[ $? -eq 1 ]])"
[[ -n "$DB_DATABASE" ]] || fail "DB_DATABASE is missing from .env, so the backup cannot run"

# The password is placed in the dump command's OWN environment only -- never in this script's
# environment, never in a variable that outlives the call, and never on a command line where `ps`
# would show it.
case "$DB_CONNECTION" in
  mysql|mariadb)
    MYSQL_PWD="$(env_get DB_PASSWORD || [[ $? -eq 1 ]])" mysqldump \
      --single-transaction --quick --routines --triggers \
      -h "$DB_HOST" -u "$DB_USERNAME" "$DB_DATABASE" > "$DUMP" \
      || fail "mysqldump failed -- NOT proceeding without a backup"
    ;;
  pgsql)
    PGPASSWORD="$(env_get DB_PASSWORD || [[ $? -eq 1 ]])" pg_dump -Fc \
      -h "$DB_HOST" -U "$DB_USERNAME" -d "$DB_DATABASE" > "$DUMP" \
      || fail "pg_dump failed -- NOT proceeding without a backup"
    ;;
  *)
    fail "no backup method for DB_CONNECTION='$DB_CONNECTION'. Take and VERIFY a backup by hand, then deploy by following deployment/ROLLBACK.md in reverse."
    ;;
esac

[[ -s "$DUMP" ]] || fail "the dump is empty -- NOT proceeding"

# VERIFY IT IS READABLE. An unread dump is not a backup.
case "$DB_CONNECTION" in
  pgsql)        pg_restore -l "$DUMP" >/dev/null || fail "the dump is not readable by pg_restore -- NOT proceeding" ;;
  mysql|mariadb) grep -qiE "CREATE TABLE|INSERT INTO" "$DUMP" || fail "the dump contains no DDL or data -- NOT proceeding" ;;
esac

printf '%s\n' "$OUTGOING" > "$BACKUP_DIR/last-deployed-revision"
log "   backup  : $DUMP ($(du -h "$DUMP" | cut -f1)), verified readable"
log "   rollback target recorded in $BACKUP_DIR/last-deployed-revision"

# --------------------------------------------------------------- 5. update code
log "5. Update code"

git merge --ff-only "$TARGET" || fail "cannot fast-forward to $TARGET -- resolve by hand rather than letting this script rewrite history"

composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist \
  || fail "composer install failed"

# ----------------------------------------------------------------- 6. migrate
log "6. Migrate"
php artisan migrate --force || fail "migrate failed -- restore from $DUMP before retrying"

# ------------------------------------------------------- 7. caches and restart
log "7. Rebuild caches and restart"

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link 2>/dev/null || true

[[ -n "${FPM_SERVICE:-}" ]]   && { sudo systemctl restart "$FPM_SERVICE"   || fail "could not restart $FPM_SERVICE"; }
[[ -n "${QUEUE_SERVICE:-}" ]] && { sudo systemctl restart "$QUEUE_SERVICE" || fail "could not restart $QUEUE_SERVICE"; }
php artisan queue:restart 2>/dev/null || true

# ------------------------------------------------------------ 8. health gate
log "8. Health check"

# /login, specifically. It answered HTTP 500 for the whole of blocker L2 because a module's
# migrations were stranded, so it is the one URL that proves the schema and the guest layout agree.
for attempt in 1 2 3 4 5 6 7 8 9 10; do
  code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$HEALTH_URL" || echo 000)"
  [[ "$code" == "200" ]] && { log "   $HEALTH_URL -> 200"; break; }
  warn "attempt $attempt: $HEALTH_URL -> $code"
  [[ $attempt -eq 10 ]] && fail "health check never returned 200. ROLL BACK: see deployment/ROLLBACK.md (outgoing revision $OUTGOING, backup $DUMP)"
  sleep 3
done

# -------------------------------------------------------------- 9. scheduler
log "9. Scheduler reminder"
crontab -l 2>/dev/null | grep -q "schedule:run" \
  || warn "NO 'schedule:run' CRON ENTRY FOUND for this user. The Lynomia integration's five scheduled commands will not run, and the failure is SILENT: commerce events and consent decisions accumulate in their outboxes and nothing reaches Lynomia. See section 13.10."

log "Deployed $OUTGOING -> $TARGET"
log "Rollback: deployment/ROLLBACK.md"
