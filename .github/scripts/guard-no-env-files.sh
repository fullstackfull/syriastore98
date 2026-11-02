#!/usr/bin/env bash
#
# Reports the known .env credential exposure, and FAILS on a new one.
#
# WHY. A .env in a build checkout is either (a) committed, which means live credentials are
# in version control and reachable by anyone with read access, or (b) written by a CI step,
# which means the build has been handed credentials it has no reason to hold. This pipeline
# is designed to need none: the test suite runs on sqlite :memory:, and every other check is
# static.
#
# ------------------------------------------------------------------------------------------
# [2026-11-02] THIS GUARD'S CENTRAL PREMISE EXPIRED AND THE GUARD WENT QUIET. READ THIS FIRST.
# ------------------------------------------------------------------------------------------
# This header used to open: "`.env` IS tracked in this repository." IT IS NOT, ANY MORE.
# `git cat-file -e HEAD:.env` fails; `git ls-tree HEAD` lists only `.env.example` and
# `.env.testing`. It WAS tracked -- `3c39c160`'s tree carries it as blob `aa5c75b8`, and
# `git log --full-history -- .env` shows it added and removed repeatedly across branches
# (the plain `git log -- .env` shows nothing, because history simplification hides changes
# that only appear in merges -- which is how this went unnoticed).
#
# AND THAT IS NOT GOOD NEWS, because of what it did to this script. The OPEN-RISK warning
# was gated on `[[ "${TRACKED}" == *.env* ]]`. That condition became false, so the warning
# STOPPED FIRING and the script fell through to its last line:
#
#     OK: no live .env in the checkout or the index.
#
# MEASURED, not deduced: with the repository in exactly its current state and no .env on
# disk, the old script printed that line and exited 0. So CI was reporting OK on a
# repository whose history still carries a live APP_KEY and the database credential set.
#
# AND THAT SENTENCE WAS THEN USED AS EVIDENCE. It is quoted as a release-gate result in
# `docs/development/phase-1/PHASE-01-FINAL-DECISION-REPORT.md:1164` and in
# `PR3-ATTESTATION-S21.md:221`, both as "exit 0 -- no live .env in the checkout or the
# index". A control whose premise expires and goes quiet is worse than one that fails,
# because its silence gets written down as proof of absence. That is the fourth control of
# this exact shape found in this codebase this week, and the only one that reached an
# attestation.
#
# THE RISK IS UNCHANGED AND IS STILL OPEN. Removal from the index does not un-leak history:
# the blob is reachable from `main` at `3c39c160:.env`. Per the owner's standing decision the
# credentials are NOT rotated and this is NOT fixed and NOT production-ready. The owner's
# statement about demo data does not establish that every exposed secret is harmless.
#
# SO THE REPORT IS NOW KEYED ON WHAT IS TRUE -- reachability in HISTORY -- rather than on
# presence in the index, and there are THREE answers, not two. "The exposure is gone" and
# "I could not look" must never print the same sentence, so:
#
#   reachable    the recorded commit is in this clone and its tree carries .env -> OPEN RISK
#   rewritten    the commit is here but no longer carries .env, or carries different
#                content -> this script's record is stale; a human must re-verify. NOT "OK".
#   cannot-tell  the commit is not in this clone (a shallow checkout), or there is no git
#                dir -> say so. NOT "OK".
#
# There is deliberately NO state in which this script reports a clean bill of health. While
# the finding is open, the only honest outputs are "open", "stale" and "could not look".
#
# ------------------------------------------------------------------------------------------
# WHY THIS DOES NOT EXIT 1 ON THE KNOWN FINDING, AND WHY THAT IS NOT BURYING IT
# ------------------------------------------------------------------------------------------
# The known `.env` carries a live APP_KEY and the database credential set. That is a REAL,
# OPEN finding.
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
#   - a `.env` on disk IN CI, which means a build step wrote one -- nothing in this pipeline
#     needs a credential.
#
# THAT LAST RULE CHANGED, AND ONLY FOR THE LOCAL CASE. It used to be "`.env` absent from the
# index while a `.env` exists on disk", which was exactly right while `.env` was tracked: the
# only way to see one untracked was a build step writing it. Now that `.env` is untracked and
# gitignored (`.gitignore:60`), that condition is ALSO the normal state of every developer's
# working copy -- so the rule fired on them, exit 1, with the message "a .env was written by
# the build", which is both a false alarm and a misattribution. MEASURED in this repository
# before the change: exit 1 with a `.env` present on disk. It is now scoped to CI, where the
# inference still holds, and reported rather than failed locally. Nothing about CI's verdict
# is weakened: a checkout has no `.env`, and a step writing one still fails.
#
# A `.env` that is BOTH tracked and on disk remains the known deferred state and is reported,
# not failed -- the exemption the owner's decision buys. It is scoped to the literal name
# `.env`; re-adding `.env` to the index would be covered by it, and tightening that is the
# owner's call, recorded here rather than taken unilaterally.
#
# So the exposure cannot grow quietly, and a second credential file cannot slip in behind
# the first. When the owner does rotate, DELETE the exemption below rather than leaving it to
# grant permanent amnesty -- and delete the recorded commit/blob pair with it, because once
# history is rewritten this script will correctly report "stale" until a human updates it.
#
# Usage: .github/scripts/guard-no-env-files.sh [repo-root]
set -euo pipefail

