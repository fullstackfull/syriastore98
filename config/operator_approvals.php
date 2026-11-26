<?php

/**
 * `IAM-003` — TWO-PERSON APPROVAL FOR DANGEROUS OPERATOR ACTIONS.
 *
 * Recovered decision text (`docs/development/master-plan/DECISION-ID-REGISTER.md:239`):
 * *"موافقات ثنائية للإجراءات الخطرة."*
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *  `enforced` IS FALSE BY DEFAULT, AND THAT IS A REFUSAL TO INVENT A BUSINESS RULE RATHER THAN
 *  AN UNFINISHED SWITCH. THE REASON IS MEASURED.
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `database/seeders/PermissionTableSeeder::run()` creates **exactly one** `super admin` row, and
 * no path in the application writes `users.type = 'super admin'` — `PlatformOperatorOnly`'s own
 * docblock establishes that, and `RolePolicy::RESERVED_NAMES` is what stops a tenant minting one.
 * So on a stock install there is ONE operator account.
 *
 * Two-person approval with one operator account is a **lockout, not a tightening**. CLAUDE.md
 * names that failure by name — `App\Http\Middleware\FinancePermission` records it happening once
 * already — and a control that makes every governed action impossible would be turned off by the
 * first person who met it, which is worse than not shipping it.
 *
 * Enforcing it therefore needs three things that are the OWNER's to decide and not this
 * repository's to guess:
 *
 *   1. **At least two operator accounts provisioned.** There is no self-service path to a second
 *      one; see the `IAM-001` finding (operator status is binary and is not grantable through the
 *      product).
 *   2. **The action list** below, confirmed or changed. `actions` holds what is WIRED;
 *      `proposed_actions` holds what this workstream proposes and has NOT wired, each with the
 *      measured reason it belongs there.
 *   3. **The quorum.** `quorum` is 2 — the requester plus one different approver. A higher
 *      quorum is a schema and UI change, not a config change, so it is named here rather than
 *      pretended to be supported.
 *
 * The mechanism is complete and tested in BOTH modes. With `enforced` false the gate records
 * nothing and changes nothing — the premise test `…_the_shipped_default_governs_nothing` pins
 * that, so this file cannot quietly start refusing operator actions on an upgrade.
 */

