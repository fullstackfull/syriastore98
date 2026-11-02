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

# ==========================================================================================
# THE ACKNOWLEDGEMENT. Owner direction of 2026-11-02, items 1-3. Read this before changing it.
# ==========================================================================================
#
# [1] THE MATCH IS ANCHORED TO A WHOLE LINE. It was `grep -qF`, a fixed-string SUBSTRING search
#     over the entire text, and that is not a near-miss -- it was exploited by accident. Writing
#     PR #125's description, an agent added a prominent warning that the checklist was NOT
#     acknowledged and QUOTED the marker inside that warning. The quote satisfied the gate.
#     Three check runs on one commit (acfa806b), with prose the only variable:
#
#         18:43:10Z  the push, body had no marker          failure
#         18:44:15Z  the description edit                  SUCCESS, mergeable_state "clean"
#         19:30:03Z  the string removed from the body      failure
#
#     A substring match measures THE PRESENCE OF TEXT, not the occurrence of the act that text
#     describes. Recorded as P1-SEC-53 in docs/security/P01-SECURITY-WORK-QUEUE.md S55.
#
# [2] SO QUOTATION, NEGATION AND DOCUMENTATION MUST NOT SATISFY IT. The body is normalised --
#     CR stripped, each line trimmed -- and then matched with `grep -qxF`, which requires the
#     WHOLE line to be the marker and nothing else. That rejects, by construction:
#         `SECURITY-DIFF-REVIEW: completed`   (backticks: the accidental case above)
#         > SECURITY-DIFF-REVIEW: completed   (a markdown blockquote, i.e. a quotation)
#         the line SECURITY-DIFF-REVIEW: completed is absent   (prose, including a denial)
#     and accepts a reviewer's standalone line, indented or not. The six scenarios are
#     executed by tests/Support/Security/bin/verify-attestation-gate-scenarios.sh, which CI
#     runs, so this property is ratcheted rather than asserted.
#
# [3] A PUSH CANNOT BE ACKNOWLEDGED AT ALL, and `report` mode no longer pretends otherwise.
#     The acknowledgement source on a push was `git log --format=%B base..HEAD` -- the COMMIT
#     MESSAGES. A commit message is not a review: it is written by whoever wrote the code,
#     before anyone reviewed it, and it cannot be amended by a reviewer without rewriting
#     history. It was also exploited by the same accident: the commit that RECORDED the
#     substring defect quoted the marker in its message, and the `::warning` was suppressed.
#     So in `report` mode the acknowledgement file is IGNORED ENTIRELY and the finding is
#     always reported. Only `enforce` mode -- a pull request, whose body a reviewer can edit
#     without touching history -- consults it.
#
# WHAT THIS STILL DOES NOT ESTABLISH, stated here because the header is where it will be read:
# a line in a pull request body is still a line in a pull request body. GitHub records no
# distinction between a line the owner typed and a line an automation generated, and in this
# repository both act as the same login. The acknowledgement that CAN carry that meaning is a
# GitHub review approval from a different identity, which is what
# .github/scripts/assert-human-approval.sh checks -- see docs/security/HUMAN-REVIEW-GATE.md.
ACK_MARKER='SECURITY-DIFF-REVIEW: completed'

# `report` mode (a push) has no reviewable body, so it has no acknowledgement. Do not look.
if [[ "$MODE" == "enforce" && -n "$BODY_FILE" && -f "$BODY_FILE" ]]; then
  # Normalise, then require the marker to BE a line rather than appear in one.
  #
  # FENCED CODE BLOCKS ARE STRIPPED FIRST, and that is not fussiness -- it was found by the
  # test that was written for the anchoring itself. Inside a ``` fence the marker IS on its own
  # line, so anchoring alone still accepted it, and a fenced block is the single most natural
  # way to DOCUMENT the marker in a pull request description. The owner's item 2 is explicit:
  # "quotation, negation and ordinary documentation do not satisfy the PR acknowledgement."
  # A pull request that explains this very gate would otherwise acknowledge itself.
  #
  # An UNTERMINATED fence swallows the rest of the body, so a marker after one is ignored and
  # the gate stays red. That is the safe direction, and it is asserted.
  if awk '
      BEGIN { fence = 0 }
      {
        line = $0
        sub(/\r$/, "", line)
        trimmed = line
        gsub(/^[[:space:]]+|[[:space:]]+$/, "", trimmed)
        if (trimmed ~ /^(```|~~~)/) { fence = 1 - fence; next }
        if (fence) { next }
        print trimmed
      }
    ' "$BODY_FILE" | grep -qxF "$ACK_MARKER"; then
    echo
    echo "Checklist acknowledged on its own line in ${BODY_FILE}."
    exit 0
  fi
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
  echo "::warning::This change touches security-sensitive paths. This is REPORTED, NOT ENFORCED,"
  echo "::warning::and REPORTED IS NOT SATISFIED. It is also NOT an acknowledgement: a push has no"
  echo "::warning::reviewable body, and commit messages are deliberately NOT accepted as proof of"
  echo "::warning::review -- they are written by whoever wrote the code, before anyone reviewed it,"
  echo "::warning::and a reviewer cannot amend them without rewriting history. Route the change"
  echo "::warning::through a pull request, where the checklist blocks and an approval can be required."
  exit 0
fi

echo
echo "::error::This diff touches security-sensitive paths. Work through"
echo "::error::.github/SECURITY-DIFF-REVIEW.md and add the line"
echo "::error::    ${ACK_MARKER}"
echo "::error::to the pull request description, with the answers above it."
exit 1
