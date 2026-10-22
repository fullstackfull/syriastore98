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
    # `[^[:alnum:]_]{0,2}` between the key name and the operator is P1-SEC-05, and it is the
    # difference between seeing a PHP config array and not. Every secret in this project's own
    # `config/` and `settings` code is written `'api_key' => 'literal'`, where a QUOTE sits
    # between the name and the `=>`; the original `[[:space:]]*` required them to be adjacent, so
    # the entire array form was invisible. MEASURED before the change: `$api_key = 'AKIA…'` was
    # caught and `'api_key' => 'AKIA…'`, `"client_secret" => "…"`, `'MAIL_PASSWORD' => '…'` and
    # `["access_token"]="…"` were all missed. Two characters is enough for a closing quote plus a
    # bracket, and excluding alphanumerics and `_` keeps it from reaching across a longer name.
    'generic-assigned-secret::(password|passwd|secret|api_?key|apikey|auth_?token|access_?token|client_?secret)[^[:alnum:]_]{0,2}[[:space:]]*(=|=>|:)[[:space:]]*.{1}[A-Za-z0-9/+_.=-]{12,}'
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

            # NARROWING 1: the value must be a QUOTED LITERAL.
            #
            # This rule's header claims it "targets an ASSIGNMENT of a literal, not a mere
            # mention", but it never actually checked for one, and that gap produced a
            # blocking false positive on the first PR this screen ever ran against:
            #
            #   app/Http/Middleware/CustomAuth.php:60
            #   $accessToken = PersonalAccessToken::findToken($bearer);
            #
            # `access_?token` matches `accessToken`, then `=`, then 12+ word characters of
            # `PersonalAccessToken` -- a Sanctum model lookup read as a credential. The whole
            # class is the same: `$secret = $request->secret`, `$key = config('x')`,
            # `$token = Str::random(40)`. A credential committed to source is a quoted string;
            # a method call is not. Requiring a quote after the operator removes every one of
            # those without touching what the rule is for.
            #
            # Only this low-confidence rule is narrowed. An AKIA key, a PEM block or an
            # sk_live_ key is still caught wherever it appears, quoted or not.
            #
            # P1-SEC-05: this gate carries THE SAME `[^[:alnum:]_]{0,2}` tolerance as the rule
            # above, and it has to. The gate is a second, independent copy of the
            # name-then-operator pattern, so fixing only the rule would have fixed nothing: the
            # rule would match `'api_key' => 'AKIA…'` and then this `continue` would throw the
            # finding away. MEASURED both ways before the change.
            if [[ "${name}" == 'generic-assigned-secret' ]] \
                && ! grep -Eqi '(password|passwd|secret|api_?key|apikey|auth_?token|access_?token|client_?secret)[^[:alnum:]_]{0,2}[[:space:]]*(=|=>|:)[[:space:]]*['"'"'"]' <<<"${content}"; then
                continue
            fi

            # NARROWING 2a: the test tree, for THIS RULE ONLY. P1-SEC-05.
            #
            # Widening the rule to see PHP array syntax (above) made 51 lines visible that were
            # invisible before, and every one of them is a test fixture: 'attacker-chosen-password',
            # 'OWNER-CHOSEN', 'pi_fake_secret_' . $n. A suite that asserts what happens to a
            # password must contain password literals, exactly as this script and gitleaks.toml
            # must contain credential-shaped patterns -- which is why EXCLUDE_RE already exempts
            # those two files by the same reasoning.
            #
            # WHAT THIS COSTS, stated rather than implied: a GENERIC-shaped secret pasted into the
            # test tree -- a bare password or api_key literal -- is no longer screened. What it does
            # NOT cost: every high-confidence rule still runs over the test tree, unnarrowed, so an
            # AWS key, a PEM block, an sk_live_ key, a Google API key, a Slack token, a JWT or a
            # database URL with a password in it is still caught there. Those are the shapes a real
            # credential actually has; a fixture password is not one of them.
            #
            # THIS NARROWS A RULE THAT A PREVIOUS DECISION DECLINED TO NARROW, so here is why the
            # cases differ rather than leaving the contradiction unexplained.
            # tests/Feature/Security/CustomerAccountSelfServiceTest.php:67-84 records: "The fix is
            # not to narrow the rule, and not to chop the string up until the regex misses it ...
            # The screen already has a documented placeholder vocabulary". That was ONE fixture,
            # renameable into the existing vocabulary with nothing else depending on its spelling,
            # and the norm it states is a good one: a fixture should read as synthetic to a HUMAN
            # first. That norm still stands for anyone writing a fixture.
            # What changed is scale, and it was measured: widening the rule to see PHP array syntax
            # makes 70 DISTINCT values visible across ~12 security suites. The ones that already
            # self-label (FIXTURE-*, not-a-real-password, placeholder-*) are skipped by the
            # vocabulary below and were never the problem. The residue needs `*-chosen-*`, `fake`,
            # `sentinel`, `known-current`, `cross-tenant`, `must-not-appear` and a dozen more
            # adjectives, which is an unbounded list that goes red the next time a suite invents a
            # word. Renaming the values instead is not free either: many are assertion-coupled, and
            # the same file's comment records a fixture rename drifting out of step with an
            # assertion 250 lines away and failing a control it had nothing to do with.
            # So: structural for the test tree, vocabulary everywhere else.
            if [[ "${name}" == 'generic-assigned-secret' && "${file}" == tests/* ]]; then
                continue
            fi

            # NARROWING 2: self-labelled test fixtures.
            #
            # The second blocking false positive on that same run was
            #   tests/Feature/Security/ZidWebhookSecretTest.php:45
            #   private const SECRET = 'zid-hook-secret-for-tests-only';
            # which IS a quoted literal, so narrowing 1 does not reach it. The value says in
            # words that it is not real. These markers are explicit and self-documenting, so a
            # developer cannot hide a live credential behind one without writing the claim
            # into the line that a reviewer reads.
            if [[ "${name}" == 'generic-assigned-secret' ]] \
                && grep -Eqi '(for[-_]tests?[-_]only|test[-_]only|not[-_]a[-_]real|fixture|dummy|sample)' <<<"${content}"; then
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
