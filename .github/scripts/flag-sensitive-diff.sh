#!/usr/bin/env bash
# Flags a diff that touches a security-sensitive surface and requires the human checklist.
#
# It does NOT try to judge the change -- that is the reviewer's job, and the whole reason the
# checklist exists is that the defects it guards against are invisible to automation. This only
# ensures nobody can land such a diff without being asked the questions.
#
# TWO MODES, BECAUSE A PUSH HAS NO PULL REQUEST BODY TO ACKNOWLEDGE IN.
#
#   enforce  (default)  A sensitive diff with no acknowledgement FAILS. This is the pull-request
#                       behaviour and it is unchanged from when this script took two arguments.
#   report              A sensitive diff with no acknowledgement is reported loudly -- a
#                       ::warning::, the full file list, and a run-summary entry that survives
#                       the log -- and exits 0.
#
# `report` exists because until the repository requires pull requests, a direct push to main
# reaches main with nothing asked of it. Reporting makes that VISIBLE rather than silent; it
# does not make it reviewed. The enforcing half of this control is branch protection, which is
# a repository setting and not something this script can assert. See the sprint report.
#
# Exit 0 = nothing sensitive touched, or acknowledged, or `report` mode.
# Exit 1 = `enforce` mode, sensitive paths touched, no acknowledgement found.
# Exit 2 = usage error (including an unknown mode -- never a silent fallback to the weaker one).
set -euo pipefail

BASE_REF="${1:-}"
BODY_FILE="${2:-}"
MODE="${3:-enforce}"

if [[ -z "$BASE_REF" ]]; then
  echo "usage: flag-sensitive-diff.sh <base-ref> [ack-file] [enforce|report]" >&2
  exit 2
fi

# An unrecognised mode is a usage error, NOT a fall back to `report`. A typo must not be the
# thing that quietly turns a blocking gate into a warning.
if [[ "$MODE" != "enforce" && "$MODE" != "report" ]]; then
  echo "flag-sensitive-diff.sh: unknown mode '${MODE}' (expected 'enforce' or 'report')" >&2
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

# The acknowledgement is a literal marker in a human-authored text: a pull request body on a
# pull request, or the pushed commit messages on a push. Deliberately a human action either way.
ACK_MARKER='SECURITY-DIFF-REVIEW: completed'

if [[ -n "$BODY_FILE" && -f "$BODY_FILE" ]] && grep -qF "$ACK_MARKER" "$BODY_FILE"; then
  echo
  echo "Checklist acknowledged in ${BODY_FILE}."
  exit 0
fi

# Record it where it outlives the log. A bypass that is only in a collapsed log section is the
# same bypass that was silent before.
if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
  {
    echo "### Security-sensitive diff -- checklist NOT acknowledged"
    echo
    echo "Mode: \`${MODE}\` · base: \`${BASE_REF}\`"
    echo
    echo "Sensitive paths in this diff:"
    echo
    echo '```'
    echo "$SENSITIVE"
    echo '```'
    echo
    echo "Checklist: \`.github/SECURITY-DIFF-REVIEW.md\` · acknowledge with \`${ACK_MARKER}\`"
  } >> "$GITHUB_STEP_SUMMARY" || true
fi

if [[ "$MODE" == "report" ]]; then
  echo
  echo "::warning::This change touches security-sensitive paths and the review checklist was"
  echo "::warning::NOT acknowledged. On a direct push there is no pull request body to"
  echo "::warning::acknowledge in, so this is REPORTED and not enforced -- which is not the"
  echo "::warning::same as satisfied. Work through .github/SECURITY-DIFF-REVIEW.md, or route"
  echo "::warning::the change through a pull request where this gate blocks."
  exit 0
fi

echo
echo "::error::This diff touches security-sensitive paths. Work through"
echo "::error::.github/SECURITY-DIFF-REVIEW.md and add the line"
echo "::error::    ${ACK_MARKER}"
echo "::error::to the pull request description, with the answers above it."
exit 1
