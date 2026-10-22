<?php

namespace Tests\Feature\Security;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P1-PLT-07 -- a save by anyone but the owner moves the owner's settings rows to the saver, or shadows them with a second row.
 * =============================================================================================
 *
 * TWO WRITE SHAPES, one defect. A census keyed on the WRITE rather than on the method name (41 `settings` updateOrCreate calls in
 * `app/`) puts 39 of them in one of these:
 *
 *   RE-STAMP (36 sites) -- match key ['name','store_id'], and the UPDATE payload carries `created_by` => the saving user. Eloquent
 *       applies the second argument on update as well as on create, so the row the owner created is rewritten with the staff
 *       member's id.
 *   SHADOW ROW (3 sites) -- the match key ITSELF includes `created_by`, so a staff save never matches the owner's row and INSERTS a
 *       second row with the same name in the same store. `settings` has no unique index, so both rows stay and every `pluck()`
 *       reader takes whichever comes last.
 *
 * WHY IT IS VISIBLE. Two readers filter on `created_by`, and BOTH resolve it to the TENANT ROOT rather than to the viewer:
 *
 *   getAdminAllSetting($user_id, $store_id) -- `helper.php:362-364` replaces a user whose type is not admin/super admin with
 *       `User::find($user->created_by)` FIRST, then filters `created_by = $user->id`. `AppSettingController::index():26` calls it as
 *       `getAdminAllSetting($user->id, $store->id)`.
 *   Utility::Setting($store_id) -- `app/Models/Utility.php:32+`, conditions ['created_by' => $user->creatorId(), 'store_id' => ...].
 *
 * So a staff save blanks the field on the STAFF member's own next view too, not only the owner's -- both readers ask for the owner's
 * id. That is asserted below, because it is the half the ledger row did not say.
 *
 * THE CREATE PATH IS NOT A CORNER CASE, and it is why preserving `created_by` on update alone would not close this. `defaultSetting()`
 * (`helper.php:980-1130`) seeds a new store with ~27 names, and NONE of them are the names these two tabs post -- not
 * `google_analytic`, `fbpixel_code`, `metakeyword`, `metadesc`, and not `store_address`/`store_city`/`store_state`/`store_zipcode`/
 * `store_country`. For exactly the tabs at issue the FIRST save creates the row, so a staff-created row is stamped with the staff
 * member's id and is invisible to both readers from the start.
 *
 * WHAT P1-SEC-27 ALREADY COVERS, and is therefore not re-tested here: `SettingsRestrictedWriteTest` pins the same two shapes for the
 * RESTRICTED names, which the restricted-post check removes from the request when they are unchanged. Every ORDINARY field still
 * carries the defect, which is what this file drives.
 *
 * Ordinary names are used throughout so that a refusal can never be the reason a row did not move: staff holding
 * `Manage Store Setting` are allowed to write every name posted here.
 */
class SettingsCreatedByOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const STORE_PERMISSION = 'Manage Store Setting';

    private User $merchant;
    private Store $store;
    /** staff of this store holding `Manage Store Setting` -- allowed to post every name used here */
    private User $storeStaff;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->merchant, $this->store] = $this->makeTenant();
        $this->storeStaff = $this->makeStaff($this->merchant);
        $this->grantPermission($this->storeStaff, self::STORE_PERMISSION);
    }

    /** Rows for a name in this store, oldest first: value and created_by straight from the table, never through a cached model. */
    private function rowsFor(string $name): array
    {
        return DB::table('settings')->where('store_id', $this->store->id)->where('name', $name)
            ->orderBy('id')->get(['value', 'created_by'])
            ->map(fn ($r) => ['value' => $r->value, 'created_by' => (string) $r->created_by])->all();
    }

    private function seedRow(string $name, string $value, ?int $createdBy = null): void
    {
        DB::table('settings')->insert([
            'name' => $name, 'value' => $value, 'store_id' => $this->store->id,
            'created_by' => $createdBy ?? $this->merchant->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private static function seoFields(): array
    {
        return ['google_analytic' => 'g', 'fbpixel_code' => 'f', 'metakeyword' => 'k', 'metadesc' => 'd'];
    }

    /** What the Store Setting screen itself would show this user, through its own reader. */
    private function screenFor(User $viewer): array
    {
        $this->flushReaders();

        return $this->actingAs($viewer)->app->call(function () {
            return getAdminAllSetting(auth()->user()->id, $this->store->id);
        });
    }

    private function flushReaders(): void
    {
        (new \App\Models\Setting())->flushCache();
        (new User())->flushCache();
        \Illuminate\Support\Facades\Cache::flush();
    }

    // ---------------------------------------------------------------------------------------------
    // premise -- without these, every assertion below could pass for the wrong reason
    // ---------------------------------------------------------------------------------------------

    /** The route exists, the staff member is admitted, and the value really is written. */
    public function test_premise_staff_holding_manage_store_setting_do_save_an_ordinary_name(): void
    {
        $this->seedRow('metakeyword', 'OWNER-VALUE');

        $this->actingAs($this->storeStaff)
            ->post('/seo-setting', ['metakeyword' => 'STAFF-VALUE'] + self::seoFields())
            ->assertSessionMissing('error');

        $this->assertContains('STAFF-VALUE', array_column($this->rowsFor('metakeyword'), 'value'),
            'The staff member was refused or the route does not write, so nothing below would be measuring ownership.');
    }

    /**
     * The names these two tabs post are NOT seeded for a new store. This is what makes the create path the normal path, and it is
     * asserted rather than assumed because the whole shape of the fix depends on it.
     *
     * IT MUST CALL defaultSetting() ITSELF. The first version of this test asserted "no rows" against the bare makeTenant() fixture,
     * which never seeds anything (tests/Feature/Security/SecurityTestCase.php:168-177) -- so it passed for every name in the table and
     * would have gone on passing if defaultSetting() started seeding `metakeyword` tomorrow, which is the one thing it claims to rule
     * out. Found by the read-only review of this change.
     */
    public function test_premise_the_tabs_own_names_are_not_seeded_for_a_new_store(): void
    {
        $this->makePlatformAdmin();   // the non-platform branch reads the super admin's own rows

        $this->app->call(function () {
            defaultSetting($this->store->id, 'company', $this->merchant);
        });

        $seeded = DB::table('settings')->where('store_id', $this->store->id)->pluck('name')->all();
        $this->assertGreaterThan(20, count($seeded),
            'premise of the premise: defaultSetting() really did seed this store, so "absent" below means absent from ITS list.');

        foreach (['google_analytic', 'fbpixel_code', 'metakeyword', 'metadesc', 'store_address', 'store_city'] as $name) {
            $this->assertNotContains($name, $seeded,
                "{$name} is seeded after all, so a staff save would be an UPDATE and the create half of this defect would not arise.");
        }
    }

    // ---------------------------------------------------------------------------------------------
    // RE-STAMP: /seo-setting and /shipping-label-setting key on (name, store) and rewrite created_by
    // ---------------------------------------------------------------------------------------------

    public function test_a_staff_save_does_not_move_the_owners_existing_row_to_the_staff_member(): void
    {
        $this->seedRow('metakeyword', 'OWNER-VALUE');

        $this->actingAs($this->storeStaff)
            ->post('/seo-setting', ['metakeyword' => 'STAFF-VALUE'] + self::seoFields())
            ->assertSessionMissing('error');

        $this->assertSame(
            [['value' => 'STAFF-VALUE', 'created_by' => (string) $this->merchant->id]],
            $this->rowsFor('metakeyword'),
            'The value must change and the ownership must not: one row, still the owner\'s.'
        );
    }

    public function test_the_same_holds_for_the_shipping_label_tab(): void
    {
        $this->seedRow('store_city', 'Damascus');

        $this->actingAs($this->storeStaff)->post('/shipping-label-setting', [
            'store_address' => 'a', 'store_city' => 'Aleppo', 'store_state' => 's', 'store_zipcode' => 'z', 'store_country' => 'k',
        ])->assertSessionMissing('error');

        $this->assertSame(
            [['value' => 'Aleppo', 'created_by' => (string) $this->merchant->id]],
            $this->rowsFor('store_city')
        );
    }

    /** The acceptance criterion as the merchant experiences it. */
    public function test_the_owners_own_screen_shows_what_staff_saved(): void
    {
        $this->seedRow('metakeyword', 'OWNER-VALUE');

        $this->actingAs($this->storeStaff)
            ->post('/seo-setting', ['metakeyword' => 'STAFF-VALUE'] + self::seoFields())
            ->assertSessionMissing('error');

        $this->assertSame('STAFF-VALUE', $this->screenFor($this->merchant)['metakeyword'] ?? null,
            "The owner's Store Setting screen shows the field blank, and saving that screen writes the blank back.");
    }

    /**
     * The half the ledger row did not record: both readers ask for the TENANT ROOT's id, so the staff member who just saved cannot
     * see their own value either.
     */
    public function test_the_staff_members_own_screen_shows_it_too(): void
    {
        $this->seedRow('metakeyword', 'OWNER-VALUE');

        $this->actingAs($this->storeStaff)
            ->post('/seo-setting', ['metakeyword' => 'STAFF-VALUE'] + self::seoFields())
            ->assertSessionMissing('error');

        $this->assertSame('STAFF-VALUE', $this->screenFor($this->storeStaff)['metakeyword'] ?? null,
            'getAdminAllSetting walks a staff member up to their owner before filtering, so a re-stamped row hides from the saver too.');
    }

    /** The create path: no row exists, staff save it first, and the owner must still see it. */
    public function test_a_name_first_saved_by_staff_is_visible_to_the_owner(): void
    {
        $this->assertSame([], $this->rowsFor('metadesc'), 'premise: no row yet');

        $this->actingAs($this->storeStaff)
            ->post('/seo-setting', ['metadesc' => 'STAFF-CREATED'] + self::seoFields())
            ->assertSessionMissing('error');

        $this->assertSame('STAFF-CREATED', $this->screenFor($this->merchant)['metadesc'] ?? null,
            'A row created by staff is stamped with the staff id, so the owner-keyed reader never returns it.');
    }

    /** The second reader, which many storefront and admin screens use. */
    public function test_the_other_owner_keyed_reader_sees_it_as_well(): void
    {
        $this->seedRow('metakeyword', 'OWNER-VALUE');

        $this->actingAs($this->storeStaff)
            ->post('/seo-setting', ['metakeyword' => 'STAFF-VALUE'] + self::seoFields())
            ->assertSessionMissing('error');

        $this->flushReaders();
        $seen = $this->actingAs($this->merchant)->app->call(function () {
            return \App\Models\Utility::Setting($this->store->id);
        });

        $this->assertSame('STAFF-VALUE', $seen['metakeyword'] ?? null,
            'Utility::Setting filters created_by = creatorId(), so a re-stamped row is invisible to it too.');
    }

    // ---------------------------------------------------------------------------------------------
    // SHADOW ROW: /theme-settings puts created_by IN the match key
    // ---------------------------------------------------------------------------------------------

    public function test_a_staff_save_does_not_insert_a_second_row_for_a_name_the_owner_already_has(): void
    {
        $this->seedRow('store_city', 'Damascus');

        $this->actingAs($this->storeStaff)
            ->post('/theme-settings', ['store_city' => 'Aleppo'])
            ->assertSessionMissing('error');

        $this->assertCount(1, $this->rowsFor('store_city'),
            'A second row with the same name in the same store was inserted; `settings` has no unique index, so both survive and '
            . 'every pluck() reader takes whichever comes last.');
        $this->assertSame(
            [['value' => 'Aleppo', 'created_by' => (string) $this->merchant->id]],
            $this->rowsFor('store_city')
        );
    }

    // ---------------------------------------------------------------------------------------------
    // the legitimate journeys, so a fix cannot close this by refusing the save
    // ---------------------------------------------------------------------------------------------

    public function test_the_owners_own_save_still_works_and_keeps_their_ownership(): void
    {
        $this->seedRow('metakeyword', 'OLD');

        $this->actingAs($this->merchant)
            ->post('/seo-setting', ['metakeyword' => 'NEW'] + self::seoFields())
            ->assertSessionMissing('error');

        $this->assertSame(
            [['value' => 'NEW', 'created_by' => (string) $this->merchant->id]],
            $this->rowsFor('metakeyword')
        );
    }

    /**
     * NOT A FIX -- this pins behaviour deliberately left alone, so that changing it later is a decision rather than an accident. It
     * covers BOTH paths, because they answer differently and an earlier version of this test asserted the create answer under a name
     * that claimed both. Found by the read-only review of this change.
     *
     * CREATE: a name with no row yet is created owned by the operator, so the merchant cannot see it. `User::creatorId()` returns
     *     `$this->id` for `super admin`, so the normalisation is a no-op for them; no special case is involved.
     * UPDATE: a name the merchant already owns keeps the MERCHANT as owner -- the update branch restores it -- so the operator's value
     *     IS visible to the merchant and is NOT visible on the operator's own screen.
     *
     * Why the create answer is not "fixed" to the store's root: `getSuperAdminAllSetting()` reads `created_by = <operator> AND
     * store_id = <operator's current_store>`, and StoreController:699-701 deliberately lets an operator park `current_store` on a store
     * they do not own -- so re-homing their creates would move platform rows onto a merchant and blank the platform settings screen.
     * The operator case is also outside this task's acceptance criteria, which name a member of staff.
     */
    public function test_an_operator_save_is_not_normalised_on_create_but_is_preserved_on_update(): void
    {
        $operator = $this->makePlatformAdmin();
        $operator->current_store = $this->store->id;
        $operator->save();

        // CREATE: metadesc has no row.
        // UPDATE: metakeyword already belongs to the merchant.
        $this->seedRow('metakeyword', 'MERCHANT-VALUE');

        $this->actingAs($operator)
            ->post('/seo-setting', ['metadesc' => 'OPERATOR-CREATED', 'metakeyword' => 'OPERATOR-UPDATED'] + self::seoFields())
            ->assertSessionMissing('error');

        $created = $this->rowsFor('metadesc');
        $this->assertNotSame([], $created, 'premise: the operator is admitted to this screen and the row is written');
        $this->assertSame('OPERATOR-CREATED', $created[0]['value']);
        $this->assertSame((string) $operator->id, $created[0]['created_by'],
            'If this now reads as the store owner, the create branch stopped being a no-op for an operator -- check that '
            . 'getSuperAdminAllSetting() still finds the platform settings before accepting it.');
        $this->assertNull($this->screenFor($this->merchant)['metadesc'] ?? null,
            'The recorded cost on create: the merchant cannot see a name the operator created on their tab.');

        $this->assertSame(
            [['value' => 'OPERATOR-UPDATED', 'created_by' => (string) $this->merchant->id]],
            $this->rowsFor('metakeyword'),
            'On update the row keeps its merchant owner, so the operator does NOT take it.'
        );
        $this->assertSame('OPERATOR-UPDATED', $this->screenFor($this->merchant)['metakeyword'] ?? null,
            'and the merchant can therefore see what the operator changed.');
    }

    /**
     * THE ROW A SAVE LANDS ON MUST BE THE ROW THE READERS SERVE.
     *
     * `settings` has no unique index, and the three writers this change re-addressed are exactly what produced duplicate
     * (name, store_id) pairs before it. Readers take the NEWEST row (`pluck()`'s later keys win); `updateOrCreate`'s own lookup has no
     * ORDER BY and takes the oldest. MEASURED before the fix: a save landed on the oldest row while `Utility::GetValueByName` kept
     * returning the newest -- so on exactly the stores this defect created, the merchant's save was silently lost behind a success
     * message. Found by the read-only review of this change; `Setting::putForStore()` is the answer.
     */
    public function test_a_save_reaches_the_row_the_readers_serve_even_when_the_store_carries_duplicates(): void
    {
        $this->seedRow('store_city', 'OLDEST', $this->merchant->id);
        $this->seedRow('store_city', 'NEWEST-IS-WHAT-READERS-SERVE', $this->storeStaff->id);

        $this->flushReaders();
        $this->assertSame('NEWEST-IS-WHAT-READERS-SERVE', \App\Models\Utility::GetValueByName('store_city', $this->store->id),
            'premise: with two rows the store-scoped reader serves the newest.');

        $this->actingAs($this->storeStaff)
            ->post('/theme-settings', ['store_city' => 'SAVED'])
            ->assertSessionMissing('error');

        $this->flushReaders();
        $this->assertSame('SAVED', \App\Models\Utility::GetValueByName('store_city', $this->store->id),
            'The save must be visible to the reader the storefront uses, not stranded on an older duplicate row.');
        $this->assertCount(2, $this->rowsFor('store_city'),
            'and it must not delete the older duplicate -- that is data, and P1-OWN-11 owns it.');
    }

    /**
     * The Firebase tab, whose write was addressed by (name, saving user) with NO store at all.
     *
     * One merchant, two of their own stores. Saving in the second store used to FIND the row written in the first (same name, same
     * user) and the payload's `store_id` then MOVED it -- so the first store's Firebase settings were blanked by a save in the second.
     *
     * Added because the review found this key change -- the largest in the diff -- pinned by nothing: reverting it kept the suite green.
     */
    public function test_a_firebase_save_in_one_store_leaves_the_same_merchants_other_store_alone(): void
    {
        $this->grantPermission($this->merchant, 'Manage Setting');
        $secondStore = $this->makeStore($this->merchant);

        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->actingAs($this->merchant)->post('/firebase-settings', ['firebase_enabled' => 'on', 'fcm_Key' => 'KEY-STORE-ONE'])
            ->assertSessionMissing('error');

        $this->merchant->current_store = $secondStore->id;
        $this->merchant->save();
        $this->actingAs($this->merchant)->post('/firebase-settings', ['firebase_enabled' => 'on', 'fcm_Key' => 'KEY-STORE-TWO'])
            ->assertSessionMissing('error');

        $first = DB::table('settings')->where('store_id', $this->store->id)->where('name', 'fcm_Key')->pluck('value')->all();
        $second = DB::table('settings')->where('store_id', $secondStore->id)->where('name', 'fcm_Key')->pluck('value')->all();

        $this->assertSame(['KEY-STORE-ONE'], $first, "The first store's key was moved or blanked by a save in the second store.");
        $this->assertSame(['KEY-STORE-TWO'], $second, 'premise: the second store got its own row');
    }

    /**
     * The stock tab, the third re-addressed write. Same shadow-row shape as /theme-settings.
     *
     * Added for the same reason: the review found this key change pinned by nothing.
     */
    public function test_a_stock_save_by_staff_updates_the_owners_row_rather_than_adding_one(): void
    {
        $settingsStaff = $this->makeStaff($this->merchant);
        $this->grantPermission($settingsStaff, 'Manage Setting');
        $this->seedRow('out_of_stock_threshold', '7');

        // `notification` is `required` on this handler's validator -- omitting it is a redirect with an error, and the row assertion
        // below would then have passed against a request that never reached the write.
        $this->actingAs($settingsStaff)->post('/stock-settings', [
            'low_stock_threshold' => '3', 'out_of_stock_threshold' => '1', 'stock_management' => 'on', 'notification' => ['mail'],
        ])->assertSessionMissing('error');

        $this->assertSame(
            [['value' => '1', 'created_by' => (string) $this->merchant->id]],
            $this->rowsFor('out_of_stock_threshold'),
            'One row, updated in place, still the owner\'s.'
        );
    }

    /**
     * The guard that only normalises a writer stamping ITSELF, and why it is not optional.
     *
     * `defaultSetting()` seeds a new store's rows with `created_by` => the STORE'S OWNER, while the person signed in may be the
     * platform operator who created the store for them. Normalising every create to the saving user's root would stamp all ~27 seeded
     * rows to the operator, and the merchant's own Store Setting screen would open blank on a brand-new store.
     *
     * Added because a removal check on that guard SURVIVED: nothing in this file reached a writer that names somebody else.
     */
    public function test_a_store_seeded_by_the_operator_for_a_merchant_belongs_to_the_merchant(): void
    {
        $this->makePlatformAdmin();           // defaultSetting()'s non-platform branch reads the super admin's own rows
        $operator = $this->makePlatformAdmin();
        [$otherMerchant, $otherStore] = $this->makeTenant();

        $this->actingAs($operator)->app->call(function () use ($otherStore, $otherMerchant) {
            defaultSetting($otherStore->id, 'company', $otherMerchant);
        });

        $owners = DB::table('settings')->where('store_id', $otherStore->id)
            ->pluck('created_by')->map(fn ($v) => (string) $v)->unique()->values()->all();

        $this->assertNotSame([], $owners, 'premise: defaultSetting() seeded rows for this store');
        $this->assertSame([(string) $otherMerchant->id], $owners,
            'Every seeded row must belong to the merchant whose store it is, not to whoever happened to be signed in.');
    }

    /**
     * An update preserves what is STORED, not what the saver's root happens to be.
     *
     * A row already carrying somebody else's id -- the legacy state this change does not repair, e.g. one re-stamped before the fix --
     * must come through a staff save untouched. Re-homing it to the current owner would be a second silent rewrite of the same column,
     * and it would make the "this does not repair existing rows" claim false.
     *
     * Added because a removal check that routed updates through the create path SURVIVED: every row in the other tests already
     * belonged to the owner, so preserving and re-homing were indistinguishable.
     */
    public function test_an_update_preserves_a_third_partys_ownership_rather_than_re_homing_it(): void
    {
        $stranger = $this->makePlatformAdmin();
        $this->seedRow('metakeyword', 'OLD', $stranger->id);

        $this->actingAs($this->storeStaff)
            ->post('/seo-setting', ['metakeyword' => 'STAFF-VALUE'] + self::seoFields())
            ->assertSessionMissing('error');

        $this->assertSame(
            [['value' => 'STAFF-VALUE', 'created_by' => (string) $stranger->id]],
            $this->rowsFor('metakeyword'),
            'The value changes; the stored ownership is preserved exactly, neither moved to the saver nor re-homed to the owner.'
        );
    }
}
