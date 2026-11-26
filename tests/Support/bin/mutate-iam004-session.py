#!/usr/bin/env python3
"""
`IAM-002` / `IAM-004` — MUTATIONS FOR THE OPERATOR SESSION AUDIT.

Nine one-change mutations, each applied alone, run, and reverted. They attack the claims the slice
makes:

  1-2.  THE ROW IS WRITTEN AT ALL. Deleting the sign-in call, and deleting the sign-out call.
  3.    THE SIGN-OUT ROW NAMES THE OPERATOR. Moving the call AFTER `logout()` leaves no principal
        to name, which is the ordering the comment claims to depend on -- so this proves the
        ordering is load-bearing rather than decorative.
  4.    THE FAILED ATTEMPT HAS NO ACTOR. Passing the targeted operator as the ACTOR makes the trail
        read as though they had acted. The row still exists and the count is unchanged, so only an
        actor assertion can see this.
  5-6.  THE VOLUME CLAIM. Dropping the operator test from `signedIn()` and from `signInFailed()`
        makes ordinary merchant traffic write rows -- which is exactly what the design note says
        this scoping avoids, and an unasserted claim in a comment is how that note would go stale.
  7.    THE CLASS CHECK. Dropping `instanceof User` is the `GAP-307` shape: a `Customer` row
        carrying the word `super admin` then writes an operator session row.
  8.    THE VISITOR'S EXPERIENCE IS UNCHANGED. Swallowing the exception instead of re-throwing it
        turns a refused sign-in into something else -- the one risk of touching a sign-in path.
  9.    THE EXISTENCE ORACLE. Recording a failed attempt for a candidate that is merely non-null,
        rather than an operator, makes the table answer "does this address exist".

Run: `python3 tests/Support/bin/mutate-iam004-session.py` from the repository root.
"""

from __future__ import annotations

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from mutate import Mutation, run  # noqa: E402

RECORDER = 'app/Support/Iam/OperatorSessionAudit.php'
CONTROLLER = 'app/Http/Controllers/Auth/AuthenticatedSessionController.php'
TEST = 'tests/Feature/Security/OperatorSessionAuditTest.php'

MUTATIONS = [
    Mutation(
        name='1. the sign-in is never recorded',
        path=CONTROLLER,
        old="        \\App\\Support\\Iam\\OperatorSessionAudit::signedIn(Auth::guard('web')->user());",
        new="        // mutated: the sign-in is not recorded",
        test_file=TEST,
        filter='test_an_operator_sign_in_is_recorded_in_the_central_audit_log',
    ),
    Mutation(
        name='2. the sign-out is never recorded',
        path=CONTROLLER,
        old="        \\App\\Support\\Iam\\OperatorSessionAudit::signedOut(Auth::guard('web')->user());",
        new="        // mutated: the sign-out is not recorded",
        test_file=TEST,
        filter='test_an_operator_sign_out_is_recorded',
    ),
    Mutation(
        name='3. the sign-out is recorded AFTER logout(), so no principal is left to name',
        path=CONTROLLER,
        old="""        \\App\\Support\\Iam\\OperatorSessionAudit::signedOut(Auth::guard('web')->user());

        // Clear the language cookie
        Cookie::queue(Cookie::forget('LANGUAGE'));
        Auth::guard('web')->logout();""",
        new="""        // Clear the language cookie
        Cookie::queue(Cookie::forget('LANGUAGE'));
        Auth::guard('web')->logout();
        \\App\\Support\\Iam\\OperatorSessionAudit::signedOut(Auth::guard('web')->user());""",
        test_file=TEST,
        filter='test_an_operator_sign_out_is_recorded',
    ),
    Mutation(
        name='4. the failed attempt is attributed to the targeted operator as the ACTOR',
        path=RECORDER,
        old="""        AuditLog::record(self::SIGN_IN_FAILED, $candidate, [], [
            'guard' => 'web',
            'outcome' => 'refused',
        ]);""",
        new="""        AuditLog::record(self::SIGN_IN_FAILED, $candidate, [], [
            'guard' => 'web',
            'outcome' => 'refused',
        ], $candidate);""",
        test_file=TEST,
        filter='test_a_failed_sign_in_against_the_operator_is_recorded_with_no_actor',
    ),
    Mutation(
        name='5. signedIn() records for every principal (the volume claim)',
        path=RECORDER,
        old="""    public static function signedIn($user): void
    {
        if (! self::isOperator($user)) {
            return;
        }""",
        new="""    public static function signedIn($user): void
    {
        if ($user === null) {
            return;
        }""",
        test_file=TEST,
        filter='test_a_merchant_sign_in_writes_no_row',
    ),
    Mutation(
        name='6. signInFailed() records for every principal (the volume claim)',
        path=RECORDER,
        old="""    public static function signInFailed($candidate): void
    {
        if (! self::isOperator($candidate)) {
            return;
        }""",
        new="""    public static function signInFailed($candidate): void
    {
        if ($candidate === null) {
            return;
        }""",
        test_file=TEST,
        filter='test_a_failed_sign_in_against_a_non_operator_writes_no_row',
    ),
    Mutation(
        name='7. the recorder drops its instanceof User check (GAP-307 shape)',
        path=RECORDER,
        old='        return $user instanceof User && UserPolicy::isOperator($user);',
        new='        return $user !== null && UserPolicy::isOperator($user);',
        test_file=TEST,
        filter='test_the_recorder_refuses_every_principal_that_is_not_an_operator_user',
    ),
    Mutation(
        name='8. the sign-in failure is swallowed instead of re-thrown',
        path=CONTROLLER,
        old="""            \\App\\Support\\Iam\\OperatorSessionAudit::signInFailed($user);

            throw $e;""",
        new="""            \\App\\Support\\Iam\\OperatorSessionAudit::signInFailed($user);""",
        test_file=TEST,
        filter='test_a_failed_sign_in_against_the_operator_is_recorded_with_no_actor',
    ),
    Mutation(
        name='9. signInFailed() becomes an existence oracle (records any resolved row)',
        path=RECORDER,
        old="""    public static function signInFailed($candidate): void
    {
        if (! self::isOperator($candidate)) {
            return;
        }""",
        new="""    public static function signInFailed($candidate): void
    {
        if (! $candidate instanceof User) {
            return;
        }""",
        test_file=TEST,
        filter='test_a_failed_sign_in_against_a_non_operator_writes_no_row',
    ),
]

if __name__ == '__main__':
    sys.exit(run(MUTATIONS))
