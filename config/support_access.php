<?php

/**
 * `SUPPORT-004` / `SUPPORT-005` — THE NUMBERS THIS MECHANISM REFUSES TO INVENT.
 * =================================================================================================
 *
 * The owner approved that support access shall be temporary, scoped, consented and audited, and
 * that emergency access shall be a separate path with higher permissions and approvals. The owner
 * did NOT state how long "temporary" is, which scopes exist, or how many people approve an
 * emergency. Those are operating policy, and `docs/development/master-plan/` contains no answer to
 * any of them — checked by reading the recovered plan and the decision register, not inferred.
 *
 * So every number below is a PLACEHOLDER with its question written beside it. The MECHANISM
 * enforces that a bound exists, that it is checked, and that it cannot be bypassed; the VALUE of
 * the bound is the owner's. `docs/development/phase-6/SUPPORT-ACCESS-OWNER-QUESTIONS.md` lists the
 * five questions in the form the owner can answer, and `SupportAccessOwnerQuestionsTest` fails if a
 * key here has no question there — so a future author cannot quietly add a sixth policy number
 * without surfacing it.
 *
 * NOTHING HERE WIDENS ACCESS. Raising `max_duration_minutes` lets a grant be requested for longer;
 * it does not create a grant, and a grant still needs the merchant's consent or two approvals.
 */
return [

    /*
     |----------------------------------------------------------------------------------------------
     | 1. Is the grant mechanism ENFORCED at the impersonation and store-switch doors?
     |----------------------------------------------------------------------------------------------
     |
     | OWNER QUESTION 1: should the platform operator be refused access to a merchant's panel when
     | no consented, unexpired grant exists?
     |
     | `SUPPORT-004` says support access requires merchant consent, so the approved answer is
     | eventually `true`. It ships `false` for one reason, stated plainly rather than hidden: with
     | no grants in the table, `true` refuses EVERY support login on the first deploy, and support
     | staff would be unable to help a merchant who is on the phone. Turning it on is an operational
     | change that needs the owner to have provisioned a way for merchants to consent, which is the
     | UI half of this requirement.
     |
     | `false` IS NOT "the mechanism does nothing". In that mode every ungranted access still writes
     | an `AuditLog` row with the action `support_access_ungranted`, so the gap is visible in the
     | same trail that will later show grants — the owner can measure how often support reaches
     | merchant data without consent BEFORE deciding to enforce. That number is the best input to
     | this decision and nobody has it today.
     |
     | Both modes are exercised: `SupportAccessEnforcementTest` drives the door with this key true
     | and false and asserts opposite outcomes.
     */
    'enforce' => env('SUPPORT_ACCESS_ENFORCE', false),

    /*
     |----------------------------------------------------------------------------------------------
     | 2. How long may a grant last?
     |----------------------------------------------------------------------------------------------
     |
     | OWNER QUESTION 2: what is the longest a support grant may run before the merchant must consent
     | again? 1440 minutes (24 hours) is a placeholder chosen to be short enough to be obviously
     | temporary and long enough to span one support conversation.
     |
     | `SupportAccessGrantFactory::request()` REFUSES a longer duration — the bound is enforced at
     | the only place a grant can be created, not advertised in a comment.
     */
    'max_duration_minutes' => env('SUPPORT_ACCESS_MAX_DURATION_MINUTES', 1440),

    /*
     |----------------------------------------------------------------------------------------------
     | 3. The scope vocabulary
     |----------------------------------------------------------------------------------------------
     |
     | OWNER QUESTION 3: which slices of a merchant's data should support be able to ask for
     | separately? The five below are derived from the panel's own existing permission groups, not
     | invented: `Manage Order`, `Manage Product`, `Manage Customer` and the settings screens each
     | exist as a permission family already.
     |
     | `full` is listed last and deliberately: it is the only scope that authorises IMPERSONATION,
     | because signing in as the merchant IS full access and pretending otherwise would make `scope`
     | decorative. A grant for `orders` lets support be shown orders; it does not let them become
     | the merchant.
     |
     | This list is the allowlist. `SupportAccessScope::isKnown()` refuses anything else, so a typo
     | in a request becomes a refusal rather than a scope that matches nothing and is never noticed.
     */
    'scopes' => ['orders', 'catalogue', 'customers', 'settings', 'full'],

    /*
     |----------------------------------------------------------------------------------------------
     | 4. Emergency access — how many approvers?
     |----------------------------------------------------------------------------------------------
     |
     | OWNER QUESTION 4: how many people must approve emergency access, and WHO may be one of them?
     |
     | `SUPPORT-005` says "موافقات أعلى" — higher approvals, plural. 2 is a placeholder meaning
     | "more than the one person who is asking".
     |
     | THE PART THAT IS NOT A NUMBER AND IS THEREFORE NOT THE OWNER'S TO DECIDE IS ALREADY ENFORCED:
     | the approvers must be DISTINCT people (a UNIQUE index, see the migration), none of them may be
     | the grantee (an operator cannot approve their own emergency access), and a single `declined`
     | row kills the grant however many approvals it has. Those are structural properties of
     | "approval by others" rather than policy settings, so they are not configurable.
     |
     | WHO may approve is question 5 below and is the one genuinely unanswerable here.
     */
    'emergency' => [
        'required_approvals' => env('SUPPORT_ACCESS_EMERGENCY_APPROVALS', 2),

        /*
         | OWNER QUESTION 5: which role approves emergency access?
         |
         | `null` means "any platform operator other than the grantee", which is the widest honest
         | reading and the only one this repository can implement today: there is no "support lead"
         | or "security officer" role in `roles`, and inventing one would be inventing an org chart.
         | Set this to a Laratrust permission name once the owner has said who holds it, and
         | `SupportAccessGate` will additionally require every approver to hold it.
         |
         | NOTE FOR WHOEVER SETS IT: CLAUDE.md records that `isAbleTo()` REFUSES the platform
         | operator, so the check in `SupportAccessGate::approverIsEligible()` tests operator status
         | FIRST and only then the permission. Setting this key does not lock the operator out.
         */
        'approver_permission' => env('SUPPORT_ACCESS_EMERGENCY_APPROVER_PERMISSION'),

        /*
         | OWNER QUESTION 2b: emergency access is meant to be shorter than ordinary support access,
         | because it skips the merchant entirely. 240 minutes (4 hours) is a placeholder.
         */
        'max_duration_minutes' => env('SUPPORT_ACCESS_EMERGENCY_MAX_DURATION_MINUTES', 240),
    ],
];
