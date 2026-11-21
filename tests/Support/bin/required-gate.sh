#!/usr/bin/env bash
#
# THE REQUIRED GATE, run the way CI runs it, with an EXIT CODE THAT TELLS THE TRUTH.
# =================================================================================================
#
# CI runs Unit first and BLOCKS on it, then Contract, then Feature with the known-outcome assertion
# over the JUnit and TeamCity logs. Reproducing that locally before a push is the only way to know
# whether a head is safe, and this script exists because an ad-hoc version of it LIED.
#
# THE LIE, measured 2026-11-21: the inline version ended each stage with
#
#     php artisan test ... | tail -8
#     echo "=== GATE RC=$? ==="
#
# `$?` there is the exit status of `tail`, not of the test run or of the assertion script. So a gate
# whose own output said "KNOWN-OUTCOME GATE FAILED. The observed outcome set does not match the
# manifest" reported `RC=0` on the next line. Nothing downstream read the prose, and a reader
# skipping to the last line would have concluded the head was clean and pushed it. That is the same
# class of defect as the mutation harness exiting 0 on a filter that matched no test, and as
# PHPUnit's own `--filter` silence -- a verification tool whose failure looks like success.
#
# So: no pipes between a command and its status, `set -o pipefail`, and every stage's status is
# captured into a variable the moment it is produced. The script exits non-zero if ANY stage failed,
# and prints a single verdict line that names which.
#
# Usage:  tests/Support/bin/required-gate.sh [log-path]
# Reads nothing from the environment except what `php artisan test` already reads.

set -u
set -o pipefail

LOG="${1:-/tmp/required-gate-$(git rev-parse --short HEAD 2>/dev/null || echo unknown).log}"
JUNIT="storage/logs/feature-junit.xml"
TEAMCITY="storage/logs/feature-teamcity.txt"

# A STALE COMPILED BLADE CAN SERVE A PREVIOUS VERSION of a view; see CLAUDE.md. Always start clean.
php artisan view:clear >/dev/null 2>&1
rm -f "$JUNIT" "$TEAMCITY"

{
    echo "=== HEAD: $(git rev-parse --short HEAD 2>/dev/null || echo unknown) at $(date -u '+%Y-%m-%dT%H:%M:%SZ') ==="

    echo "=== UNIT (CI runs this first and blocks on it) ==="
    php artisan test --testsuite=Unit > /tmp/gate-unit.$$ 2>&1
    unit_rc=$?
    tail -4 /tmp/gate-unit.$$

    echo "=== CONTRACT ==="
    php artisan test --testsuite=Contract > /tmp/gate-contract.$$ 2>&1
    contract_rc=$?
    tail -4 /tmp/gate-contract.$$

    echo "=== FEATURE (not green by design: see .github/known-test-outcomes.json) ==="
    php artisan test --testsuite=Feature \
        --log-junit "$JUNIT" \
        --log-teamcity "$TEAMCITY" > /tmp/gate-feature.$$ 2>&1
    feature_rc=$?
    tail -6 /tmp/gate-feature.$$

    echo "=== KNOWN-OUTCOME GATE (the one that decides) ==="
    php .github/scripts/assert-known-test-outcomes.php \
        "$JUNIT" .github/known-test-outcomes.json "$TEAMCITY" > /tmp/gate-outcomes.$$ 2>&1
    outcomes_rc=$?
    cat /tmp/gate-outcomes.$$

    rm -f /tmp/gate-unit.$$ /tmp/gate-contract.$$ /tmp/gate-feature.$$ /tmp/gate-outcomes.$$

    echo
    echo "=== VERDICT ==="
    printf '  unit            rc=%s\n' "$unit_rc"
    printf '  contract        rc=%s\n' "$contract_rc"
    printf '  feature         rc=%s  (nonzero is EXPECTED -- declared failures)\n' "$feature_rc"
    printf '  known-outcomes  rc=%s  <-- THIS is the gate\n' "$outcomes_rc"

    # The Feature suite's own exit code is nonzero whenever a declared failure runs, which is always.
    # The known-outcome assertion is what distinguishes "the declared set" from "a regression", so it
    # is the gate; Unit and Contract must be clean outright.
    if [ "$unit_rc" -eq 0 ] && [ "$contract_rc" -eq 0 ] && [ "$outcomes_rc" -eq 0 ]; then
        echo "GATE PASSED"
        exit 0
    fi

    echo "GATE FAILED"
    [ "$unit_rc" -ne 0 ] && echo "  -> Unit is red. CI blocks here and never runs Feature."
    [ "$contract_rc" -ne 0 ] && echo "  -> Contract is red: the Flutter API contract."
    [ "$outcomes_rc" -ne 0 ] && echo "  -> The observed outcome set does not match the manifest. Read the ::error:: lines above."
    exit 1
} 2>&1 | tee "$LOG"

# `tee` is the last stage, so pipefail is what carries the block's status out of this script.
exit "${PIPESTATUS[0]}"
