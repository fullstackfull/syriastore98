<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use App\Models\Utility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P1-SEC-11 criterion 1 -- `Utility::Setting()`'s FALLBACK BORROWS ANOTHER STORE'S ROWS.
 * =============================================================================================
 *
 * `Utility::Setting()` (app/Models/Utility.php:139-157) resolves the viewer's tenant and current
 * store, and when that store has no matching rows it runs
 *
 *     $data = Setting::where('created_by', 1)->get();
 *
 * with NO store predicate -- every row belonging to user id 1 across EVERY store, folded into one
 * array in row order, so the last row of each name wins. That is what printed store B's address on
 * a label for merchant A's own order (measured in ShippingLabelStoreSettingsTest), and the
 * shipping-label fix only overrode the five ADDRESS names in the host handler.
 *
 * WHY THE HELPER IS THE RIGHT PLACE AND THE LABEL FIX WAS NOT ENOUGH. The same settings array
 * reaches the VENDORED order documents -- AuctionProduct's shipping label, LotteryPrizes' order
 * views, and AutomaticOrderPrinting's ten templates all call `Utility::Setting()` and all render
 * `store_address` and its siblings. `packages/workdo/` must never be edited, so a per-handler
 * override cannot reach them; the host helper they share can.
 *
 * WHAT CHANGES, STATED PLAINLY because it is a contract change: a store with no settings rows of
 * its own used to inherit user 1's values and now gets the hardcoded defaults the method already
 * builds. That is deliberate. The old behaviour was not a sane fallback -- it is nondeterministic
 * (the answer depends on row order across tenants) and it is cross-tenant. Every store created
 * through StoreController is seeded by `defaultSetting()` (StoreController:148, :305), so the
 * branch is reached only by a store created outside that path, one whose rows were deleted, or one
 * whose rows carry a different `created_by` than the viewer resolves -- which is exactly the shape
 * the label defect was measured in.
 *
 * `Setting` is Cachable, so every fixture here flushes before the read.
 */
class SettingFallbackStoreScopeTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function setting($store, string $name, string $value, $createdBy): void
    {
        DB::table('settings')->insert([
            'name' => $name, 'value' => $value, 'store_id' => $store->id,
            'created_by' => $createdBy, 'created_at' => now(), 'updated_at' => now(),
        ]);

        (new Setting())->flushCache();
    }

    /**
     * THE DEFECT. Store A's merchant, whose own store has no rows, must not be handed store B's.
     */
    public function test_a_store_with_no_rows_of_its_own_does_not_inherit_another_stores_values(): void
    {
        // user id 1 owns store B's rows: the shape the fallback reaches for.
        [, $storeB] = $this->makeTenant();
        $this->setting($storeB, 'store_address', 'STORE-B-SECRET-ADDRESS', 1);

        $this->assertSame('STORE-B-SECRET-ADDRESS',
            DB::table('settings')->where('store_id', $storeB->id)->where('name', 'store_address')->value('value'),
            'Fixture premise: store B carries the row, owned by user id 1.');

        // Store A has NO settings rows at all.
        [$merchantA, $storeA] = $this->makeTenant();
        $merchantA->current_store = $storeA->id;
        $merchantA->save();

        $this->assertSame(0, (int) DB::table('settings')->where('store_id', $storeA->id)->count(),
            'Fixture premise: store A has no settings rows, which is what reaches the fallback.');

        $this->actingAs($merchantA->fresh());
        (new Setting())->flushCache();

        $resolved = Utility::Setting();

        $this->assertNotSame('STORE-B-SECRET-ADDRESS', $resolved['store_address'] ?? null,
            "Utility::Setting() handed store A a value belonging to store B: the created_by = 1 "
            . 'fallback carries no store predicate.');
    }

    /** And the same for a money-adjacent name, so the point is not read as being about addresses. */
    public function test_the_fallback_does_not_carry_another_stores_mail_credentials(): void
    {
        [, $storeB] = $this->makeTenant();
        $this->setting($storeB, 'MAIL_PASSWORD', 'store-b-smtp-secret', 1);

        [$merchantA, $storeA] = $this->makeTenant();
        $merchantA->current_store = $storeA->id;
        $merchantA->save();

        $this->actingAs($merchantA->fresh());
        (new Setting())->flushCache();

        $resolved = Utility::Setting();

        $this->assertNotSame('store-b-smtp-secret', $resolved['MAIL_PASSWORD'] ?? null,
            "Store A's resolved settings carry store B's SMTP password.");
    }

    /**
     * AND THE PRIMARY READ'S OWN STORE PREDICATE, which a mutant showed was pinned by nothing.
     *
     * The conditions are `['created_by' => $user->id, 'store_id' => $storeId]`. The `created_by`
     * half looks sufficient until you notice that ONE MERCHANT MAY RUN SEVERAL STORES -- which this
     * platform supports and `StoreScope::admissibleStoreIds()` is built around -- so their id owns
     * rows in every one of them. Drop the store predicate and the array folds all their stores'
     * rows together in row order, last name wins: the merchant viewing store A sees store B's
     * address, currency and SMTP password. Not cross-TENANT, but the wrong store, silently, and on
     * the same documents this row is about.
     */
    public function test_a_merchant_with_two_stores_sees_only_the_current_ones_rows(): void
    {
        [$merchant, $storeA] = $this->makeTenant();
        $storeB = $this->makeStore($merchant);

        $this->setting($storeA, 'store_address', 'ADDRESS-OF-STORE-A', $merchant->id);
        $this->setting($storeB, 'store_address', 'ADDRESS-OF-STORE-B', $merchant->id);

        $merchant->current_store = $storeA->id;
        $merchant->save();

        $this->actingAs($merchant->fresh());
        (new Setting())->flushCache();

        $this->assertSame('ADDRESS-OF-STORE-A', Utility::Setting()['store_address'] ?? null,
            "The merchant's own OTHER store's row won: the primary read is not scoped to the "
            . 'current store, only to the owner.');

        // and the other direction, so the assertion is not satisfied by row order
        $merchant->current_store = $storeB->id;
        $merchant->save();

        $this->actingAs($merchant->fresh());
        (new Setting())->flushCache();

        $this->assertSame('ADDRESS-OF-STORE-B', Utility::Setting()['store_address'] ?? null,
            'Switching the current store did not change the resolved settings.');
    }

    /** THE POSITIVE CONTROL: a store's OWN rows still resolve, or the refusal above proves nothing. */
    public function test_a_stores_own_rows_still_resolve(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $merchant->current_store = $store->id;
        $merchant->save();
        $this->setting($store, 'store_address', 'MY-OWN-ADDRESS', $merchant->id);

        $this->actingAs($merchant->fresh());
        (new Setting())->flushCache();

        $resolved = Utility::Setting();

        $this->assertSame('MY-OWN-ADDRESS', $resolved['store_address'] ?? null,
            'A store can no longer read its own settings, which would be an outage rather than a fix.');
    }

    /** A name with no row anywhere falls to the method's own defaults, not to nothing. */
    public function test_an_unset_name_still_falls_to_the_declared_default(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $merchant->current_store = $store->id;
        $merchant->save();
        $this->setting($store, 'store_address', 'MY-OWN-ADDRESS', $merchant->id);

        $this->actingAs($merchant->fresh());
        (new Setting())->flushCache();

        $resolved = Utility::Setting();

        $this->assertSame('USD', $resolved['CURRENCY_NAME'] ?? null,
            'The declared defaults are no longer returned, so a caller reading an unset name gets a '
            . 'missing key instead of a default. (CURRENCY_NAME, not site_currency: the first draft '
            . 'of this test asserted a key the defaults array does not carry and failed for that '
            . 'reason rather than for the defect.)');
    }
}
