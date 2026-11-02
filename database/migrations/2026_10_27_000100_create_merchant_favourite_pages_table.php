<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2-06-7 — per-employee favourite pages.
 *
 * AUTHORISATION AND ITS LIMIT, recorded here because the limit is the important half. The owner's
 * decision of 2026-10-27 reads: "إن لم يوجد، أوافق على تصميم وإضافة Migration صغيرة لتفضيلات
 * المفضلة في بيئات التطوير والاختبار المعزولة فقط. لا تنفيذ على الإنتاج." So this migration is
 * authorised for ISOLATED DEVELOPMENT AND TEST ENVIRONMENTS ONLY. It has NOT been run against
 * production, and running it there needs a separate owner decision — it is not implied by the
 * authorisation that allowed it to be written.
 *
 * WHY A NEW TABLE RATHER THAN AN EXISTING STORE. The owner's condition was to reuse an existing
 * store "only if it provably isolates employee, store and permissions". Measured against the
 * restored dev schema (114 tables), nothing does:
 *
 *   - `settings` is keyed `(name, value, created_by, store_id)` and `SettingOwnership` rewrites
 *     `created_by` to `$user->creatorId()`, which for a staff member resolves to the OWNING
 *     ADMIN. Two employees of one merchant therefore collapse onto one row. This is the same
 *     measurement P2-06-5 recorded for the same reason.
 *   - `users` has no preferences column (29 columns, all domain fields).
 *   - every table carrying both `user_id` and `store_id` is domain-specific: `store_notifications`,
 *     `store_devices`, `audit_logs`, `notify_users`, `order_refund_settings`,
 *     `whatsapp_messages`, `lynomia_store_identities`, `lynomia_contact_verifications`.
 *   - the P2-06-4/P2-06-5 stores are deliberately short-lived — localStorage is per-device and
 *     the Laravel session dies at logout, and favourites must survive both.
 *
 * `page` HOLDS A NAVIGATION `name`, NOT A URL, and the column is sized to make that obvious: 64
 * characters, which fits every name declared in `app/Listeners/CompanyMenuListener.php` and fits
 * no realistic URL. The owner's condition — "احفظ معرّفات صفحات أو Routes معروفة، لا روابط عشوائية
 * أو Query Strings قد تحتوي بيانات عميل" — is enforced in `App\Services\FavouritePages`, which
 * refuses anything not currently present in the gated navigation; the column width is a second,
 * structural reason a query string cannot land here.
 *
 * NO FOREIGN KEY TO `users` OR `stores`, deliberately. Both are written by vendored WorkDo code
 * paths this change does not control, and a cascade added here would make a user deletion in a
 * module's own flow behave differently than it does today. The rows are presentation preferences:
 * an orphan is harmless, is filtered at render time anyway (the page must still be in the
 * employee's navigation), and nothing reads an orphan back. Consistent with how the rest of this
 * schema treats `store_id`. (An earlier draft of this note cited a `FavouritePages::prune()` that
 * was never written -- a docblock making a claim about code that does not exist is the same
 * defect as a comment that contradicts it.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('merchant_favourite_pages')) {
            return;
        }

        Schema::create('merchant_favourite_pages', function (Blueprint $table) {
            $table->id();

            // The authenticated employee. Never taken from request input -- see FavouritePages.
            $table->unsignedBigInteger('user_id');

            // The tenant. `merchant_favourite_pages` is NOT added to config/tenancy.php's
            // `scoped_models`, and that is on purpose: `null_tenant => 'open'` means the global
            // scope adds NO predicate when no tenant resolves, so a handler relying on it would
            // be unprotected for any principal that does not resolve one. Every query in
            // FavouritePages carries an explicit `where('store_id', ...)` instead, which is the
            // rule CLAUDE.md states as "never let the scope be your only tenant check".
            $table->unsignedBigInteger('store_id');

            // A navigation `name`, e.g. 'product'. Not a route, not a URL, not a query string.
            $table->string('page', 64);

            // Merchant-chosen ordering within the favourites list.
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            /* One row per employee per store per page. The index makes a duplicate IMPOSSIBLE,
               which is not the same as making a double submit safe: a read-then-create would
               raise a constraint violation rather than a duplicate, so `toggle()` uses
               `firstOrCreate`. The palette could show two live controls for one page, so that
               race was reachable, not theoretical. */
            $table->unique(['user_id', 'store_id', 'page'], 'merchant_favourite_pages_unique');

            // The only read pattern: this employee's favourites in this store, in order.
            $table->index(['user_id', 'store_id', 'position'], 'merchant_favourite_pages_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_favourite_pages');
    }
};
