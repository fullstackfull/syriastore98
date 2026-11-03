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

# ==============================================================================================
# WHAT COUNTS AS A SECURITY-SENSITIVE PATH
# ==============================================================================================
# Every entry is a surface where a diff can remove a control WITHOUT THE NEW CODE LOOKING WRONG.
# That is the whole test for membership. Each one carries the reason it is here, because a list of
# bare regexes is a list nobody can audit -- and this list was wrong for months precisely because
# nobody could read it.
#
# ----------------------------------------------------------------------------------------------
# P1-SEC-56, 2026-11-02: THE LIST DID NOT INCLUDE THE PLACE THIS CODEBASE'S DEFECTS LIVE.
# ----------------------------------------------------------------------------------------------
# Measured, not inferred. Against the previous pattern:
#
#   * PR #128 -- which ADDS an authorization gate to an OTP controller and rewires an outbound
#     send of a stored `api_secret` onto a pinned, redirect-refusing transport -- matched
#     0 OF ITS 7 FILES. The job log read, in full: `no security-sensitive paths in this diff.`
#     One of the seven is literally named `tests/Feature/Security/OtpOutboundHardeningTest.php`.
#
#   * 2497f28e, the commit inside PR #124 whose subject is "four live defects GATE F turned on"
#     -- the OTP SSRF guard and the cross-tenant product-cost leak among them -- matched
#     0 OF ITS 7 FILES. PR #124 tripped this gate only through its OTHER commits (migrations,
#     `.github/`, `Policies/`). The security fixes themselves were invisible to it.
#
#   * d9a3fbc2, "the shipping quote leaked another tenant's product price", which changed
#     `app/Http/Controllers/Api/ApiController.php` -- the most defect-dense file in the
#     repository -- matched 0 OF ITS 2 FILES.
#
# The cause is simple and was never deliberate: the pattern covered policies, middleware, routes,
# config and migrations, and NOT `app/Http/Controllers/`, `app/Services/` or `app/Models/`. But
# CLAUDE.md's entire catalogue of this codebase's real defects lives in controllers: the handler
# that derives a target from `$request->some_id` instead of the authenticated principal, the
# authorization check that omits the operator-first clause, the `Product::find()` that is not
# scoped because `Product` is not in `scoped_models`. `Auth` was not on the list either, so
# `app/Http/Controllers/Customer/Auth/` was not sensitive; `Audit` is on it and reads similarly,
# which is probably why nobody noticed.
#
# So the three widest entries below are not caution -- they are the correction of a gate that was
# measuring a surface this project does not keep its defects on. The bound is in the other
# direction: a diff of only views, lang files, public assets, docs or `README` still does NOT
# fire, and `verify-attestation-gate-scenarios.sh` asserts that in both directions so the
# widening cannot quietly become "everything".
PATTERN_PARTS=(
  # --- the original entries, unchanged in meaning -------------------------------------------
  '^config/'                                  # a framework or tenancy setting (scoped_models, guards)
  '/config/'                                  # the same inside a package
  'routes/'                                   # a route's middleware stack, and route shadowing
  'bootstrap/app\.php'                        # the global middleware list
  'Middleware/'                               # the checks themselves
  'Policies/'                                 # ditto
  'Audit|audit/'                              # the audit trail that evidences the rest
  'Permission|Role'                           # Laratrust's own surface
  'Billing|Entitlement|Seat|Plan'             # what a tenant is allowed to have
  'Signature|Secret|Credential|Token'         # anything named after a secret
  '^\.github/'                                # the gates, including this script
  'Providers/'                                # P0: every Lynomia rate limiter is defined in ONE provider,
  'RateLimiter|Throttle'                      #   so retuning a multiple there matched nothing and passed
  'database/migrations/'                      # the schema a control depends on
  'packages/workdo/LynomiaChat/src/app/'      # the first-party integration's services, jobs, controllers.
                                              #   Its views, tests and lang files stay out, deliberately.

  # --- P1-SEC-56 additions, each with its measured defect ------------------------------------
  '^app/Http/'                                # CONTROLLERS, where the authorization check, the tenant
                                              #   predicate and the principal derivation actually are
                                              #   (d9a3fbc2, 2497f28e) -- plus FormRequests, whose
                                              #   validation rules are a control, and resources, whose
                                              #   field list decides what leaves the server. Stated as
                                              #   one entry because `^app/Http/Controllers/` would be
                                              #   subsumed by it and a redundant line invites the
                                              #   question of which one is load-bearing.
  '^app/Services/'                            # SafeWebhook, SafeRemoteFetch, OtpTransport, the money
                                              #   services. PR #128 and 2497f28e both land here.
  '^app/Models/'                              # global scopes, $fillable, $hidden, casts, Cachable
  '^app/Support/'                             # the schema guards and shared predicates
  '^app/Observers/'                           # OrderObserver is what calls the financial service at all
  'Auth'                                      # Auth controllers, auth config, auth middleware. Absent
                                              #   before, which is how a customer login controller that
                                              #   sends a stored credential was classified as harmless.
  '^tests/Feature/Security/'                  # a diff that WEAKENS a security test is a security diff
  '^tests/Feature/Tenancy/'                   # 83ae48d9 was a tenancy-inventory change, 0 of 1 matched
  '^tests/Support/Security/'                  # the out-of-suite evidence scripts
  '^tests/Contract/'                          # the Flutter API contract: a loosened assertion here is how
                                              #   a client-visible auth change would stop being noticed
)
PATTERN="($(IFS='|'; echo "${PATTERN_PARTS[*]}"))"
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
