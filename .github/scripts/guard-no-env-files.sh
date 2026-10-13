#!/usr/bin/env bash
#
# Reports live .env files, and FAILS on a new one.
#
# WHY. A .env in a build checkout is either (a) committed, which means live credentials are
# in version control and reachable by anyone with read access, or (b) written by a CI step,
# which means the build has been handed credentials it has no reason to hold. This pipeline
# is designed to need none: the test suite runs on sqlite :memory:, and every other check is
# static.
#
# ------------------------------------------------------------------------------------------
# WHY THIS NO LONGER EXITS 1 ON THE KNOWN .env, AND WHY THAT IS NOT BURYING IT
# ------------------------------------------------------------------------------------------
# `.env` IS tracked in this repository. It was added in commit 3c39c160 "Initial commit 98"
# and carries a live APP_KEY and the database credential set. That is a REAL, OPEN finding.
#
# The project owner has reviewed it and decided, explicitly:
#   - do NOT rotate, regenerate, replace or invalidate any credential, APP_KEY, API key,
#     Firebase configuration, database credential or integration secret;
#   - do NOT delete or modify the deployed .env;
#   - these operations must not block ongoing development;
#   - KEEP the finding OPEN as a security risk -- do not mark it fixed or production-ready.
#
# Those instructions pull in opposite directions for a scanner, and the resolution has to
# serve both. Exiting 1 on the known file would turn every build red forever over a finding
# the owner has consciously deferred, which blocks development. Adding the file to an
# allowlist would make it invisible, which buries the finding. So this script does neither:
# it REPORTS the known exposure, loudly and on every single run, and exits 0 for it alone --
# while still FAILING on anything that is not that exact known state.
#
# What still fails the build, i.e. what counts as new:
#   - any live env file other than `.env` (.env.local, .env.production, .env.prod);
#   - any TRACKED env file other than `.env`;
#   - `.env` being absent from the index while a `.env` exists on disk, which means a build
#     step wrote one.
#
# So the exposure cannot grow quietly, and a second credential file cannot slip in behind
# the first. When the owner does rotate and untrack, DELETE the exemption below rather than
# leaving it to grant permanent amnesty.
#
# Usage: .github/scripts/guard-no-env-files.sh [repo-root]
set -euo pipefail

ROOT="${1:-.}"

printf 'Guard: live .env files\n'

# The one state that is known, reviewed and deferred by the owner.
KNOWN_TRACKED_ENV='.env'

cd "${ROOT}"

ON_DISK=()
while IFS= read -r candidate; do
    [[ -z "${candidate}" ]] && continue
    ON_DISK+=("${candidate#./}")
done < <(
    find . -maxdepth 2 \
        \( -name '.env' -o -name '.env.local' -o -name '.env.production' -o -name '.env.prod' \) \
        2>/dev/null | grep -v '/vendor/' | grep -v '/node_modules/' || true
)

TRACKED=""
if git rev-parse --git-dir >/dev/null 2>&1; then
    TRACKED="$(git ls-files '.env' '.env.local' '.env.production' '.env.prod' || true)"
fi

FAILURES=()

# Anything tracked that is not the one known file is new, and fails.
while IFS= read -r t; do
    [[ -z "${t}" ]] && continue
    if [[ "${t}" != "${KNOWN_TRACKED_ENV}" ]]; then
        FAILURES+=("tracked in git: ${t}")
    fi
done <<< "${TRACKED}"

# Anything on disk that is not the one known file is new, and fails. A `.env` on disk that
# is NOT tracked means a build step wrote it, which also fails.
for f in "${ON_DISK[@]:-}"; do
    [[ -z "${f}" ]] && continue
    if [[ "${f}" != "${KNOWN_TRACKED_ENV}" ]]; then
        FAILURES+=("present in the checkout: ${f}")
    elif [[ "${TRACKED}" != *"${KNOWN_TRACKED_ENV}"* ]]; then
        FAILURES+=("a .env was written by the build (it is not tracked): ${f}")
    fi
done

if [[ ${#FAILURES[@]} -gt 0 ]]; then
    cat >&2 <<'MSG'

================================================================================
A NEW CREDENTIAL FILE HAS APPEARED. Refusing to proceed.

This is NOT the known, owner-deferred `.env`. Either a second env file was
committed, or a build step wrote one. Nothing in this pipeline requires a
credential: the suite runs on sqlite :memory: and every other check is static.

Found:
MSG
    printf '  %s\n' "${FAILURES[@]}" >&2
    printf '================================================================================\n' >&2
    exit 1
fi

# The known exposure. Reported on EVERY run, by design -- this is the finding staying open.
if [[ "${TRACKED}" == *"${KNOWN_TRACKED_ENV}"* ]]; then
    printf '::warning title=OPEN SECURITY RISK::`.env` is tracked in git (since 3c39c160) and contains a live APP_KEY and database credentials. Accepted and deferred by the project owner; NOT fixed, NOT production-ready. Remediation order: rotate every credential FIRST (removal from the index does not un-leak history), then untrack, then consider a history rewrite. See SECURITY.md.\n'
    printf '  OPEN RISK, reported not enforced: .env is tracked. See SECURITY.md.\n'
    printf '  No NEW credential file. Exiting 0 so an accepted finding does not block development.\n'
    exit 0
fi

printf '  OK: no live .env in the checkout or the index.\n'
