<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `IAM-003` — TWO-PERSON APPROVAL FOR DANGEROUS OPERATOR ACTIONS. The durable record of a
 * request, who made it, who approved it, and when it took effect.
 *
 * Recovered decision text (`docs/development/master-plan/DECISION-ID-REGISTER.md:239`):
 * *"موافقات ثنائية للإجراءات الخطرة."* — two-person approval for dangerous actions.
 *
 * **DEVELOPMENT AND TEST ONLY, exactly as `module_payment_initiations` is and for the reason its
 * header gives:** the restriction is on who may run a migration against production, which is the
 * owner's decision and not a property of this file. Nothing here has been run against production
 * and nothing may be without that decision.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHY A TABLE AND NOT `audit_logs`
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * `audit_logs` records what HAPPENED. This table records what has been ASKED FOR and has not
 * happened yet — a row in `pending` state is the statement "this effect does not exist in the
 * product". Those are different lifecycles: an audit row is append-only and never changes, while
 * one of these transitions and must be queried by state to render an approvals queue. Putting a
 * mutable workflow row in an append-only compliance trail would make the trail untrustworthy.
 *
 * Both are written: `App\Support\Iam\TwoPersonApproval` records every request, approval, refusal
 * and execution in `audit_logs` as well, which is `IAM-004`'s "central" half.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT A ROW IS, BY `state`
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 *   `pending`   one operator asked for the action. **The effect does not exist.**
 *   `approved`  a SECOND, DIFFERENT operator approved it, and the executor has not finished.
 *               Transient: `approve()` moves a row through this to `executed` or `failed` in one
 *               transaction. A row resting here means the process died mid-execution, which is
 *               why the state is distinguishable rather than folded into `executed`.
 *   `executed`  the executor ran and the effect exists. `executed_at` and `outcome` say what it
 *               did.
 *   `failed`    a second operator approved, and the executor REFUSED at execution time — the
 *               world changed between the request and the approval. `refusal_reason` says which
 *               rule refused. This is not an error state to retry blindly: the refusal is the
 *               product working.
 *   `rejected`  a second operator refused the request. `refusal_reason` carries their words.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * NO FOREIGN KEYS, deliberately, and for `audit_logs`' reason: an approval record must outlive
 * the user it names. A cascade would destroy the evidence of who approved a deletion precisely
 * when that evidence matters most, and a restrict would block the deletion the record exists to
 * authorise. `requested_by` and `approved_by` are plain indexed integers.
 *
 * NO `store_id`. Every action governed here is a PLATFORM action — a plan, a module, another
 * tenant's money. Attributing one to a store would be the `getCurrentStore()` fabrication
 * `AuditAttribution` exists to undo: that helper returns the hardcoded literal `1` for a
 * `super admin`, so a `store_id` here would file every operator action under store 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operator_action_approvals')) {
            return;
        }

        Schema::create('operator_action_approvals', function (Blueprint $table) {
            $table->bigIncrements('id');

            /* The governed action's key, as `config/operator_approvals.php` spells it. 64 is the
               same width `module_payment_initiations.module` uses for the same kind of value. */
            $table->string('action', 64);

            /* The request's own arguments, as the gate received them AFTER validation — never the
               raw request bag. `TwoPersonApproval` is what narrows it; see that class. */
            $table->text('payload')->nullable();

            /*
             * SHA-256 of action + canonicalised payload. It exists so that a repeat of the SAME
             * request finds its own pending row instead of minting a second one — an operator
             * clicking twice must not create two approvals, because two approvals are two
             * executions.
             *
             * NOT a security boundary: it is derived from data the requester chose, so it says
             * "this is the same ask", never "this ask is legitimate". Authorisation is
             * `requested_by`/`approved_by` and the operator test around them.
             */
            $table->char('fingerprint', 64);

            $table->string('state', 16)->default('pending');

            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('approved_by')->nullable();

            $table->timestamp('decided_at')->nullable();
            $table->timestamp('executed_at')->nullable();

            /* Which rule refused, or the approver's words when they rejected. A REASON and not a
               flag, because CLAUDE.md records that a refusal asserted without its reason is
               satisfied by a crash, a missing row or an unrelated earlier rule. */
            $table->string('refusal_reason', 255)->nullable();

            /* What the executor did, for a person reading the row later. */
            $table->text('outcome')->nullable();

            $table->timestamps();

            $table->index(['state', 'created_at'], 'oaa_state_created_index');
            $table->index(['action', 'state'], 'oaa_action_state_index');
            $table->index(['fingerprint', 'state'], 'oaa_fingerprint_state_index');
            $table->index('requested_by', 'oaa_requested_by_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_action_approvals');
    }
};
