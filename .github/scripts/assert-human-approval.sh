#!/usr/bin/env bash
#
# Requires a GitHub REVIEW APPROVAL from an identity that is not the pull request's author, and
# that is bound to the pull request's CURRENT head.
#
# Owner direction of 2026-11-02, items 4 and 5.
#
# ==============================================================================================
# WHY THIS EXISTS, AND WHY THE CHECKLIST MARKER COULD NOT CARRY THIS MEANING
# ==============================================================================================
# `flag-sensitive-diff.sh` requires a literal acknowledgement line in the pull request body. That
# line is now anchored so prose cannot satisfy it (item 1-3, see that file's header), but anchoring
# fixes the ACCIDENT, not the PROPOSITION. A body line is still a body line:
#
#   * GitHub records no distinction between a line a person typed and a line an automation
#     generated. Both appear in `.body`, with no author, no timestamp and no identity.
#   * In THIS repository they are literally the same identity. Measured 2026-11-02:
#     `gh api user` -> login `fullstackfull`, id 287595574, type User -- and PRs #123, #124 and
#     #125 all report `author: fullstackfull`, because the automation acts through the owner's
#     credential. There is no field anywhere in the record that separates them.
#   * So an agent wrote the acknowledgement on #124 and then merged it (P1-SEC-50, S53).
#
# A REVIEW is different in kind. It has a `user.login`, a `state`, a `submitted_at` and a
# `commit_id`. GitHub itself refuses to let a pull request's author approve their own pull
# request. So "an APPROVED review from a login other than the author, at the current head" is a
# proposition about an identity and a point in history, which is what the checklist was always
# trying to express.
#
# ==============================================================================================
# THIS IS NOT ENFORCING YET, AND THAT IS DELIBERATE -- READ docs/security/HUMAN-REVIEW-GATE.md
# ==============================================================================================
# Default mode is `report`. Enforcing today would produce a gate that CANNOT BE SATISFIED, not a
# gate that is strict: the owner is the author of every pull request here (the automation opens
# them as them), GitHub refuses self-approval, and therefore no qualifying approval can exist
# until the automation has its own identity. Turning this on before that separation would force
# exactly the bypasses this whole exercise is about.
#
# Item 7 of the owner's direction -- how to separate the identities -- is written up in
# docs/security/HUMAN-REVIEW-GATE.md. Flip this to `enforce` after that, not before.
#
# ==============================================================================================
# FETCHING IS SEPARATE FROM DECIDING, ON PURPOSE
# ==============================================================================================
# This script takes the reviews as JSON ON DISK and makes no network call. The workflow fetches
# them. That is what makes all six owner-named scenarios -- positive, negative, quoted marker,
# denied marker, stale review, author self-approval -- executable offline and therefore ratcheted
# by tests/Support/Security/bin/verify-attestation-gate-scenarios.sh rather than asserted in prose.
#
# THE HEAD-BINDING RULE, stated exactly, because "stale" has more than one reasonable meaning:
#   Only reviews whose `commit_id` equals the current head are considered AT ALL. Among those,
#   each reviewer's LAST review (by `submitted_at`) is their position. A qualifying approval is a
#   reviewer whose position is APPROVED and whose login is not the author's.
#   Consequence, and it is intentional: a REQUEST_CHANGES left at an older head neither approves
#   nor blocks here -- it is simply not at this head. GitHub's own branch protection is the place
#   to express "changes requested blocks"; this script answers one question only.
#
# Usage:
#   assert-human-approval.sh <pr-author-login> <head-sha> <reviews-json> [enforce|report] [trusted-file]
#
# Exit 0 = a qualifying approval exists, or `report` mode.
# Exit 1 = `enforce` mode and no qualifying approval.
# Exit 2 = usage error, unreadable input, or an unknown mode -- never a silent fallback to the
#          weaker behaviour.
set -euo pipefail

AUTHOR="${1:-}"
HEAD_SHA="${2:-}"
REVIEWS="${3:-}"
MODE="${4:-report}"
TRUSTED_FILE="${5:-}"

printf 'Gate: a human review approval, from a non-author identity, at this head\n'

if [[ -z "$AUTHOR" || -z "$HEAD_SHA" || -z "$REVIEWS" ]]; then
  echo "usage: assert-human-approval.sh <pr-author-login> <head-sha> <reviews-json> [enforce|report] [trusted-file]" >&2
  exit 2
fi

# An unrecognised mode is a usage error, NOT a fall back to `report`. A typo must not be the thing
# that quietly turns a gate into a warning. (Same posture as flag-sensitive-diff.sh.)
if [[ "$MODE" != "enforce" && "$MODE" != "report" ]]; then
  echo "assert-human-approval.sh: unknown mode '${MODE}' (expected 'enforce' or 'report')" >&2
  exit 2
fi

if [[ ! -f "$REVIEWS" ]]; then
  echo "::error::reviews file '${REVIEWS}' does not exist. The gate could not be evaluated." >&2
  exit 2
fi

