<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 06 `SUPPORT-004` / `SUPPORT-005` — THE AUTHORISATION RECORD FOR SUPPORT ACCESS.
 * =================================================================================================
 *
 * APPROVED FOR DEVELOPMENT AND ISOLATED DATABASE TESTING. **Not applied to production, and applying
 * it needs the owner's separate authorisation** — the same posture as the D8 consent tables, the
 * GAP-219 nudge ledger and the ANA-003 schedule table. Both tables are NEW and ADDITIVE: nothing
 * existing is altered, renamed or dropped, and `down()` drops only what `up()` created.
 *
 * WHAT THE OWNER APPROVED, verbatim from `docs/development/master-plan/DECISION-ID-REGISTER.md`:
 *
 *   `SUPPORT-004`  وصول دعم مؤقت ومحدد بموافقة التاجر وسجل تدقيق.
 *                  Temporary, scoped support access with merchant consent and an audit log.
 *   `SUPPORT-005`  وصول طوارئ منفصل وبصلاحيات وموافقات أعلى.
 *                  Separate emergency access, with higher permissions and approvals.
 *
 * Every adjective in `SUPPORT-004` is a clause, and before this migration exactly ONE of the four
 * was implemented. Measured on this branch, 2026-11-26, before anything was written:
 *
 *   | clause            | before                                                                  |
 *   |-------------------|-------------------------------------------------------------------------|
 *   | audit log         | **PRESENT** — `App\Support\Audit\RecordImpersonation` writes one row per |
 *   |                   | impersonation take/leave, and `StoreController::switchStore` writes      |
 *   |                   | `operator_store_switched`. Both go to `audit_logs`.                     |
 *   | temporary         | **ABSENT** — an impersonation session ends when the operator clicks      |
 *   |                   | "exit", or never. No expiry exists anywhere.                            |
 *   | scoped            | **ABSENT** — `UserPolicy::impersonate` is all-or-nothing; a grant        |
 *   |                   | reaching only orders, or only the catalogue, cannot be expressed.       |
 *   | merchant consent  | **ABSENT** — the operator self-authorises. `grep -rl "support_access\|  |
 *   |                   | SupportAccess\|EmergencyAccess\|break.glass" app/ routes/ database/`    |
 *   |                   | returned nothing at all.                                                |
 *
 * `SUPPORT-005` was ABSENT entirely: there is no second path, so today the emergency route IS the
 * ordinary route, which is the specific shape the decision forbids.
 *
 * -------------------------------------------------------------------------------------------------
 *  WHY TWO TABLES AND NOT A JSON COLUMN
 * -------------------------------------------------------------------------------------------------
 *
 * `SUPPORT-005` requires APPROVALS, plural. The number is the owner's (see `config/support_access.php`),
 * so the schema must hold any number of them — and the one security property that is NOT a number is
 * that N approvals must come from N DISTINCT people. A json array cannot carry a unique index, so
 * one approver appending twice would satisfy a two-person rule by themselves. `UNIQUE (grant_id,
 * approver_id)` is the whole reason `support_access_approvals` is a table.
 *
 * -------------------------------------------------------------------------------------------------
 *  NEITHER TABLE IS IN `config/tenancy.php`'s `scoped_models`, DELIBERATELY
 * -------------------------------------------------------------------------------------------------
 *
 * This is the opposite of the usual advice and it is load-bearing. `StoreScope` would filter these
 * rows by the ACTING principal's resolved tenant, and the acting principal here is the platform
 * OPERATOR, for whom `getCurrentStore()` returns a hardcoded `1` (`app/Helper/helper.php`). An
 * operator reading their own grants would see store 1's and no others — so the gate would refuse
 * every legitimate grant and admit nothing, or worse, a grant written for store 1 would be the only
 * one that ever matched. CLAUDE.md's rule is "never let the scope be your only tenant check"; here
 * the scope is not a tenant check at all, because a grant is a record ABOUT a tenant held BY someone
 * outside it. Every read in `App\Support\SupportAccess\SupportAccessGate` therefore carries an
 * explicit `store_id` predicate, and `SupportAccessGrantIsNotScopedTest` pins both halves.
 *
 * -------------------------------------------------------------------------------------------------
 *  NO FOREIGN KEYS
 * -------------------------------------------------------------------------------------------------
 *
 * Same reasoning as `audit_logs`, whose migration states it: a grant is part of the compliance
 * record of who reached whose data, and it must outlive the store or the staff account it names. A
 * cascade would erase the record of an access when the operator account was later closed — exactly
 * the deletion the record exists to survive — and a restrict would block that closure. Plain indexed
 * integers, as `audit_logs` has.
 *
 * -------------------------------------------------------------------------------------------------
 *  `expires_at` IS NOT NULLABLE
 * -------------------------------------------------------------------------------------------------
 *
 * "مؤقت" (temporary) is the decision's first adjective. A nullable expiry means the default grant is
 * permanent and the temporary one is the special case, which inverts the requirement — and a column
 * that is usually null gets read as "no opinion" by the next author. There is no way to write a
 * never-expiring grant through this schema. The maximum DURATION is a number and therefore the
 * owner's; the EXISTENCE of a bound is the approved decision and is enforced here in the structure.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('support_access_grants')) {
            Schema::create('support_access_grants', function (Blueprint $table) {
                $table->bigIncrements('id');

                /* THE ONE STORE THIS GRANT REACHES. Not a tenant, not a merchant — one store.
                   "محدد" (scoped) is satisfied on two axes and this is the first: a merchant who owns
                   four stores and needs help with one does not hand over the other three. */
                $table->unsignedBigInteger('store_id');

                /* The tenant-root merchant whose data this concerns, recorded so the row is readable
                   after a store is renamed or transferred. */
                $table->unsignedBigInteger('merchant_id');

                /* The support staff member the grant is FOR. A grant is personal: it authorises one
                   named human, so the audit trail names a person and not a team. */
                $table->unsignedBigInteger('grantee_id');

                /* `support` | `emergency`. See App\Support\SupportAccess\AccessKind.
                   `SUPPORT-005` requires the emergency path to be SEPARATE, so the kind is part of
                   the row's identity rather than a flag on an otherwise identical record: the two
                   kinds are activated by different predicates (consent vs. approvals) and a
                   `support` row can never become an `emergency` row by an update. */
                $table->string('kind', 16);

                /* `orders` | `catalogue` | `customers` | `settings` | `full`.
                   See App\Support\SupportAccess\SupportAccessScope. The second scope axis: WHAT of
                   the store's data. Only `full` authorises impersonation, because impersonation IS
                   full access — see the gate. */
                $table->string('scope', 32);

                /* Why support needs it, in the requester's words. Shown to the merchant when consent
                   is asked for: a consent prompt with no reason is not informed consent. */
                $table->text('reason');

                $table->unsignedBigInteger('requested_by');
                $table->timestamp('requested_at');

                /* MERCHANT CONSENT. Null until the merchant grants it, and for `kind = emergency`
                   null forever — that path is authorised by approvals INSTEAD of consent, which is
                   the entire point of `SUPPORT-005` and the reason the two are separate paths. */
                $table->timestamp('consented_at')->nullable();
                $table->unsignedBigInteger('consented_by')->nullable();

                /* NOT NULLABLE. See the docblock. */
                $table->timestamp('expires_at');

                /* Early revocation. The merchant may withdraw consent before expiry; the operator
                   and the grantee may also close a grant they no longer need. */
                $table->timestamp('revoked_at')->nullable();
                $table->unsignedBigInteger('revoked_by')->nullable();
                $table->string('revoked_reason', 191)->nullable();

                /* Use counters. A grant that was consented to and never used is a different fact
                   from one that was used sixty times, and a merchant asking "what did support
                   actually do" needs both this and the `audit_logs` rows the gate writes. */
                $table->timestamp('first_used_at')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->unsignedInteger('uses')->default(0);

                $table->timestamps();

                /* The gate's own query: this grantee, this store, newest first. */
                $table->index(['grantee_id', 'store_id', 'id'], 'sag_grantee_store_index');
                /* The merchant's "who can see my data" screen. */
                $table->index(['store_id', 'id'], 'sag_store_index');
                /* The operator's expiry sweep. */
                $table->index('expires_at', 'sag_expires_index');
            });
        }

        if (! Schema::hasTable('support_access_approvals')) {
            Schema::create('support_access_approvals', function (Blueprint $table) {
                $table->bigIncrements('id');

                $table->unsignedBigInteger('grant_id');
                $table->unsignedBigInteger('approver_id');

                /* `approved` | `declined`. A decline is recorded rather than deleted: "two people
                   approved and one refused" must not read as "two people approved". The gate treats
                   any decline as fatal to the grant. */
                $table->string('decision', 16);

                $table->string('note', 191)->nullable();
                $table->timestamp('decided_at');

                $table->timestamps();

                /* THE SECURITY PROPERTY. One approver, one decision, per grant. Without this a
                   single person satisfies any N-approver rule by posting N times. */
                $table->unique(['grant_id', 'approver_id'], 'saa_grant_approver_unique');
            });
        }
    }

    public function down(): void
    {
        // Both tables are created by this migration and by nothing else, so dropping them destroys
        // only what it created. That is NOT true of `audit_logs`, whose own migration explains why
        // its `down()` is a no-op, and the grant rows here are deliberately not the audit trail:
        // the trail of support access lives in `audit_logs` and survives this rollback. A grant row
        // is the AUTHORISATION; the audit row is the RECORD OF USE. Losing the former on a rollback
        // of the feature that created it is correct; losing the latter would not be.
        Schema::dropIfExists('support_access_approvals');
        Schema::dropIfExists('support_access_grants');
    }
};
