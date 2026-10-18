#!/usr/bin/env bash
# Flags a diff that touches a security-sensitive surface and requires the human checklist.
#
# It does NOT try to judge the change -- that is the reviewer's job, and the whole reason the
# checklist exists is that the defects it guards against are invisible to automation. This only
# ensures nobody can land such a diff without being asked the questions.
#
# Exit 0 = nothing sensitive touched, or the checklist was acknowledged.
# Exit 1 = sensitive paths touched and no acknowledgement found.
set -euo pipefail

BASE_REF="${1:-}"
BODY_FILE="${2:-}"

if [[ -z "$BASE_REF" ]]; then
  echo "usage: flag-sensitive-diff.sh <base-ref> [pr-body-file]" >&2
  exit 2
fi

CHANGED="$(git diff --name-only "${BASE_REF}...HEAD" || true)"

if [[ -z "$CHANGED" ]]; then
  echo "no changed files against ${BASE_REF}; nothing to flag."
  exit 0
fi

# Every alternative here is a surface where a diff can remove a control without the new code looking
# wrong. `Providers/` and `RateLimiter|Throttle` are on the list because the limiter definitions were
# NOT: every rate limiter in the Lynomia integration lives in one service provider, and a diff
# touching only that file -- retuning a multiple, reordering the throttle tiers -- matched nothing
# here and cleared this gate with exit 0. That is the P0 the commerce and ingress fixes closed, so
# the change that re-opens it has to be asked the questions. `src/app/` covers the integration's own
# services, jobs, listeners and controllers for the same reason; its views, tests and lang files are
# deliberately left out.
PATTERN='(^config/|/config/|routes/|Audit|audit/|Middleware/|^routes/|bootstrap/app\.php|Policies/|Permission|Role|Billing|Entitlement|Seat|Plan|Signature|Secret|Credential|Token|/config/|^\.github/|Providers/|RateLimiter|Throttle|database/migrations/|packages/workdo/LynomiaChat/src/app/)'
SENSITIVE="$(echo "$CHANGED" | grep -E "$PATTERN" || true)"

if [[ -z "$SENSITIVE" ]]; then
  echo "no security-sensitive paths in this diff."
  exit 0
fi

echo "------------------------------------------------------------------"
echo "SECURITY-SENSITIVE DIFF -- human review checklist required"
echo "------------------------------------------------------------------"
echo "$SENSITIVE" | sed 's/^/  /'
echo
echo "Deletions by file (what did this REMOVE?):"
git diff --numstat "${BASE_REF}...HEAD" -- $(echo "$SENSITIVE" | tr '\n' ' ') 2>/dev/null \
  | awk '$2 > 0 {printf "  -%-6s +%-6s %s\n", $2, $1, $3}' | sort -rn -k1 || true
echo
echo "Checklist: .github/SECURITY-DIFF-REVIEW.md"

# The acknowledgement is a literal marker in the PR body. Deliberately a human action.
ACK_MARKER='SECURITY-DIFF-REVIEW: completed'

if [[ -n "$BODY_FILE" && -f "$BODY_FILE" ]] && grep -qF "$ACK_MARKER" "$BODY_FILE"; then
  echo
  echo "Checklist acknowledged in the pull request body."
  exit 0
fi

echo
echo "::error::This diff touches security-sensitive paths. Work through"
echo "::error::.github/SECURITY-DIFF-REVIEW.md and add the line"
echo "::error::    ${ACK_MARKER}"
echo "::error::to the pull request description, with the answers above it."
exit 1
