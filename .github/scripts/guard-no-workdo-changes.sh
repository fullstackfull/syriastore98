#!/usr/bin/env bash
#
# Fails if the pull request modifies any VENDORED module under packages/workdo/.
#
# WHY. The WorkDo addon modules are vendored third-party code from a commercial upstream.
# Editing them in place is unrecoverable in practice: the change is silently reverted by
# the next upstream module update, and there is no patch record of what was changed or
# why. The governing principle for this phase is PRESERVE first, and these modules are
# the single largest body of functionality in the platform.
#
# A genuine fix that appears to need a module file almost always belongs in the host
# application instead (a Policy, a FormRequest, a Gate, a scope, or a route-level
# middleware). If it truly cannot, it is an owner decision about forking the module, not
# a change to slip into a security PR.
#
# FIRST-PARTY MODULES ARE EXEMPT, BY NAME, AND ONLY BY NAME.
#
# The directory is the loader's, not the vendor's: App\Providers\PackageServiceProvider
# discovers addons by scanning packages/workdo/<Name>, so a module this project WRITES has
# nowhere else to live without rewriting the loader. LynomiaChat is such a module -- new
# in this repository, with no upstream counterpart, so there is no upstream update to
# revert it and no vendor patch record to lose. Every reason in the paragraph above is
# about code we did not write, and none of them is true of code we did.
#
# The exemption is an allowlist of exact directory names, deliberately not a pattern: a
# new vendored module cannot fall into it by accident, and adding one is a visible edit to
# this file. Everything outside the list still fails, including an edit to a vendored
# module made in the same commit as a legitimate first-party one.
#
# Usage: .github/scripts/guard-no-workdo-changes.sh <base-ref> [repo-root]
set -euo pipefail

BASE="${1:-}"
ROOT="${2:-.}"

printf 'Guard: packages/workdo/ is unmodified\n'

if [[ -z "${BASE}" ]]; then
    printf '  SKIP: no base ref given, so there is no diff to inspect.\n'
    exit 0
fi

cd "${ROOT}"

if ! git rev-parse --verify --quiet "${BASE}" >/dev/null; then
    printf '  FAIL: base ref %s is not available. Fetch it before running this guard;\n' "${BASE}" >&2
    printf '        a guard that cannot see the diff must not report success.\n' >&2
    exit 1
fi

# First-party modules written by this project. Exact directory names only.
FIRST_PARTY_MODULES=(
    LynomiaChat
)


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
if ! MERGE_BASE="$(git merge-base "${BASE}" HEAD 2>&1)"; then
    printf '  FAIL: there is no merge base between %s and HEAD, so the three-dot diff this\n' "${BASE}" >&2
    printf '        guard depends on cannot be computed. git said: %s\n' "${MERGE_BASE}" >&2
    if [[ "$(git rev-parse --is-shallow-repository 2>/dev/null || true)" == 'true' ]]; then
        printf '        THE CLONE IS SHALLOW, so the history was truncated. Look for a\n' >&2
        printf '        `git fetch --depth=...` in an EARLIER STEP of this same job.\n' >&2
    fi
    printf '        A guard that cannot see the diff must not report success.\n' >&2
    exit 1
fi

if ! CHANGED="$(git diff --name-only "${BASE}"...HEAD -- 'packages/workdo/' 2>&1)"; then
    printf '  FAIL: git diff against %s failed, so this guard could not be evaluated.\n' "${BASE}" >&2
    printf '        git said: %s\n' "${CHANGED}" >&2
    exit 1
fi

for module in "${FIRST_PARTY_MODULES[@]}"; do
    CHANGED="$(printf '%s\n' "${CHANGED}" | grep -v "^packages/workdo/${module}/" || true)"
done

# grep -v on empty input leaves an empty line behind; strip blanks so the test below is honest.
CHANGED="$(printf '%s\n' "${CHANGED}" | sed '/^[[:space:]]*$/d' || true)"

if [[ -n "${CHANGED}" ]]; then
    cat >&2 <<'MSG'

================================================================================
packages/workdo/ WAS MODIFIED. Refusing to proceed.

These addon modules are vendored third-party code. An in-place edit is
silently reverted by the next upstream update and leaves no patch record.
(First-party modules are exempt by name; see the allowlist in this script.)

Put the fix in the host application instead -- a Policy, FormRequest, Gate,
query scope or route middleware. If that is genuinely impossible, forking the
module is an owner decision, not part of a security PR.

Files changed:
MSG
    printf '  %s\n' "${CHANGED}" >&2
    printf '================================================================================\n' >&2
    exit 1
fi

printf '  OK: no changes under packages/workdo/ outside first-party modules.\n'
