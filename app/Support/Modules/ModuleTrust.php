<?php

namespace App\Support\Modules;

use App\Models\ModuleReviewRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * MAY THIS PACKAGE'S CODE RUN? One answer, for the prohibition that has no other enforcement.
 *
 * `MARKET-004` (`DECISION-ID-REGISTER.md:214`) and `EX-010` (`:438`) forbid executing untrusted
 * external code in production. MEASURED 2026-11-26, the platform did it in one hop and the hop is
 * two methods long:
 *
 *   `ModuleController::install()`      extracts an uploaded zip into `packages/workdo/<name>` and
 *                                      writes an `add_on_managers` row with `is_enable = 0`.
 *   `PackageServiceProvider::loadDynamicAddons()`
 *                                      `AddOnManager::pluck('module')` -- NO `is_enable` predicate
 *                                      -- then `loadAddons()`, which calls
 *                                      `$this->app->register($provider)` for each provider the
 *                                      package's own `composer.json` names, and registers its
 *                                      migrations.
 *
 * So the uploaded package's `register()` and `boot()` ran on the next request. Nothing had enabled
 * it; nothing had reviewed it. `is_enable = 0` looks like a safety and is not one: it governs
 * whether the module's ROUTES answer (`ModuleAvailability`, `DisabledModuleRouteGuard`) and has
 * never governed whether its code executes.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS IS NOT `is_enable`, WHICH IS THE OBVIOUS ONE-LINE FIX AND IS THE WRONG ONE
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * `docs/development/phase-6/PHASE-06-DISABLED-APPLICATION-BOUNDARY.md` §3 already considered
 * `where('is_enable', 1)` at the loader and rejected it, for a reason that is right: `loadAddons()`
 * registers a module's migrations, console commands and event listeners too, so filtering there
 * would mean a switched-off application could not be migrated, could not be re-enabled cleanly and
 * would silently stop whatever scheduled work it owns. It would fail by BREAKING rather than by
 * refusing.
 *
 * None of that transfers to an unreviewed upload, and the difference is the justification for a
 * second flag rather than a reuse of the first. A package nobody has reviewed must not be migrated
 * either -- its migrations are its code. "Switched off" is a state a trusted application returns
 * from; "never reviewed" is a state it has not yet entered. One column cannot hold both.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * AN ABSENT ROW IS NOT AN UNREVIEWED PACKAGE
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * The most important line here, and the same distinction `ModuleAvailability` makes with a sharper
 * consequence. The 70 vendored modules and the three shipped themes arrived through git with the
 * product; no row describes them. Reading "no row" as "refuse" would unload every module on the
 * first request after the migration -- `LandingPage` alone owns 275 routes including the public
 * landing pages, and `PackageServiceProvider` force-adds it. A row exists only for a package that
 * came through the HTTP upload door, and that door is the only way third-party code enters a
 * running installation.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * THE FAILURE DIRECTIONS ARE DIFFERENT FOR THE TWO READS, ON PURPOSE
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * `ModuleAvailability` fails OPEN on every error and says why: it is a bounded availability control
 * and breaking every module route because a cache driver hiccuped is the worse failure. This class
 * cannot copy that wholesale, because failing open here means running unreviewed code -- so:
 *
 *   - **the table is absent**  -> TRUSTED. Nothing has ever been recorded against this database, so
 *                                no upload has happened through the recorded door. This is a fresh
 *                                checkout before `migrate`, and it is exactly the state the
 *                                application is in today. Refusing here would brick a new install.
 *   - **the schema is unreachable** -> TRUSTED, for the same reason and one more: the only caller
 *                                that reaches `loadAddons()` without a working database is the
 *                                STATIC pass in `register()`, whose two names are first-party.
 *   - **the table exists and the read throws** -> REFUSED, and logged `critical`. We can see the
 *                                register and cannot read it; that is the one case where a guess
 *                                would be a guess about third-party code, and the answer is no.
 *
 * The asymmetry is deliberate and is asserted in both directions by
 * `tests/Unit/Modules/ModuleTrustTest`.
 */
final class ModuleTrust
{
    public const TABLE = 'module_review_records';

    /**
     * The widest subject the register can key, and therefore the widest an install may accept.
     *
     * `module_review_records.subject` is VARCHAR(191). `add_on_managers.module` and
     * `addons.theme_id` are both VARCHAR(255) and both take a value derived from the UPLOAD -- the
     * archive's own `module.json` `name` for a module, the uploaded filename for a theme. So a name
     * between 192 and 255 characters would install fine and fail THIS table's insert, and
     * `recordUpload()` cannot undo an install whose files are already written: no record means
     * trusted, which is the one outcome the boundary must not have. The install doors refuse a
     * longer name instead, and `nameFitsTheRegister()` is the single predicate both use.
     */
    public const MAX_SUBJECT_LENGTH = 191;