return [
    /*
     * Master switch. FALSE on a stock install — see the header. `OPERATOR_APPROVALS_ENFORCED=true`
     * turns the control on for every action in `actions` below.
     */
    'enforced' => env('OPERATOR_APPROVALS_ENFORCED', false),

    /*
     * Distinct operators required for an effect to exist: the requester, plus this many minus one
     * approvers. Only 2 is implemented — `operator_action_approvals` carries a single
     * `approved_by` column, so a 3 here would be a claim the schema cannot keep. Asserted by
     * `TwoPersonApprovalConfigTest`.
     */
    'quorum' => 2,

    /*
     * THE WIRED SET. One entry per governed action: the key is the action name the gate is called
     * with, `executor` is the first-party class that performs the effect AT APPROVAL TIME, and
     * `label` is what the approvals queue shows a person.
     *
     * `plan.disable` IS THE FIRST AND ONLY WIRED ACTION, chosen rather than invented:
     *
     *   - it is already treated as dangerous by the repository — `routes/web.php` puts it behind
     *     `PlatformOperatorOnly`, and `PlanController::planDisable` repeats the operator test in
     *     the handler after `GAP-307` found a `Customer` typed `super admin` disabling a plan;
     *   - disabling a plan withdraws a product every merchant on the platform buys, so the blast
     *     radius is the whole tenant base rather than one store;
     *   - its effect is a single column on a single row, so it can be executed at APPROVAL time
     *     from a recorded payload. An action whose effect needs the requester's live session —
     *     impersonation is the example — cannot be, which is why it is in `proposed_actions` with
     *     that stated rather than wired to a mechanism that does not fit it.
     */
    'actions' => [
        'plan.disable' => [
            'label' => 'Disable or re-enable a subscription plan',
            'executor' => \App\Support\Iam\Executors\DisablePlanExecutor::class,
        ],
    ],

    /*
     * ══════════════════════════════════════════════════════════════════════════════════════════
     *  PROPOSED AND NOT WIRED — THE OWNER'S LIST TO CONFIRM.
     * ══════════════════════════════════════════════════════════════════════════════════════════
     *
     * Each is an operator action this repository ALREADY guards as dangerous, with the measured
     * citation and the reason it is not wired here. This is data for the owner decision, not
     * configuration: nothing reads this key except the test that pins it is non-empty and the
     * documentation that renders it.
     */
    'proposed_actions' => [
        'plan.order.refund' => [
            'label' => 'Refund a plan order and downgrade the merchant to the free plan',
            'evidence' => 'PlanController::refund(), behind PlatformOperatorOnly. '
                . 'PlatformOperatorOnly\'s docblock records that it marks any plan order refunded '
                . 'and then calls assignPlan(1) on a caller-supplied user_id, so it downgrades an '
                . 'ARBITRARY merchant — sabotage of a third party rather than a self-serve action.',
            'not_wired_because' => 'It moves money and changes a merchant\'s entitlement. '
                . 'CLAUDE.md requires an owner escalation for material changes to payments, so the '
                . 'action set is proposed rather than enforced.',
        ],
        'plan.bank_transfer.approve' => [
            'label' => 'Approve a bank-transfer plan payment',
            'evidence' => 'BankTransferController::orderapprove(), behind PlatformOperatorOnly. '
                . 'It sets payment_status = Approved and calls assignPlan(), so it grants a paid '
                . 'plan on the strength of an uploaded receipt one person looked at.',
            'not_wired_because' => 'Same reason as the refund above: it is a payment decision.',
        ],
        'user.delete' => [
            'label' => 'Delete a user, including a merchant tenant root',
            'evidence' => 'UserController::destroy() via UserPolicy::delete. '
                . 'docs/security/P0-USER-DELETION-CASCADE.md is the whole analysis; the handler '
                . 'already refuses fail-closed on 15 cascade and several orphan dependencies.',
            'not_wired_because' => 'The door is shared with MERCHANTS deleting their own staff, '
                . 'which must not need a platform operator. Governing it needs the narrower action '
                . '"the operator deletes a type-admin row", and which deletions count is the '
                . 'owner\'s to say.',
        ],
        'store.delete' => [
            'label' => 'Delete a store',
            'evidence' => 'StoreController::destroy(). Destroys a tenant\'s storefront.',
            'not_wired_because' => 'Same shared-door problem as user.delete: a merchant deletes '
                . 'their own store through it.',
        ],
        'operator.impersonate' => [
            'label' => 'Sign in as a merchant (impersonation)',
            'evidence' => 'StoreController::LoginWithAdmin() via UserPolicy::impersonate, '
                . 'audit-logged by App\\Support\\Audit\\RecordImpersonation. '
                . 'App\\Services\\OperatorCatalogueWrite\'s docblock records that impersonation is '
                . 'the one delegation mechanism that exists and that it is not a sound one.',
            'not_wired_because' => 'THE MECHANISM HERE DOES NOT FIT IT, and that is a design '
                . 'question rather than a policy one. Approval-time execution replays a recorded '
                . 'payload; impersonation\'s effect is a change to the REQUESTER\'S OWN live '
                . 'session, which cannot be replayed by the approver. It needs a second mode — an '
                . 'approval that GRANTS the requester the right to act, consumed on their next '
                . 'attempt — which is deliberately not built on speculation.',
        ],
        'module.disable' => [
            'label' => 'Disable a platform module for every tenant',
            'evidence' => 'ModuleController, gated by the Manage Module / Module remove names in '
                . 'config/superadminaccess.php — the 13 names a merchant may not grant themselves.',
            'not_wired_because' => 'CTRL-002 ("gradual rollout and safe disable") owns the '
                . 'semantics of disabling a feature, and CTRL-004 forbids destroying a feature\'s '
                . 'data when it is switched off. Wiring a gate here before that family is settled '
                . 'would fix the wrong half.',
        ],
    ],
];
