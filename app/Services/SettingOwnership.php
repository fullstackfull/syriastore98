<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;

/**
 * Who a `settings` row belongs to. P1-PLT-07.
 *
 * `settings` rows are per STORE -- the pair (name, store_id) identifies one -- but two readers filter on `created_by` as well, and both
 * resolve it to the store's TENANT ROOT rather than to the viewer:
 *
 *   getAdminAllSetting($user_id, $store_id)  -- app/Helper/helper.php:362-374. A user whose type is not admin/super admin is first
 *       replaced by User::find($user->created_by), and only then is `created_by = $user->id` applied.
 *   Utility::Setting($store_id)              -- app/Models/Utility.php:32+, ['created_by' => $user->creatorId(), 'store_id' => ...].
 *
 * Meanwhile 39 of the 41 `settings` writers in app/ put `created_by` => the SAVING user into the write. A census keyed on the write
 * rather than on the method name puts each in one of two shapes:
 *
 *   RE-STAMP (36) -- match key ['name','store_id'], and Eloquent applies updateOrCreate's second argument on UPDATE too, so the row
 *       the owner created is rewritten to the staff member who saved. Both readers then return nothing for that name, so the field
 *       shows blank on the owner's Store Setting screen AND on the staff member's own -- and saving that screen writes the blank back.
 *   SHADOW ROW (3) -- the match key itself carries `created_by`, so a staff save never matches the owner's row and INSERTS a second one.
 *       `settings` has no unique index, so both survive and every pluck() reader takes whichever comes last. Those three keys are fixed
 *       at the call site; a hook cannot reach a match key.
 *
 * SO THIS IS A WRITE-SIDE RULE, AND IT IS HERE RATHER THAN AT 36 CALL SITES because App\Models\Setting::booted() already routes every
 * ELOQUENT write through one `saving` hook (HostClaims::assertFree, P1-SEC-29; StoredPathPolicy::setting, P1-SEC-28) -- which also
 * reaches the vendored module writers that P1-SEC-31 records and that cannot be edited.
 *
 * "EVERY ELOQUENT WRITE" IS THE EXACT CLAIM, AND IT IS NOT EVERY WRITE. Api\DashboardController:3290, :3370, :3470, :3572 and :3679
 * are RAW `DB::insert('insert into settings ... ON DUPLICATE KEY UPDATE ...')`, so they pass this hook, the model cache and HostClaims
 * by. They stamp `created_by = $user->id` on the admin API's email and payment settings, so the re-stamp defect stays open there for a
 * staff token -- and because `settings` has no unique index the ON DUPLICATE clause can never fire, so each of those saves APPENDS a
 * row. The 41-site census behind this file was keyed on the `updateOrCreate` spelling and therefore missed all five: census-failure
 * mode 2 from .claude/skills/store-syria-laravel-tenancy. Recorded on #110 rather than fixed here, because converting a raw upsert to
 * Eloquent changes its concurrency behaviour and belongs with P1-OWN-11's unique index.
 *
 * WHY NOT FIX THE READERS INSTEAD, which acceptance criterion 1 offers as the alternative: today `created_by = $user->id` is
 * accidentally also a tenant guard. getAdminAllSetting($x, $foreignStoreId) returns an EMPTY array because no row matches both -- a
 * fact P1-SEC-26 measured. Dropping the predicate would turn every such call into a read of the named store's settings, credentials
 * included, wherever a caller passes a store id it has not validated. That is a wider and riskier change than this one, it would need
 * all 36 call sites audited for where their store id comes from, and it is recorded rather than taken.
 *
 * TWO THINGS THIS DELIBERATELY DOES NOT DO.
 *
 *   It does not touch the PLATFORM OPERATOR on create, and it needs no special case to manage that: `User::creatorId()` returns
 *       `$this->id` for type `admin` AND `super admin` (app/Models/User.php:103-113), so the normalisation below is already a no-op for
 *       both -- it does work only for a member of staff, who is the only principal with a `created_by` of their own. An explicit
 *       operator limb was written here first and REMOVED once the removal check showed it changed nothing (an equivalent mutant),
 *       rather than left in place implying a protection it was not providing. What it was meant to protect is real:
 *       getSuperAdminAllSetting() reads `created_by = <operator> AND store_id = <operator's current_store>`, and
 *       StoreController:699-701 deliberately lets an operator park `current_store` on a store they do not own, so re-homing their
 *       creates would move platform rows onto a merchant and blank the platform settings screen. Pinned by a test instead.
 *       THE TWO PATHS ANSWER DIFFERENTLY, and an earlier version of this note got it wrong by saying only the first: on CREATE the
 *       operator keeps the row and the merchant cannot see it; on UPDATE the branch below restores the merchant's id, so the operator
 *       does NOT take the row and the merchant DOES see the change. Found by the read-only review of this file.
 *   A COST THIS CHANGE ACCEPTS, measured rather than waved at. Re-addressing the three shadow-row writers means an operator parked in
 *       a merchant's store (above) now OVERWRITES the merchant's row where before it inserted an operator-owned one. The merchant's
 *       Store Setting screen renders from `created_by = <viewer's root>`, which is empty for an operator in a foreign store, so a save
 *       from that screen posts blanks. What actually changes is narrower than it looks: the store-scoped readers the STOREFRONT uses
 *       take the newest row, so the operator's shadow row already won there before this change -- the storefront was blanked either
 *       way. This makes the damage visible on the merchant's own screen instead of hidden from it, and makes it unrecoverable instead
 *       of recoverable by deleting the operator's row. The missing control is neither of these: no settings handler checks that
 *       `getCurrentStore()` is a store the caller owns. Recorded on #110.
 *   It does not repair rows already re-stamped, and does not re-home a row whose `created_by` is neither the owner nor the saver: an
 *       update preserves exactly what is stored. That is data, not code (P1-OWN-11's third query shows the symptom class).
 */
final class SettingOwnership
{
    public static function keep(Setting $setting): void
    {
        if ($setting->exists) {
            // An update may change the value; it may never change who the row belongs to.
            if ($setting->isDirty('created_by')) {
                $setting->created_by = $setting->getOriginal('created_by');
            }

            return;
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            // A console command, a queued job or a storefront guard: leave whatever the writer named.
            return;
        }

        // Only normalise the writer's reflex of stamping ITSELF. A writer that deliberately names somebody else -- StoreController
        // seeding a new store for its merchant, defaultSetting() seeding the store owner -- is naming a tenant root already, and is
        // left exactly as it is.
        if ((int) $setting->created_by !== (int) $user->id) {
            return;
        }

        $root = $user->creatorId();
        if (! empty($root)) {
            $setting->created_by = (int) $root;
        }
    }
}