if ! command -v jq >/dev/null 2>&1; then
  echo "::error::jq is not available, so the reviews could not be parsed. The gate could not be" >&2
  echo "::error::evaluated, and a gate that cannot be evaluated must not report success." >&2
  exit 2
fi

# A malformed or non-array payload is an ERROR, not an absence of approvals. An API error body is
# a JSON OBJECT, and treating that as "no reviews" would turn a failed fetch into a clean refusal
# in enforce mode and into silence in report mode.
if ! jq -e 'type == "array"' "$REVIEWS" >/dev/null 2>&1; then
  echo "::error::'${REVIEWS}' is not a JSON array of reviews. The fetch probably failed; its" >&2
  echo "::error::content follows. The gate could not be evaluated." >&2
  head -c 400 "$REVIEWS" >&2 || true
  echo >&2
  exit 2
fi

TOTAL="$(jq 'length' "$REVIEWS")"
AT_HEAD="$(jq --arg h "$HEAD_SHA" '[.[] | select(.commit_id == $h)] | length' "$REVIEWS")"

printf '  reviews on this pull request: %s   at the current head (%s): %s\n' \
  "$TOTAL" "${HEAD_SHA:0:8}" "$AT_HEAD"

# Each reviewer's LAST position among reviews at this head.
POSITIONS="$(jq -r --arg h "$HEAD_SHA" '
  [ .[] | select(.commit_id == $h) ]
  | sort_by(.submitted_at)
  | group_by(.user.login)
  | map({login: .[0].user.login, state: (last(.[]) | .state)})
  | .[] | "\(.login)\t\(.state)"
' "$REVIEWS" 2>/dev/null || true)"

if [[ -n "$POSITIONS" ]]; then
  printf '  positions at this head:\n'
  printf '%s\n' "$POSITIONS" | sed 's/^/    /'
fi

QUALIFYING=""
while IFS=$'\t' read -r login state; do
  [[ -z "${login}" ]] && continue
  [[ "$state" != "APPROVED" ]] && continue
  if [[ "$login" == "$AUTHOR" ]]; then
    printf '  REJECTED: %s approved, but is the pull request AUTHOR. Self-approval is not review.\n' "$login"
    continue
  fi
  if [[ -n "$TRUSTED_FILE" && -f "$TRUSTED_FILE" ]]; then
    if ! awk '{ gsub(/^[[:space:]]+|[[:space:]]+$/, ""); print }' "$TRUSTED_FILE" \
         | grep -v '^#' | grep -qxF "$login"; then
      printf '  REJECTED: %s approved, but is not listed in %s.\n' "$login" "$TRUSTED_FILE"
      continue
    fi
  fi
  QUALIFYING="${QUALIFYING}${login} "
done <<< "$POSITIONS"

# Reviews that exist but are NOT at this head are the "stale" case. Name them, because a reviewer
# who approved and then saw the head move deserves to be told why their approval stopped counting.
STALE="$(jq -r --arg h "$HEAD_SHA" '
  [ .[] | select(.commit_id != $h and .state == "APPROVED") ]
  | map("\(.user.login) approved \(.commit_id[0:8])")
  | unique | .[]
' "$REVIEWS" 2>/dev/null || true)"

if [[ -n "$STALE" ]]; then
  printf '  STALE, not counted (approved a different head than %s):\n' "${HEAD_SHA:0:8}"
  printf '%s\n' "$STALE" | sed 's/^/    /'
fi

if [[ -n "$QUALIFYING" ]]; then
  printf '  APPROVED by a non-author identity at this head: %s\n' "${QUALIFYING% }"
  exit 0
fi

# No qualifying approval.
if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
  {
    echo "### Human review approval -- NOT present"
    echo
    echo "Mode: \`${MODE}\` · author: \`${AUTHOR}\` · head: \`${HEAD_SHA:0:8}\`"
    echo
    echo "Reviews: ${TOTAL} total, ${AT_HEAD} at this head. No APPROVED review from a login other"
    echo "than the author, at this head."
    [[ -n "$STALE" ]] && { echo; echo "Stale approvals (different head):"; echo; echo '```'; echo "$STALE"; echo '```'; }
  } >> "$GITHUB_STEP_SUMMARY" || true
fi

if [[ "$MODE" == "report" ]]; then
  echo
  echo "::warning::No GitHub review approval from an identity other than the author, at this head."
  echo "::warning::This is REPORTED, NOT ENFORCED -- and it cannot be enforced yet, because the"
  echo "::warning::automation and the owner are the SAME GitHub login here, and GitHub refuses to"
  echo "::warning::let an author approve their own pull request. Separating those identities is the"
  echo "::warning::precondition; see docs/security/HUMAN-REVIEW-GATE.md. REPORTED IS NOT SATISFIED."
  exit 0
fi

echo
echo "::error::This pull request has no APPROVED review from an identity other than its author"
echo "::error::(${AUTHOR}) at the current head (${HEAD_SHA:0:8})."
echo "::error::A review approval is the only acknowledgement that records WHO gave it. See"
echo "::error::docs/security/HUMAN-REVIEW-GATE.md."
exit 1