ROOT="${1:-.}"

printf 'Guard: live .env files\n'

# The one state that is known, reviewed and deferred by the owner.
KNOWN_TRACKED_ENV='.env'

# WHERE THE KNOWN EXPOSURE LIVES. Measured on 2026-11-02 in this repository, not remembered:
#   git rev-parse 3c39c160                             -> 3c39c160001965b7e...f03f52f
#   git rev-parse 3c39c160:.env                        -> aa5c75b8bc005cc14...a54260d
#   git merge-base --is-ancestor 3c39c160 origin/main  -> true (exit 0)
KNOWN_ENV_COMMIT='3c39c160001965b7e11602eed73c2c6e4f03f52f'
KNOWN_ENV_BLOB='aa5c75b8bc005cc14a00453e8dc0de16fa54260d'

cd "${ROOT}"

# Is this a CI runner? The on-disk-untracked inference holds here and only here.
IN_CI='no'
if [[ -n "${GITHUB_ACTIONS:-}" || -n "${CI:-}" ]]; then
    IN_CI='yes'
fi

ON_DISK=()
while IFS= read -r candidate; do
    [[ -z "${candidate}" ]] && continue
    ON_DISK+=("${candidate#./}")
done < <(
    find . -maxdepth 2 \
        \( -name '.env' -o -name '.env.local' -o -name '.env.production' -o -name '.env.prod' \) \
        2>/dev/null | grep -v '/vendor/' | grep -v '/node_modules/' || true
)

HAVE_GIT='no'
TRACKED=""
if git rev-parse --git-dir >/dev/null 2>&1; then
    HAVE_GIT='yes'
    TRACKED="$(git ls-files '.env' '.env.local' '.env.production' '.env.prod' || true)"
fi

# ------------------------------------------------------------------------------------------
# Is the known exposure still reachable from THIS checkout's history?
# ------------------------------------------------------------------------------------------
# WHICH REF, IF ANY, DESCENDS FROM THE RECORDED COMMIT -- and if none does, WHY.
#
# This used to print "its ancestry could not be confirmed here" and stop, which is the
# same sin the rest of this script was just rewritten to stop committing: one vague
# sentence covering several different states, printed on every run until nobody reads it.
# It appeared on the first real CI run of the rewrite (`static`, pull_request, depth 0),
# where `3c39c160` IS an ancestor of `main` -- GitHub's own compare API answers
# `behind_by: 0` -- so a single `HEAD` probe is not a sufficient question. A pull_request
# checkout is a detached synthetic merge ref, and an earlier step in this same job may run
# `git fetch --depth=1` against the base ref, which can plant a boundary in `.git/shallow`
# and cut the walk.
#
# So: probe several refs, and when none answers, say which refs were tried, what git's exit
# code and message were, and whether the clone is shallow. A named reason can be fixed; a
# shrug cannot.
describe_ancestry () {
    local probe rc msg shallow
    shallow="$(git rev-parse --is-shallow-repository 2>/dev/null || printf 'unknown')"

    for probe in HEAD "origin/${GITHUB_BASE_REF:-}" origin/main origin/master main master; do
        [[ -z "${probe}" || "${probe}" == 'origin/' ]] && continue
        git rev-parse --verify --quiet "${probe}^{commit}" >/dev/null 2>&1 || continue
        msg="$(git merge-base --is-ancestor "${KNOWN_ENV_COMMIT}" "${probe}" 2>&1)" && rc=0 || rc=$?
        if [[ "${rc}" -eq 0 ]]; then
            printf 'an ancestor of %s' "${probe}"
            return 0
        fi
        LAST_PROBE="${probe}"
        LAST_RC="${rc}"
        LAST_MSG="${msg}"
    done

    if [[ "${shallow}" == 'true' ]]; then
        printf 'present in this clone; ancestry NOT established, and the clone is SHALLOW -- a boundary in .git/shallow cuts the walk, so this is a limit of the checkout and NOT evidence about the repository. Last probe %s, git exit %s%s' \
            "${LAST_PROBE:-none resolvable}" "${LAST_RC:-n/a}" "${LAST_MSG:+, git said: ${LAST_MSG}}"
    else
        printf 'present in this clone; NOT an ancestor of any ref examined, and the clone is NOT shallow -- so this is a real answer, and the recorded commit may sit on an abandoned branch. RE-VERIFY BY HAND. Last probe %s, git exit %s%s' \
            "${LAST_PROBE:-none resolvable}" "${LAST_RC:-n/a}" "${LAST_MSG:+, git said: ${LAST_MSG}}"
    fi
}

