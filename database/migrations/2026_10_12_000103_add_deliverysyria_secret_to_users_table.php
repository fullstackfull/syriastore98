<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA-04 / SEC-R5-04 — `users.deliverysyria_secret` is declared nowhere.
 *
 * NOT PROVISIONAL. The shape is pinned by how the value is consumed: it is compared with
 * `hash_equals()` against a trimmed string, so it is a nullable VARCHAR. No production dump
 * is needed to author it.
 *
 * NO SECRET VALUE IS REPRODUCED ANYWHERE IN THIS FILE OR IN THE WORK THAT PRODUCED IT.
 * Only attribute names and file:line citations appear below, per the absolute no-secrets rule.
 *
 * EVIDENCE
 *   - grep for "deliverysyria" over both migration trees (207 files) -> 0 hits.
 *   - `$fillable` at app/Models/User.php:52.
 *   - WRITTEN at app/Http/Controllers/SettingController.php:99 and :118.
 *   - READ at six sites: app/Services/PaymentService.php:2662,
 *     app/Http/Controllers/Api/DeliverySyriaOrderController.php:158,
 *     app/Http/Controllers/DeliverySyriaController.php:17 and :37,
 *     app/Http/Controllers/AccountProfileController.php:180 and :183,
 *     app/Listeners/CompanyMenuListener.php:285.
 *
 * FAILURE MODE, stated precisely because the worse hypothesis does NOT hold. The webhook
 * authenticator fails CLOSED, not open: Api/DeliverySyriaOrderController.php:157-164 reads
 * `trim((string) ($owner->deliverysyria_secret ?? ''))` and rejects when the stored value is
 * empty OR `hash_equals` fails. Eloquent returns null for an absent column rather than
 * throwing, so the first clause trips and the request gets a 403. The consequence of the
 * missing column is therefore a 100% DEAD Delivery Syria integration on a from-zero build,
 * NOT an authentication bypass. That narrowing is why this is HIGH and not CRITICAL.
 * The WRITE path is the one that hard-fails: SettingController.php:99 `$u->save()` raises a
 * QueryException on the unknown column, reachable by any authenticated merchant saving
 * shipping settings.
 *
 * SCOPE HELD DELIBERATELY NARROW. Adding this attribute to `User::$hidden` and giving it an
 * `encrypted` cast are both worth doing and are both BEHAVIOUR CHANGES, not schema catch-up:
 * an `encrypted` cast makes existing plaintext rows undecryptable and is coupled to the
 * APP_KEY rotation decision (GATE E). They are therefore NOT done here. Note also that
 * app/Models/User.php belongs to another workstream in this increment. Tracked as a
 * cross-agent dependency / follow-up, not silently bundled.
 *
 * SEQUENCING NOTE. `users` is the highest-fan-in table in the schema (14 foreign keys
 * reference it). This migration is deliberately kept to one nullable column and is NOT
 * combined with the SCHEMA-08 `users.plan_id` type normalisation, which is GATE F work
 * because it depends on what production actually holds.
 *
 * SAFETY. `hasColumn`-guarded -> NO-OP against a database that already has the column.
 * Purely additive and nullable: no existing row changes, no existing query result changes.
 *
 * FLUTTER IMPACT: none. The column is not serialised to any app-facing payload today, and an
 * additive nullable column cannot change a JSON key's presence or type for a named-key client.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'deliverysyria_secret')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('deliverysyria_secret')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'deliverysyria_secret')) {
            return;
        }

        // DESTRUCTIVE ON ROLLBACK: dropping this column discards every store's Delivery Syria
        // webhook secret, which then has to be re-entered per store. Correct inverse of up(),
        // but do not roll this back casually on an environment that has live values.
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('deliverysyria_secret');
        });
    }
};
