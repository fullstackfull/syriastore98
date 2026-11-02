#!/usr/bin/env bash
#
# Executes the six scenarios the owner named for the security-attestation gate, plus the cases
# that found the defects in the first place. Owner direction of 2026-11-02, Decision 3.
#
#   positive              a reviewer's standalone acknowledgement line satisfies the checklist
#   negative              a body with no acknowledgement does not
#   quoted marker         `SECURITY-DIFF-REVIEW: completed` inside prose does not  <- the real bug
#   denied marker         a sentence SAYING it is absent does not                  <- the real bug
#   stale review          an approval at a different head does not count
#   author self-approval  the author's own approval does not count
#
# WHY THIS IS A SCRIPT AND NOT A PHPUNIT TEST. The things under test are two shell scripts and the
# git/GitHub shapes they read. A PHP test would have to shell out to them anyway, and CLAUDE.md
# puts evidence that cannot live in the suite under tests/Support/Security/bin/. It is wired into
# ci.yml's `static` job so it runs on every pull request -- the property is RATCHETED, not
# asserted in a comment, which is the whole lesson of P1-SEC-48 through -53.
#
# PRECONDITIONS: git, jq, bash. No network. Creates and removes its own temporary repository.
#
# Usage: tests/Support/Security/bin/verify-attestation-gate-scenarios.sh [repo-root]
# Exit 0 = every scenario behaved as specified. Exit 1 = at least one did not (it is named).
set -uo pipefail

# RESOLVED TO AN ABSOLUTE PATH BEFORE ANY `cd`. The scenarios below cd into a synthetic
# repository, so a relative root -- `.`, which is exactly how ci.yml invokes its guards -- would
# make every script path point at the wrong place. Measured: it produced 25 exit-127 failures,
# which the harness reported as failures rather than passing them, but a harness that only works
# when invoked from one directory is a harness that will mislead someone.
ROOT="$(cd "${1:-$(dirname "${BASH_SOURCE[0]}")/../../../..}" && pwd)"
FLAG="${ROOT}/.github/scripts/flag-sensitive-diff.sh"
APPROVE="${ROOT}/.github/scripts/assert-human-approval.sh"

for s in "$FLAG" "$APPROVE"; do
  [[ -f "$s" ]] || { echo "missing script: $s" >&2; exit 1; }
done

WORK="$(cd "${TMPDIR:-/tmp}" && pwd)/attestation-gate-scenarios.$$"
cleanup() { [[ -n "${WORK:-}" && -d "$WORK" ]] && rm -rf "$WORK"; }
trap cleanup EXIT

mkdir -p "$WORK"
PASS=0
FAIL=0

say()  { printf '%s\n' "$*"; }
ok()   { PASS=$((PASS + 1)); printf '  PASS  %s\n' "$*"; }
bad()  { FAIL=$((FAIL + 1)); printf '  FAIL  %s\n' "$*"; }

# Asserts an exit code and, optionally, that the output does / does not contain a phrase.
expect() {
  local label="$1" want_rc="$2" got_rc="$3" out="$4" must="${5:-}" mustnot="${6:-}"
  if [[ "$got_rc" != "$want_rc" ]]; then
    bad "${label}: exit ${got_rc}, expected ${want_rc}"
    printf '%s\n' "$out" | sed 's/^/        /' | head -6
    return
  fi
  if [[ -n "$must" ]] && ! printf '%s' "$out" | grep -qF "$must"; then
    bad "${label}: exit ${want_rc} as expected, but the output does not contain: ${must}"
    return
  fi
  if [[ -n "$mustnot" ]] && printf '%s' "$out" | grep -qF "$mustnot"; then
    bad "${label}: exit ${want_rc} as expected, but the output WRONGLY contains: ${mustnot}"
    return
  fi
  ok "$label"
}

# =============================================================================================
say ''
say '############ A. the checklist acknowledgement (flag-sensitive-diff.sh) ############'
# =============================================================================================
# A repository with a genuinely security-sensitive diff, so the script reaches the ack check at
# all. `routes/` is on its sensitive pattern.
REPO="$WORK/repo"
mkdir -p "$REPO/routes"
cd "$REPO"
git init -q -b main . 2>/dev/null
git config user.email gate@test; git config user.name gate
echo 'base' > routes/web.php
git add -A >/dev/null; git commit -qm base
BASE="$(git rev-parse HEAD)"
echo 'changed' >> routes/web.php
git add -A >/dev/null; git commit -qm change

# Sanity: the diff really is flagged. If this stops being true, every A-scenario below becomes
# vacuous -- it would reach "no security-sensitive paths" and exit 0 for the wrong reason.
out="$(bash "$FLAG" "$BASE" /dev/null enforce 2>&1)"; rc=$?
expect 'A0 premise: the synthetic diff IS flagged as sensitive' 1 "$rc" "$out" \
  'SECURITY-SENSITIVE DIFF'

