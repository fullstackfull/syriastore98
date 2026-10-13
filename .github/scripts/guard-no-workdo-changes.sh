#!/usr/bin/env bash
#
# Fails if the pull request modifies anything under packages/workdo/.
#
# WHY. The 69 WorkDo addon modules are vendored third-party code from a commercial
# upstream. Editing them in place is unrecoverable in practice: the change is silently
# reverted by the next upstream module update, and there is no patch record of what was
# changed or why. The governing principle for this phase is PRESERVE first, and these
# modules are the single largest body of functionality in the platform.
#
# A genuine fix that appears to need a module file almost always belongs in the host
# application instead (a Policy, a FormRequest, a Gate, a scope, or a route-level
# middleware). If it truly cannot, it is an owner decision about forking the module, not
# a change to slip into a security PR.
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

CHANGED="$(git diff --name-only "${BASE}"...HEAD -- 'packages/workdo/' || true)"

if [[ -n "${CHANGED}" ]]; then
    cat >&2 <<'MSG'

================================================================================
packages/workdo/ WAS MODIFIED. Refusing to proceed.

These 69 addon modules are vendored third-party code. An in-place edit is
silently reverted by the next upstream update and leaves no patch record.

Put the fix in the host application instead -- a Policy, FormRequest, Gate,
query scope or route middleware. If that is genuinely impossible, forking the
module is an owner decision, not part of a security PR.

Files changed:
MSG
    printf '  %s\n' "${CHANGED}" >&2
    printf '================================================================================\n' >&2
    exit 1
fi

printf '  OK: no changes under packages/workdo/.\n'
