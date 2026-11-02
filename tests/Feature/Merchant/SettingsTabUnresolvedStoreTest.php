<?php

namespace Tests\Feature\Merchant;

use App\Models\CurrencyRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * A SETTINGS TAB MUST RENDER FOR AN OPERATOR WHOSE STORE DOES NOT RESOLVE.
 *
 * ================================================================================================
 *  THE DEFECT THIS PINS WAS MADE LIVE BY GATE F, AND FOUND BY THE KNOWN-OUTCOME GATE ON 2026-11-02.
 * ================================================================================================
 *
 * `SettingController::settingForm()` seeds fourteen default `currency_rates` rows whenever that
 * table is empty, for `$storeId = getCurrentStore()`. Two facts make that dangerous together:
 *
 *   1. `getCurrentStore()` returns the **hardcoded literal 1** for a `super admin` whose
 *      `current_store` is empty (`app/Helper/helper.php:773`).
 *   2. `currency_rates.store_id` carries a FOREIGN KEY to `stores(id)` ON DELETE CASCADE --
 *      production has it, and `2026_11_02_000700_create_currency_rates_table.php` reproduces it.
 *
 * So when store 1 does not exist, all fourteen inserts violate that key, the exception reaches
 * `settingForm`'s own `catch (\Exception $e)`, and the method answers
 * `{"is_success": false, "msg": "Something went wrong!", "data": {"content": null}}` -- for **every
 * tab**, because the seed runs before the tab is dispatched. An operator who had never used the
 * store switcher could not open the email, storage, PWA or ChatGPT settings either.
 *
 * WHY IT WAS INVISIBLE UNTIL NOW. `currency_rates` was declared by no migration, so this block
 * never reached a real table in any environment built from this repository. The table arriving is
 * what turned a latent defect into a live one -- exactly the class of change
 * `docs/GATE-F-VERIFIED-SCHEMA.md` §4 warns about, in the direction nobody watches: code whose
 * behaviour depended on a table being ABSENT.
 *
 * AND THE FAILURE WAS SILENT. `settingForm`'s catch discards `$e` without logging it, so the only
 * symptom anywhere was a generic message in a JSON envelope. That is why this is a test and not a
 * note: nothing else would say so.
 *
 * THE TEST BITES. Removing the `$storeExists` guard from `settingForm` turns both cases below red.
 */
class SettingsTabUnresolvedStoreTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * An operator with no `current_store`, in a database with no store 1, still gets a tab.
     *
     * The tab chosen is `email_setting` deliberately: it has nothing to do with currencies, which
     * is the point -- the seed runs before the dispatch, so a currency failure took every unrelated
     * tab down with it.
     */
    public function test_an_operator_with_no_resolvable_store_still_gets_a_settings_tab(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->assertSame(
            0,
            DB::table('stores')->count(),
            'premise: this test is about the case where the store getCurrentStore() names does not '
            . 'exist, so there must be no stores at all'
        );

        $response = $this->actingAs($operator->fresh())->post('/setting-form', ['tab_type' => 'email_setting']);

        $response->assertOk();

        $body = json_decode((string) $response->getContent(), true);

        $this->assertTrue(
            (bool) ($body['is_success'] ?? false),
            'The settings tab did not render for an operator whose store does not resolve. '
            . 'getCurrentStore() answers the hardcoded 1 for a super admin with no current_store, '
            . 'and the currency_rates seed then violates the foreign key to stores(id) -- taking '
            . 'every tab down with it through settingForm\'s own catch. Response: '
            . substr((string) $response->getContent(), 0, 300)
        );

        $this->assertNotNull($body['data']['content'] ?? null, 'the tab rendered no content');
    }

    /**
     * And nothing was written, which is the other half: the guard must DECLINE to seed rather than
     * seed somewhere else.
     *
     * A "fix" that redirected the seed to some other store id would also make the test above pass,
     * and would be worse than the bug -- it would attach one tenant's currency rates to another.
     */
    public function test_no_currency_rate_is_seeded_against_a_store_that_does_not_exist(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator->fresh())->post('/setting-form', ['tab_type' => 'email_setting']);

        $this->assertSame(
            0,
            CurrencyRate::withoutGlobalScopes()->count(),
            'A currency rate was seeded although the store it belongs to does not exist. Read '
            . 'through withoutGlobalScopes() deliberately -- a plain count would answer against '
            . 'whichever tenant happens to be resolved rather than against the table.'
        );
    }

    /**
     * THE POSITIVE DIRECTION, so the guard cannot be satisfied by never seeding at all.
     *
     * With a real store resolved, the seed must still populate the defaults -- otherwise the two
     * cases above would pass against a controller that had simply stopped working.
     */
    public function test_a_resolvable_store_still_gets_its_default_currency_rates_seeded(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->assertSame(0, CurrencyRate::withoutGlobalScopes()->count(), 'premise: no rates yet');

        $response = $this->actingAs($merchant->fresh())->post('/setting-form', ['tab_type' => 'email_setting']);

        $response->assertOk();

        $seeded = CurrencyRate::withoutGlobalScopes()->where('store_id', $store->id)->get();

        $this->assertGreaterThan(
            0,
            $seeded->count(),
            'No default currency rate was seeded for a store that DOES exist, so the guard has '
            . 'turned the seed off rather than making it safe.'
        );

        // USD must be among them: StoreCurrencyConversionService short-circuits on USD, so a
        // missing USD row is the one that would be hardest to notice.
        $this->assertTrue(
            $seeded->contains(fn ($rate) => $rate->currency_code === 'USD'),
            'the seeded set does not include USD'
        );

        // And every seeded row belongs to the caller's own store, not to the hardcoded 1.
        $this->assertSame(
            [(int) $store->id],
            $seeded->pluck('store_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'a seeded rate was attached to a store other than the caller\'s own'
        );
    }
}
