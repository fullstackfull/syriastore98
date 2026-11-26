<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKET-004 / EX-010 -- THE RECORD THAT DECIDES WHETHER AN UPLOADED PACKAGE MAY EXECUTE.
 *
 * **DEVELOPMENT AND TEST ONLY**, exactly as `module_payment_initiations` is and for the reason that
 * file's header gives: who may run a migration against production is the owner's decision and is
 * not a property of this file. Nothing here has been run against production.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * THE MEASURED DEFECT, AND WHY A TABLE IS THE ONLY PLACE THIS CAN LIVE
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * `MARKET-004` (`docs/development/master-plan/DECISION-ID-REGISTER.md:214`) and `EX-010` (`:438`)
 * state the same prohibition from two directions: untrusted Marketplace code must not execute in
 * production. Measured against the code on 2026-11-26, the platform does exactly that, in one hop:
 *
 *   1. `ModuleController::install()` extracts an uploaded zip into `packages/workdo/<name>` and
 *      writes an `add_on_managers` row. It sets `is_enable = 0`, which reads like a safety.
 *   2. `PackageServiceProvider::loadDynamicAddons()` does `AddOnManager::pluck('module')` with
 *      **no `is_enable` predicate** and hands the result to `loadAddons()`, which calls
 *      `$this->app->register($provider)` for every provider the package's `composer.json`
 *      declares, and registers its migrations.
 *
 * So the uploaded package's `register()` and `boot()` run on the very next request, before anybody
 * has enabled it and with nothing having reviewed it. `is_enable = 0` governs *reachability* of the
 * module's routes (`App\Support\Modules\ModuleAvailability`, `DisabledModuleRouteGuard`); it has
 * never governed *execution*.
 *
 * `is_enable` cannot be reused for this, and that is the whole reason for a new table.
 * `docs/development/phase-6/PHASE-06-DISABLED-APPLICATION-BOUNDARY.md` §3 records the deliberate
 * decision NOT to filter the loader on `is_enable`, because a switched-off application must still
 * be migratable and re-enablable. That reasoning is correct and it does not transfer: a package
 * nobody has reviewed must not be migrated either. "Switched off" and "never trusted" are two
 * different states and one flag cannot hold both.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT A ROW MEANS
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * **AN ABSENT ROW IS NOT AN UNREVIEWED PACKAGE.** The same distinction `ModuleAvailability`'s
 * docblock makes, and for a sharper reason here: the 70 vendored modules under `packages/workdo/`
 * and the three shipped themes arrived with the product, through git, and no row describes them.
 * Reading "no row" as "refuse to load" would take the entire platform down on the first request
 * after this migration. A row exists only for a package that arrived through the HTTP upload door,
 * written there by `ModuleController::install()` and `AddonController::ThemeInstall()`, and that is
 * the only door by which third-party code can enter a running installation.
 *
 *   `provenance = 'uploaded'`  somebody posted a zip to this installation.
 *   `review_state = 'pending'`  the default, and the state every upload starts in. Not executable.
 *   `review_state = 'approved'` a platform operator has recorded a review decision. Executable.
 *   `review_state = 'rejected'` recorded refusal. Not executable, and the row says who and why.
 *
 * `declared_version`, `declared_dependencies` and `declared_permissions` are MARKET-002's four
 * review dimensions, captured FROM THE PACKAGE ITSELF at upload time rather than asked of a human:
 * the manifest's `version`, its `parent_module` list, and the permission names its seeder declares.
 * They are the INPUTS to a review. The review STANDARD -- what a reviewer must satisfy themselves
 * of before recording `approved` -- is an owner decision and is not encoded here or anywhere else.
 *
 * **NO COLUMN SAYS `safe`, AND NONE MAY.** Nothing in this table establishes that a package is
 * harmless; a row records that a named operator took responsibility for it on a date.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('module_review_records')) {
            return;
        }

        Schema::create('module_review_records', function (Blueprint $table) {
            $table->id();

            /* 'module' (a package under packages/workdo) or 'theme' (a directory under themes/).
               Both are code: a theme ships Blade, and `ThemeFieldCatalog::readableKeys()` does a
               literal `include` of a theme's `default_data/settings.php`. */
            $table->string('subject_type', 16);

            /*
             * The name the LOADER keys on -- `add_on_managers.module` for a package,
             * `addons.theme_id` for a theme.
             *
             * 191, NOT 64, AND THE WIDTH IS A CONTROL. Both of those columns are a plain
             * `$table->string(...)`, i.e. VARCHAR(255), and both take a value the uploader
             * influences: `install()` writes `$data['name'] ?? $fileName` from the archive's own
             * manifest. A narrower column here fails the INSERT, and `ModuleTrust::recordUpload()`
             * cannot undo an install that has already written its files -- so a name too long for
             * this column would have left no record, and no record means TRUSTED. Found by review of
             * this change, not by a test.
             *
             * 191 is the utf8mb4 index-safe length this codebase already uses for an indexed string
             * (`module_payment_initiations.route`), and the two install doors now refuse a longer
             * name outright, so the two limits agree instead of one silently losing to the other.
             */
            $table->string('subject', 191);

            $table->string('provenance', 16)->default('uploaded');
            $table->string('review_state', 16)->default('pending');

            /* MARKET-002's review inputs, read out of the package at upload time. */
            $table->string('declared_version', 40)->nullable();
            $table->text('declared_dependencies')->nullable();
            $table->text('declared_permissions')->nullable();

            /* Who uploaded it, and who decided. Deliberately NOT a foreign key: a decision must
               outlive the operator account that made it, and this project has already had a
               cascade delete destroy an audit trail. */
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 255)->nullable();

            $table->timestamps();

            /* One row per package. `firstOrNew` on this pair is how a re-upload reopens a review
               rather than silently inheriting the previous decision. */
            $table->unique(['subject_type', 'subject'], 'mrr_subject_unique');
            $table->index(['review_state', 'created_at'], 'mrr_state_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_review_records');
    }
};