B="$WORK/bodies"; mkdir -p "$B"
printf 'answers to the eight questions\n\nSECURITY-DIFF-REVIEW: completed\n' > "$B/positive"
printf '   SECURITY-DIFF-REVIEW: completed   \n'                            > "$B/indented"
printf 'nothing of the sort here\n'                                         > "$B/negative"
printf 'the line `SECURITY-DIFF-REVIEW: completed` is absent, I did not add it\n' > "$B/quoted"
printf 'I have NOT written SECURITY-DIFF-REVIEW: completed anywhere in this body\n' > "$B/denied"
printf '> SECURITY-DIFF-REVIEW: completed\n'                                > "$B/blockquote"
printf 'SECURITY-DIFF-REVIEW: completed extra words after it\n'             > "$B/trailing-words"
printf 'prefix SECURITY-DIFF-REVIEW: completed\n'                           > "$B/leading-words"
printf 'answers\r\nSECURITY-DIFF-REVIEW: completed\r\n'                     > "$B/crlf"
# FOUND BY THE PHPUNIT TWIN OF THIS HARNESS, not by reading the script: anchoring alone still
# accepted a FENCED BLOCK, because inside a fence the marker IS its own line -- and a fenced block
# is how anyone would DOCUMENT the marker. A pull request explaining this gate would have
# acknowledged itself. Fences are now stripped before matching.
printf '```\nSECURITY-DIFF-REVIEW: completed\n```\n'                        > "$B/fenced"
printf '~~~\nSECURITY-DIFF-REVIEW: completed\n~~~\n'                        > "$B/tilde-fenced"
printf '```text\nSECURITY-DIFF-REVIEW: completed\n```\n'                    > "$B/fenced-info"
printf '```\nexample\n\nSECURITY-DIFF-REVIEW: completed\n'                 > "$B/unterminated-fence"

run_flag() { out="$(bash "$FLAG" "$BASE" "$1" "${2:-enforce}" 2>&1)"; rc=$?; }

run_flag "$B/positive";       expect 'A1 positive: a standalone acknowledgement line satisfies it' 0 "$rc" "$out" 'acknowledged on its own line'
run_flag "$B/indented";       expect 'A2 positive: an indented standalone line still satisfies it' 0 "$rc" "$out" 'acknowledged on its own line'
run_flag "$B/crlf";           expect 'A3 positive: CRLF line endings still satisfy it'             0 "$rc" "$out" 'acknowledged on its own line'
run_flag "$B/negative";       expect 'A4 negative: a body with no acknowledgement does not'        1 "$rc" "$out" '::error::'
run_flag "$B/quoted";         expect 'A5 QUOTED MARKER does not satisfy it (the real defect)'      1 "$rc" "$out" '::error::' 'acknowledged'
run_flag "$B/denied";         expect 'A6 DENIED MARKER does not satisfy it (the real defect)'      1 "$rc" "$out" '::error::' 'acknowledged'
run_flag "$B/blockquote";     expect 'A7 a markdown blockquote of the marker does not satisfy it'  1 "$rc" "$out" '::error::' 'acknowledged'
run_flag "$B/trailing-words"; expect 'A8 the marker with words after it does not satisfy it'       1 "$rc" "$out" '::error::' 'acknowledged'
run_flag "$B/leading-words";  expect 'A9 the marker with words before it does not satisfy it'      1 "$rc" "$out" '::error::' 'acknowledged'
run_flag "$B/fenced";         expect 'A9a a FENCED BLOCK does not satisfy it (documentation)'       1 "$rc" "$out" '::error::' 'acknowledged'
run_flag "$B/tilde-fenced";   expect 'A9b a tilde fence does not satisfy it'                        1 "$rc" "$out" '::error::' 'acknowledged'
run_flag "$B/fenced-info";    expect 'A9c a fence with an info string does not satisfy it'          1 "$rc" "$out" '::error::' 'acknowledged'
run_flag "$B/unterminated-fence"
expect 'A9d an unterminated fence swallows the rest -- gate stays red (safe direction)' 1 "$rc" "$out" '::error::' 'acknowledged'

# Item 3: a push cannot be acknowledged. Even with a perfectly valid marker in the ack file --
# which on a push is the COMMIT MESSAGES -- report mode must report and must not claim acceptance.
run_flag "$B/positive" report
expect 'A10 report mode IGNORES the ack file entirely (commit messages are not proof)' \
  0 "$rc" "$out" 'NOT an acknowledgement' 'acknowledged on its own line'

# And an unknown mode must be a usage error, never a silent downgrade to the weaker behaviour.
out="$(bash "$FLAG" "$BASE" "$B/negative" repot 2>&1)"; rc=$?
expect 'A11 an unknown mode is exit 2, not a fallback to report' 2 "$rc" "$out" 'unknown mode'