    /** Can this name be keyed in the register at all? Both install doors ask before writing a row. */
    public static function nameFitsTheRegister(?string $subject): bool
    {
        return $subject !== null && mb_strlen($subject) <= self::MAX_SUBJECT_LENGTH;
    }

    /**
     * True when this package's code may be loaded and run.
     *
     * Deliberately NOT routed through `App\Models\ModuleReviewRecord`: this runs inside
     * `PackageServiceProvider` while the container is still assembling, and the model's absence
     * from the `Cachable` set is a decision that must not be able to drift into this read. The
     * query builder has no model cache to be stale.
     */
    public static function mayExecute(?string $subject, string $subjectType = ModuleReviewRecord::TYPE_MODULE): bool
    {
        if ($subject === null || trim($subject) === '') {
            return true;
        }

        try {
            if (! Schema::hasTable(self::TABLE)) {
                return true;
            }
        } catch (\Throwable) {
            return true;
        }

        try {
            $row = DB::table(self::TABLE)
                ->where('subject_type', $subjectType)
                ->where('subject', $subject)
                ->first(['provenance', 'review_state']);
        } catch (\Throwable $e) {
            Log::critical('module trust: the review register exists and could not be read, so the package is refused', [
                'subject' => $subject,
                'subject_type' => $subjectType,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        if ($row === null) {
            return true;
        }

        if ($row->provenance !== ModuleReviewRecord::PROVENANCE_UPLOADED) {
            return true;
        }

        return $row->review_state === ModuleReviewRecord::STATE_APPROVED;
    }

    /**
     * Keep only the packages that may execute, and say in the log which were dropped.
     *
     * NAMED, because which module is missing is the single most useful fact about this outcome and
     * is precisely what `PackageServiceProvider`'s two older silent failures did not record.
     *
     * @param  list<string>  $modules
     * @return list<string>
     */
    public static function executable(array $modules): array
    {
        $allowed = [];
        $refused = [];

        foreach ($modules as $module) {
            if (self::mayExecute($module)) {
                $allowed[] = $module;
            } else {
                $refused[] = $module;
            }
        }

        if ($refused !== []) {
            Log::warning('module trust: an uploaded package was not loaded because no approved review decision exists for it', [
                'modules' => $refused,
            ]);
        }

        return array_values($allowed);
    }

    /**
     * Record that a package arrived by upload, and capture MARKET-002's review inputs from it.
     *
     * A RE-UPLOAD REOPENS THE REVIEW. `updateOrInsert` on the unique pair resets `review_state` to
     * `pending` and clears the previous decision, which is the only safe reading of a replaced
     * archive: `ModuleController::install()` deletes the existing directory and writes new bytes,
     * so an earlier `approved` describes code that is no longer on disk. Inheriting it would turn
     * one approval into a standing permission to ship anything under that name.
     *
     * Never throws. The install handlers call this after the files are already on disk, and a
     * failure to WRITE the record must not be reported to the operator as a failed install when the
     * install happened -- the loader's own refusal is what keeps the code from running, and with no
     * row... the loader would trust it. So the write failing is logged `critical`: it is the one
     * outcome where the boundary is open and nobody has been told.
     */
    public static function recordUpload(
        string $subject,
        string $subjectType,
        ?string $directory,
        ?int $uploadedBy
    ): void {
        try {
            if (! Schema::hasTable(self::TABLE)) {
                Log::critical('module trust: an upload could not be recorded because the review register is not migrated', [
                    'subject' => $subject,
                    'subject_type' => $subjectType,
                ]);

                return;
            }

            $permissions = null;
            $version = null;
            $dependencies = [];

            if ($directory !== null && is_dir($directory)) {
                $version = PackageDeclaration::declaredVersion($directory);
                $dependencies = PackageDeclaration::declaredDependencies($directory);
                $permissions = PackageDeclaration::declaredPermissions($directory);
            }

            $now = now();

            DB::table(self::TABLE)->updateOrInsert(
                ['subject_type' => $subjectType, 'subject' => $subject],
                [
                    'provenance' => ModuleReviewRecord::PROVENANCE_UPLOADED,
                    'review_state' => ModuleReviewRecord::STATE_PENDING,
                    'declared_version' => $version,
                    'declared_dependencies' => $dependencies === [] ? null : implode(',', $dependencies),
                    'declared_permissions' => $permissions === null ? null : json_encode($permissions),
                    'uploaded_by' => $uploadedBy,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_note' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        } catch (\Throwable $e) {
            Log::critical('module trust: an upload could not be recorded, so nothing holds this package back from loading', [
                'subject' => $subject,
                'subject_type' => $subjectType,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