HISTORY='cannot-tell'
ANCESTRY='could not be determined'
FOUND_BLOB=''
if [[ "${HAVE_GIT}" == 'yes' ]]; then
    if ! git cat-file -e "${KNOWN_ENV_COMMIT}^{commit}" 2>/dev/null; then
        # The recorded commit is not in this clone. `tests`, `tests-degraded` and
        # `lockfile-reproducibility` check out at depth 1; only `static`, `secrets` and
        # `regression-gate` set fetch-depth: 0. Absence HERE says nothing about the
        # repository, so this must not be reported as absence of the finding.
        HISTORY='cannot-tell'
    elif FOUND_BLOB="$(git rev-parse "${KNOWN_ENV_COMMIT}:${KNOWN_TRACKED_ENV}" 2>/dev/null)"; then
        HISTORY='reachable'
        ANCESTRY="$(describe_ancestry)"
    else
        HISTORY='rewritten'
    fi
fi

FAILURES=()
NOTES=()

# Anything tracked that is not the one known file is new, and fails.
while IFS= read -r t; do
    [[ -z "${t}" ]] && continue
    if [[ "${t}" != "${KNOWN_TRACKED_ENV}" ]]; then
        FAILURES+=("tracked in git: ${t}")
    fi
done <<< "${TRACKED}"

# Anything on disk that is not the one known file is new, and fails.
for f in "${ON_DISK[@]:-}"; do
    [[ -z "${f}" ]] && continue
    if [[ "${f}" != "${KNOWN_TRACKED_ENV}" ]]; then
        FAILURES+=("present in the checkout: ${f}")
    elif [[ "${TRACKED}" == *"${KNOWN_TRACKED_ENV}"* ]]; then
        # Tracked AND on disk: the known deferred state. Reported below, not failed.
        :
    elif [[ "${IN_CI}" == 'yes' ]]; then
        # Untracked and on disk IN CI: a build step wrote it. Nothing here needs one.
        FAILURES+=("a .env was written by the build (nothing in this pipeline needs one): ${f}")
    else
        NOTES+=("an untracked .env is on disk: ${f} -- normal in a working copy (.gitignore:60); the same file in CI FAILS this guard")
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

for n in "${NOTES[@]:-}"; do
    [[ -z "${n}" ]] && continue
    printf '  note: %s\n' "${n}"
done

if [[ "${TRACKED}" == *"${KNOWN_TRACKED_ENV}"* ]]; then
    printf '  the known `.env` is TRACKED in this checkout'\''s index.\n'
fi

# ------------------------------------------------------------------------------------------
# The verdict. There is no "OK" branch: the finding is open until the owner closes it.
# ------------------------------------------------------------------------------------------
case "${HISTORY}" in
    reachable)
        printf '::warning title=OPEN SECURITY RISK::A live `.env` is reachable from this repository history at %s:.env (blob %s) and contains a live APP_KEY and database credentials. It is no longer in the index, which does NOT un-leak it. Accepted and deferred by the project owner; OPEN, NOT fixed, NOT production-ready. The owner notes the data is demo data, which does not establish that every exposed secret is harmless. Remediation order: rotate every credential FIRST, then consider a history rewrite. See SECURITY.md.\n' \
            "${KNOWN_ENV_COMMIT:0:8}" "${KNOWN_ENV_BLOB:0:8}"
        printf '  OPEN RISK, reported not enforced: .env is reachable at %s:.env (%s).\n' \
            "${KNOWN_ENV_COMMIT:0:8}" "${ANCESTRY}"
        if [[ -n "${FOUND_BLOB}" && "${FOUND_BLOB}" != "${KNOWN_ENV_BLOB}" ]]; then
            printf '::warning title=THIS GUARD NEEDS A HUMAN::The .env at %s is blob %s, not the recorded %s. Its CONTENTS changed, so the recorded finding describes a different file and must be re-read before it is relied on.\n' \
                "${KNOWN_ENV_COMMIT:0:8}" "${FOUND_BLOB:0:8}" "${KNOWN_ENV_BLOB:0:8}"
        fi
        printf '  No NEW credential file. Exiting 0 so an accepted finding does not block development.\n'
        exit 0
        ;;
    rewritten)
        printf '::warning title=THIS GUARD NEEDS A HUMAN::%s is in this clone but its tree no longer carries .env. That may mean the history was rewritten and the exposure is genuinely gone, or that this script records the wrong commit. This guard cannot tell the difference, and will not report either as OK. Re-verify by hand and update guard-no-env-files.sh.\n' \
            "${KNOWN_ENV_COMMIT:0:8}"
        printf '  STALE RECORD: the recorded commit no longer carries .env. Not a clean bill of health. Exiting 0 so it does not block development.\n'
        exit 0
        ;;
    *)
        printf '::warning title=OPEN SECURITY RISK NOT CHECKED::The known .env exposure could not be checked from this checkout: %s is not present (a shallow clone, or no git dir). This is NOT a report that the exposure is gone -- it is reachable from `main`, and the `static` job, which checks out with fetch-depth: 0, reports it in this same workflow run. Set fetch-depth: 0 on this job to check it here too.\n' \
            "${KNOWN_ENV_COMMIT:0:8}"
        printf '  COULD NOT BE CHECKED: %s is not in this clone, so history was not examined. No NEW credential file. Exiting 0.\n' \
            "${KNOWN_ENV_COMMIT:0:8}"
        exit 0
        ;;
esac
