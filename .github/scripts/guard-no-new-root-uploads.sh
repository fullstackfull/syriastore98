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


# ------------------------------------------------------------------------------------------
# A DIFF THAT CANNOT BE COMPUTED IS NOT AN EMPTY DIFF.
#
# The `rev-parse --verify` above is NOT sufficient, and believing it was is what made this
# guard able to pass while a violating change was present. `--verify` succeeds for any
# object PRESENT in the store, whether or not the graph can be walked to it. A three-dot
# diff needs a MERGE BASE, and a truncated history has none -- git then says
# `fatal: <base>...HEAD: no merge base`, the `|| true` below turned that into an empty
# result, and an empty result reads as "nothing changed".
#
# MEASURED, in a synthetic repository, not reasoned about: with a full clone the diff
# correctly names the violating file; after `git fetch --no-tags --depth=1 origin main` --
# the exact command `ci.yml` ran one step earlier in this same job -- `rev-parse --verify`
# STILL RESOLVES, `git merge-base` prints nothing, the diff dies with `no merge base`, and
# the guard printed `OK` and exited 0. See docs/security/P01-SECURITY-WORK-QUEUE.md S52.
#
# So the merge base is now a PREMISE, checked and named, and the diff's exit status is
# honoured instead of discarded.
# ------------------------------------------------------------------------------------------
# SAME THREE CORRECTIONS AS guard-no-workdo-changes.sh, for the same measured reasons; read the
# long note there. In brief:
#
#   --no-renames   `--diff-filter=A` plus default rename detection meant a `git mv` INSIDE the
#                  root `uploads/` tree created a NEW unauthenticated URL while this guard said
#                  "OK: no file was added under uploads/." Measured: an R100 pair, exit 0. The
#                  content is already in the repository so nothing new is disclosed, but a new
#                  webserver-served path is exactly what this guard is for.
#   stdout only    capturing `2>&1` turned git's warnings into phantom "added" paths and failed
#                  clean trees. A defect introduced by the previous fix.
#   the diff is    `git merge-base` prints nothing when there is no common ancestor, so the old
#   the premise    "git said:" was always blank. The diff explains itself; ask it.
if ! ADDED="$(git diff --no-renames --name-only --diff-filter=A "${BASE_REF}...HEAD" -- 'uploads/' 2>/dev/null)"; then
  DIFF_ERR="$(git diff --no-renames --name-only --diff-filter=A "${BASE_REF}...HEAD" -- 'uploads/' 2>&1 >/dev/null || true)"
  echo "::error::The diff against ${BASE_REF} could not be computed, so this guard could not be"
  echo "::error::evaluated. git said: ${DIFF_ERR:-(nothing)}"
  if ! git merge-base "${BASE_REF}" HEAD >/dev/null 2>&1; then
    echo "::error::There is no merge base between ${BASE_REF} and HEAD."
  fi
  case "$(git rev-parse --is-shallow-repository 2>/dev/null || printf 'unknown')" in
    true)
      echo "::error::THE CLONE IS SHALLOW, so the history was truncated. Look for a"
      echo "::error::\`git fetch --depth=...\` in an EARLIER STEP of this same job."
      ;;
    false)
      echo "::error::The clone is NOT shallow, so this is not a truncation: the two histories"
      echo "::error::are probably unrelated (a re-created branch, a force-push)."
      ;;
    *)
      echo "::error::Whether the clone is shallow could NOT be determined, so the cause is"
      echo "::error::unknown -- which is not the same as absent."
      ;;
  esac
  echo "::error::A guard that cannot see the diff must not report success."
  exit 1
fi

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
