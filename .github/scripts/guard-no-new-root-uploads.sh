#!/usr/bin/env bash
#
# NO NEW USER UPLOAD IS COMMITTED TO THE REPOSITORY.  P1-OWN-10 (#103), OWNER DECISION 11.
#
# THE OWNER'S WORDS: "Do not delete or rewrite historical user uploads in Git." So the 407 files
# already tracked under `uploads/` stay exactly where they are, and this guard does NOT touch them.
# What it does is stop the set GROWING, which is the half of D11 that is not accepted risk: every
# new file added there is a new disclosure, not a historical one.
#
# WHY `uploads/` AT THE REPOSITORY ROOT AND NOT `public/uploads/`. The application writes and links
# user media under `public/uploads/` (the tracked `.htaccess` rewrites `/uploads/...` to
# `public/uploads/...`), and `.gitignore` already excludes `/public/uploads/`. The root-level
# `uploads/` directory is committed residue -- and because the rewrite at `.htaccess:33-36` only
# fires when the file does NOT exist at the root, a file that DOES exist there is served directly by
# the webserver with no application and no authorisation. See
# docs/security/P01-OWN-10-ROOT-UPLOADS-EXPOSURE.md.
#
# WHAT THIS REFUSES: any ADDED path under `uploads/` in the diff against the base ref. Modifications
# and deletions of the existing set are not refused -- deleting is the owner's call under D11, and
# this guard does not have an opinion about it.
#
# USAGE
#   .github/scripts/guard-no-new-root-uploads.sh                 # needs BASE_REF, else SKIPs
#   BASE_REF=origin/main .github/scripts/guard-no-new-root-uploads.sh
#
# Exit 0 = nothing new. Exit 1 = a new upload was committed, with the paths named.

set -uo pipefail

echo "Guard: no NEW file under the repository-root uploads/"

BASE_REF="${BASE_REF:-${1:-}}"

if [ -z "${BASE_REF}" ]; then
  # SAME POSTURE AS guard-no-workdo-changes.sh: a guard that cannot see a diff has not been
  # satisfied, but it must not invent a failure either. It says so and exits 0.
  echo "  SKIP: no base ref given, so there is no diff to inspect."
  exit 0
fi

if ! git rev-parse --verify --quiet "${BASE_REF}" >/dev/null; then
  echo "::error::BASE_REF '${BASE_REF}' does not resolve. The guard could not be evaluated."
  exit 1
fi

ADDED="$(git diff --name-only --diff-filter=A "${BASE_REF}...HEAD" -- 'uploads/' || true)"

if [ -z "${ADDED}" ]; then
  echo "  OK: no file was added under uploads/."
  exit 0
fi

echo "::error::A new user upload was committed to the repository root."
echo "::error::Files served from root-level uploads/ bypass the application entirely:"
echo "::error::.htaccess:33-36 only rewrites /uploads/... to public/uploads/... when the file does"
echo "::error::NOT exist at the root, so one that does is served directly, with no authorisation."
echo "::error::"
echo "::error::Write user media to public/uploads/ (already in .gitignore) instead."
echo "::error::The historical set is accepted risk under owner decision 11 and is NOT touched; this"
echo "::error::guard exists so it does not grow. See docs/security/P01-OWN-10-ROOT-UPLOADS-EXPOSURE.md."
echo "::error::"
while IFS= read -r f; do
  echo "::error::  added: ${f}"
done <<< "${ADDED}"

exit 1
