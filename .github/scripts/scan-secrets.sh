#!/usr/bin/env bash
#
# Scans the diff for hardcoded credentials.
#
# SCOPE AND HONESTY ABOUT WHAT THIS IS. This is a cheap, dependency-free regex screen over
# the changed lines only. It is NOT a replacement for a real secret scanner (gitleaks,
# trufflehog, GitHub's own push protection), and it cannot find a secret that is already
# in history -- only one being added. It is here because it costs nothing and runs on a
# stock runner today, which the dependency blocker means cannot be said of much else.
# Enabling GitHub secret scanning and push protection on the repository is an owner
# action and is the real control; this is the floor, not the ceiling.
#
# IT PRINTS NO SECRET VALUE. Only the file, the line number and which rule matched. A CI
# log is a durable, widely-readable artifact; echoing a matched credential into it turns a
# near-miss into a disclosure.
#
# Usage: .github/scripts/scan-secrets.sh <base-ref> [repo-root]
set -euo pipefail

BASE="${1:-}"
ROOT="${2:-.}"

printf 'Scan: hardcoded credentials in the diff\n'

cd "${ROOT}"

if [[ -z "${BASE}" ]]; then
    printf '  SKIP: no base ref given, so there is no diff to scan.\n'
    exit 0
fi

if ! git rev-parse --verify --quiet "${BASE}" >/dev/null; then
    printf '  FAIL: base ref %s is not available; a scan that cannot see the diff\n' "${BASE}" >&2
    printf '        must not report success.\n' >&2
    exit 1
fi

# Rule name :: extended regex. Each targets an ASSIGNMENT of a literal, not a mere
# mention, so `$apiKey = $request->key` and a doc comment do not trip it.
RULES=(
    'aws-access-key-id::(^|[^A-Z0-9])(AKIA|ASIA)[A-Z0-9]{16}([^A-Z0-9]|$)'
    'private-key-block::-----BEGIN [A-Z ]*PRIVATE KEY-----'
    'generic-assigned-secret::(password|passwd|secret|api_?key|apikey|auth_?token|access_?token|client_?secret)[[:space:]]*(=|=>|:)[[:space:]]*.{1}[A-Za-z0-9/+_.=-]{12,}'
    'slack-token::xox[baprs]-[A-Za-z0-9-]{10,}'
    'stripe-live-key::sk_live_[A-Za-z0-9]{16,}'
    'google-api-key::AIza[A-Za-z0-9_-]{35}'
    'jwt-literal::eyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}'
    'db-url-with-password::(mysql|postgres|postgresql|mongodb)(\+srv)?://[^:[:space:]]+:[^@[:space:]]+@'
)

# Paths where a long random-looking literal is expected and not a credential.
#
# This script and the gitleaks config are excluded because both are, by their nature,
# files full of credential-shaped patterns and documented example keys -- they match
# their own rules. (Verified: without this, the scanner reports its own comment about
# AWS's documentation key AKIAIOSFODNN7EXAMPLE as a finding.)
#
# .svg is excluded because packages/workdo/LandingPage ships 16 SVGs carrying base64 CSS
# source maps that begin `eyJ`, identical in shape to a JWT header. Verified not to be
# tokens: none has JWT structure, and they decode to {"version":3,"sources":[...]}.
EXCLUDE_RE='(^|/)(vendor|node_modules|dist|build|\.git)/|(composer|package)-?lock\.(json|lock)$|^\.github/(scripts/scan-secrets\.sh|gitleaks\.toml)$|\.(min\.js|min\.css|map|lock|svg|png|jpg|jpeg|gif|woff2?|ttf|eot|ico)$'

mapfile -t FILES < <(git diff --name-only --diff-filter=ACMR "${BASE}"...HEAD | grep -Ev "${EXCLUDE_RE}" || true)

if [[ ${#FILES[@]} -eq 0 ]]; then
    printf '  OK: no scannable files changed.\n'
    exit 0
fi

HITS=0

for file in "${FILES[@]}"; do
    [[ -f "${file}" ]] || continue

    # Added lines only, with their new line numbers, so an untouched pre-existing line is
    # not re-reported on every run.
    ADDED="$(git diff --unified=0 "${BASE}"...HEAD -- "${file}" \
        | awk '
            /^@@/ {
                match($0, /\+[0-9]+/)
                n = substr($0, RSTART + 1, RLENGTH - 1) + 0
                next
            }
            /^\+\+\+/ { next }
            /^\+/ { print n "\t" substr($0, 2); n++ }
        ' || true)"

    [[ -z "${ADDED}" ]] && continue

    for rule in "${RULES[@]}"; do
        name="${rule%%::*}"
        regex="${rule#*::}"

        while IFS=$'\t' read -r lineno content; do
            [[ -z "${lineno}" ]] && continue

            # Skip obvious placeholders -- but ONLY for the low-confidence rule.
            #
            # `generic-assigned-secret` matches any `password = "<12+ chars>"`, so a
            # template's `password = "changeme"` would otherwise fail every build. The
            # high-confidence rules are exempt from this filter on purpose: an AKIA key,
            # a PEM block or an sk_live_ key is credential-shaped in a way a placeholder
            # is not, and suppressing one because the line happens to contain the word
            # "example" is how a real key gets through. (AWS's own documentation key is
            # literally AKIAIOSFODNN7EXAMPLE, which is exactly the collision that
            # justifies narrowing this rather than widening it.)
            if [[ "${name}" == 'generic-assigned-secret' ]] \
                && grep -Eqi '(your[-_ ]?|example|changeme|placeholder|xxxx|<[a-z_]+>|\$\{|\bnull\b|""|'"''"')' <<<"${content}"; then
                continue
            fi

            printf '  HIT [%s] %s:%s\n' "${name}" "${file}" "${lineno}" >&2
            HITS=$((HITS + 1))
        # `-e` is required, not stylistic: the private-key pattern begins with '-', which
        # grep otherwise parses as an option. Without it that rule silently matches
        # nothing, which is the exact class of quiet failure this script exists to catch.
        done < <(grep -E -i -e "${regex}" <<<"${ADDED}" || true)
    done
done

if [[ "${HITS}" -gt 0 ]]; then
    cat >&2 <<'MSG'

================================================================================
POSSIBLE HARDCODED CREDENTIALS IN THE DIFF. Refusing to proceed.

Locations only are printed above -- no value is echoed, because a CI log is a
durable and widely-readable artifact.

If a match is real: the owner rotates the credential, and it moves to the
environment. Do NOT merely delete the line; it stays in git history.
If a match is a false positive: narrow the rule in this script and say why.

This regex screen is a floor, not a ceiling. Enabling GitHub secret scanning
and push protection on the repository is an owner action and the real control.
================================================================================
MSG
    exit 1
fi

printf '  OK: no credential-shaped literals added in %d changed file(s).\n' "${#FILES[@]}"
