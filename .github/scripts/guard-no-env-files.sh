#!/usr/bin/env bash
#
# Fails if a real .env file is present in the checkout.
#
# WHY. A .env in a build checkout is either (a) committed, which means live credentials
# are in version control and reachable by anyone with read access and by every CI log
# that echoes the environment, or (b) written by a CI step, which means the build has
# been handed credentials it has no reason to hold. This pipeline is designed to need
# none: the test suite runs on sqlite :memory:, and every other check is static.
#
# .env.example and .env.testing are expected and allowed. .env.testing carries only the
# throwaway testing configuration and no live secret.
#
# Usage: .github/scripts/guard-no-env-files.sh [repo-root]
set -euo pipefail

ROOT="${1:-.}"

printf 'Guard: no live .env present\n'

# Only paths the application would actually load. Deliberately not a recursive scan of
# the whole tree: vendor/ and node_modules/ ship their own fixture .env files.
FOUND=()

while IFS= read -r candidate; do
    [[ -z "${candidate}" ]] && continue
    FOUND+=("${candidate}")
done < <(
    find "${ROOT}" -maxdepth 2 \
        -name '.env' -o -name '.env.local' -o -name '.env.production' -o -name '.env.prod' \
        2>/dev/null | grep -v '/vendor/' | grep -v '/node_modules/' || true
)

if [[ ${#FOUND[@]} -gt 0 ]]; then
    cat >&2 <<'MSG'

================================================================================
A .env FILE IS PRESENT IN THE BUILD CHECKOUT. Refusing to proceed.

Either it is committed -- in which case live credentials are in version
control and must be rotated by the owner, not by CI -- or a build step wrote
it, in which case this pipeline is holding credentials it does not need.

Nothing in this pipeline requires one: the suite runs on sqlite :memory: and
every other check is static. .env.example and .env.testing are allowed.

Found:
MSG
    printf '  %s\n' "${FOUND[@]}" >&2
    printf '================================================================================\n' >&2
    exit 1
fi

# A committed .env is worse than a generated one, so it is called out separately.
cd "${ROOT}"

if git rev-parse --git-dir >/dev/null 2>&1; then
    TRACKED="$(git ls-files '.env' '.env.local' '.env.production' '.env.prod' || true)"

    if [[ -n "${TRACKED}" ]]; then
        printf '  FAIL: a .env is TRACKED IN GIT: %s\n' "${TRACKED}" >&2
        printf '        The owner must rotate those credentials and remove the file from history.\n' >&2
        exit 1
    fi
fi

printf '  OK: no live .env in the checkout or the index.\n'