# =============================================================================================
say ''
say '############ B. the review approval (assert-human-approval.sh) ############'
# =============================================================================================
HEAD_SHA='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
OLD_SHA='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
AUTHOR='fullstackfull'
R="$WORK/reviews"; mkdir -p "$R"

rev() { # state login commit submitted
  printf '{"state":"%s","user":{"login":"%s"},"commit_id":"%s","submitted_at":"%s"}' "$1" "$2" "$3" "$4"
}
printf '[]\n' > "$R/none.json"
printf '[%s]\n' "$(rev APPROVED a-human "$HEAD_SHA" 2026-11-02T10:00:00Z)"        > "$R/positive.json"
printf '[%s]\n' "$(rev APPROVED a-human "$OLD_SHA"  2026-11-02T10:00:00Z)"        > "$R/stale.json"
printf '[%s]\n' "$(rev APPROVED "$AUTHOR" "$HEAD_SHA" 2026-11-02T10:00:00Z)"      > "$R/self.json"
printf '[%s]\n' "$(rev COMMENTED a-human "$HEAD_SHA" 2026-11-02T10:00:00Z)"       > "$R/commented.json"
printf '[%s,%s]\n' \
  "$(rev APPROVED a-human "$HEAD_SHA" 2026-11-02T10:00:00Z)" \
  "$(rev REQUEST_CHANGES a-human "$HEAD_SHA" 2026-11-02T11:00:00Z)"               > "$R/superseded.json"
printf '[%s,%s]\n' \
  "$(rev REQUEST_CHANGES a-human "$HEAD_SHA" 2026-11-02T10:00:00Z)" \
  "$(rev APPROVED a-human "$HEAD_SHA" 2026-11-02T11:00:00Z)"                      > "$R/re-approved.json"
printf '{"message":"Not Found","status":"404"}\n'                                 > "$R/apierror.json"
printf 'a-human\n' > "$R/trusted.txt"
printf 'someone-else\n' > "$R/trusted-other.txt"

run_app() { out="$(bash "$APPROVE" "$AUTHOR" "$HEAD_SHA" "$1" "${2:-enforce}" "${3:-}" 2>&1)"; rc=$?; }

run_app "$R/positive.json";    expect 'B1 positive: a non-author APPROVED at this head passes'      0 "$rc" "$out" 'APPROVED by a non-author'
run_app "$R/none.json";        expect 'B2 negative: no reviews fails in enforce mode'               1 "$rc" "$out" '::error::'
run_app "$R/stale.json";       expect 'B3 STALE: an approval at a different head does not count'    1 "$rc" "$out" 'STALE, not counted'
run_app "$R/self.json";        expect 'B4 AUTHOR SELF-APPROVAL does not count'                      1 "$rc" "$out" 'is the pull request AUTHOR'
run_app "$R/commented.json";   expect 'B5 a COMMENTED review is not an approval'                    1 "$rc" "$out" '::error::'
run_app "$R/superseded.json";  expect 'B6 a later REQUEST_CHANGES at the same head supersedes it'   1 "$rc" "$out" '::error::'
run_app "$R/re-approved.json"; expect 'B7 a later APPROVED at the same head does count'             0 "$rc" "$out" 'APPROVED by a non-author'
run_app "$R/positive.json" enforce "$R/trusted.txt"
expect 'B8 a trusted-list entry is accepted' 0 "$rc" "$out" 'APPROVED by a non-author'
run_app "$R/positive.json" enforce "$R/trusted-other.txt"
expect 'B9 a login absent from the trusted list is rejected' 1 "$rc" "$out" 'not listed in'
run_app "$R/apierror.json"
expect 'B10 a failed fetch is exit 2, NOT "no approvals"' 2 "$rc" "$out" 'not a JSON array'
run_app "$R/none.json" report
expect 'B11 report mode warns and exits 0, and says REPORTED IS NOT SATISFIED' 0 "$rc" "$out" 'REPORTED IS NOT SATISFIED'
out="$(bash "$APPROVE" "$AUTHOR" "$HEAD_SHA" "$R/none.json" enfroce 2>&1)"; rc=$?
expect 'B12 an unknown mode is exit 2, not a fallback to report' 2 "$rc" "$out" 'unknown mode'
out="$(bash "$APPROVE" "$AUTHOR" "$HEAD_SHA" "$WORK/does-not-exist.json" enforce 2>&1)"; rc=$?
expect 'B13 a missing reviews file is exit 2, NOT "no approvals"' 2 "$rc" "$out" 'does not exist'

# =============================================================================================
say ''
printf '%s\n' "----------------------------------------------------------------"
printf 'attestation gate scenarios: %s passed, %s failed\n' "$PASS" "$FAIL"
printf '%s\n' "----------------------------------------------------------------"
if [[ "$FAIL" -ne 0 ]]; then
  echo "::error::The security-attestation gate does not behave as specified. See the FAIL lines above."
  exit 1
fi
exit 0
