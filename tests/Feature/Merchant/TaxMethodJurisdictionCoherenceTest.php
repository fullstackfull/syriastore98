<?php

namespace Tests\Feature\Merchant;

use App\Models\Store;
use App\Models\TaxMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A TAX METHOD THAT NAMES A CITY WITHOUT A STATE IS CHARGED TO NOBODY. AUD-4.
 * =================================================================================================
 *
 * FOUND BY THE PR #140 DELETION AUDIT'S ADVERSARIAL PASS, as a consequence of P4-F070. That change
 * removed the "if nothing matched, charge every method" fallback from `Cart::getProductTaxAmount()`
 * -- correctly, because it charged a Damascus shopper Aleppo's tax -- but the fallback had also been
 * catching, by accident, every method whose jurisdiction columns describe NO shopper.
 *
 * `getProductTaxAmount()` takes a method through exactly four shapes:
 *
 *     country + state + city      country + state      country alone      none of them
 *
 * Two more combinations can be WRITTEN and match nobody:
 *
 *     a state with no country        a city with no state (with or without a country)
 *
 * `TaxMethodController::store()` and `::update()` assigned the three columns independently behind a
 * validator that checks only `name` and `tax_rate`, so nothing refused either.
 *
 * THE FIRST VERSION OF THIS COMMENT SAID THE FORM'S CASCADE MEANT A PERSON COULD NOT BUILD ONE BY
 * CLICKING, and that was wrong -- found by independent review of this change, then confirmed by
 * reading the script: choose a country, a state and a city, then CHANGE THE COUNTRY. The state
 * select is rebuilt but the city select kept its old choice, so the form posted a city with no
 * state. Both views now clear the city in the country handler
 * (`test_a_country_change_clears_the_city_as_well_as_the_state`); a crafted request, and a row an
 * earlier version of the form wrote, can still carry the shape, which is what the writers refuse.
 *
 * WHAT THIS CLOSES, AND WHAT IT DOES NOT. It refuses the incoherent write at both writers. It cannot
 * repair a row that already exists, and whether any does is a question about production data that
 * this repository cannot answer. The read-only query for the owner is in
 * `docs/security/P05-PR140-DELETION-AUDIT-DIGEST.md`.
 *
 * THE FORM'S `0` PLACEHOLDER IS UNSET. Both writers read `$request->country_id ?: null`, so `0`, an
 * empty string and an absent field all mean "no restriction"; the helper uses the same reads, and
 * `test_the_forms_zero_placeholder_is_an_unset_level_not_a_selection` pins that so an unrestricted
 * method -- the ordinary case -- is never refused.
 */
class TaxMethodJurisdictionCoherenceTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $merchant = $this->makeMerchant();
        $this->store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $this->store->id;
        $merchant->save();

        $this->merchant = User::find($merchant->id);

        foreach (['Create Tax Method', 'Edit Tax Method', 'Delete Tax Method'] as $permission) {
            $this->grantPermission($this->merchant, $permission);
        }

        $this->merchant = User::find($merchant->id);

        // `tax_methods` carries foreign keys to these three, so real rows have to exist.
        DB::table('countries')->insertOrIgnore(['id' => 201, 'name' => 'Syria', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('states')->insertOrIgnore(['id' => 21, 'name' => 'Damascus', 'country_id' => 201, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cities')->insertOrIgnore(['id' => 31, 'name' => 'Mezzeh', 'country_id' => 201, 'state_id' => 21, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function taxGroupId(): int
    {
        return (int) DB::table('taxes')->insertGetId([
            'name' => 'Tax group '.uniqid(),
            'store_id' => $this->store->id,
        ]);
    }

    private function rowsNamed(string $name): int
    {
        return DB::table('tax_methods')->where('name', $name)->count();
    }

    private function storeBody(string $name, array $jurisdiction): array
    {
        return array_merge([
            'name' => $name,
            'tax_rate' => '9.00',
            'tax_id' => $this->taxGroupId(),
            'priority' => 1,
        ], $jurisdiction);
    }

    private function existingMethod(string $name = 'Existing levy'): TaxMethod
    {
        $row = new TaxMethod();
        $row->name = $name;
        $row->tax_rate = '5.00';
        $row->tax_id = $this->taxGroupId();
        $row->priority = 1;
        $row->enable_shipping = 0;
        $row->store_id = $this->store->id;
        $row->country_id = 201;
        $row->save();
        (new TaxMethod)->flushCache();

        return $row;
    }

    /** @return array<string, array{0: array<string, int>}> the two shapes that match no shopper */
    public static function incoherentJurisdictions(): array
    {
        return [
            'a state with no country' => [['state_id' => 21]],
            'a city with no state' => [['country_id' => 201, 'city_id' => 31]],
            'a city alone' => [['city_id' => 31]],
            'a state and a city but no country' => [['state_id' => 21, 'city_id' => 31]],
        ];
    }

    /** @return array<string, array{0: array<string, int>}> the four shapes the cart can match */
    public static function coherentJurisdictions(): array
    {
        return [
            'none of them' => [[]],
            'country alone' => [['country_id' => 201]],
            'country and state' => [['country_id' => 201, 'state_id' => 21]],
            'country, state and city' => [['country_id' => 201, 'state_id' => 21, 'city_id' => 31]],
        ];
    }

    #[DataProvider('incoherentJurisdictions')]
    public function test_store_refuses_a_jurisdiction_that_matches_no_shopper(array $jurisdiction): void
    {
        $response = $this->actingAs($this->merchant)
            ->post(route('taxes-method.store'), $this->storeBody('Orphan levy', $jurisdiction));

        $response->assertSessionHas('error', __('Choose a country before a state, and a state before a city.'));
        $this->assertSame(0, $this->rowsNamed('Orphan levy'), 'the incoherent row was written anyway');
    }

    #[DataProvider('coherentJurisdictions')]
    public function test_store_still_accepts_every_shape_the_cart_can_match(array $jurisdiction): void
    {
        $response = $this->actingAs($this->merchant)
            ->post(route('taxes-method.store'), $this->storeBody('Honest levy', $jurisdiction));

        $response->assertSessionMissing('error');
        $this->assertSame(1, $this->rowsNamed('Honest levy'),
            'a coherent jurisdiction was refused, so an ordinary tax method can no longer be created');
    }

    public function test_the_forms_zero_placeholder_is_an_unset_level_not_a_selection(): void
    {
        // The create form submits `0` for a level nobody chose. `?: null` turns it into null in both
        // writers, so the helper must read it the same way or the ordinary case is refused.
        $response = $this->actingAs($this->merchant)->post(
            route('taxes-method.store'),
            $this->storeBody('Placeholder levy', ['country_id' => 0, 'state_id' => 0, 'city_id' => 0])
        );

        $response->assertSessionMissing('error');
        $this->assertSame(1, $this->rowsNamed('Placeholder levy'));

        $partial = $this->actingAs($this->merchant)->post(
            route('taxes-method.store'),
            $this->storeBody('Country with placeholders', ['country_id' => 201, 'state_id' => 0, 'city_id' => 0])
        );

        $partial->assertSessionMissing('error');
        $this->assertSame(1, $this->rowsNamed('Country with placeholders'));

        /*
         * THE CASES THAT DISCRIMINATE. With every level `0`, "zero is a value" and "zero is unset"
         * AGREE -- nothing is narrower than anything else -- so the two requests above cannot tell
         * the readings apart, and a mutant that stopped using `?: null` survived them. They
         * disagree when a level is `0` and the level above it is ABSENT: read as a selection, `0`
         * would be "a state with no country" and an ordinary request would be refused.
         */
        foreach ([
            'a zero state with no country at all' => ['state_id' => 0],
            'a zero city under a zero country' => ['country_id' => 0, 'city_id' => 0],
        ] as $label => $jurisdiction) {
            $name = 'Placeholder '.$label;
            $this->actingAs($this->merchant)
                ->post(route('taxes-method.store'), $this->storeBody($name, $jurisdiction))
                ->assertSessionMissing('error');

            $this->assertSame(1, $this->rowsNamed($name), $label.' was refused, so `0` is being read as a selection');
        }
    }

    #[DataProvider('incoherentJurisdictions')]
    public function test_update_refuses_a_jurisdiction_that_matches_no_shopper(array $jurisdiction): void
    {
        $row = $this->existingMethod();

        $response = $this->actingAs($this->merchant)->put(
            route('taxes-method.update', $row->id),
            array_merge(['name' => 'Renamed levy', 'tax_rate' => '7.00', 'priority' => 1], $jurisdiction)
        );

        $response->assertSessionHas('error', __('Choose a country before a state, and a state before a city.'));

        $stored = DB::table('tax_methods')->where('id', $row->id)->first();
        $this->assertSame('Existing levy', $stored->name, 'the refused update still changed the row');
        $this->assertSame('5.00', number_format((float) $stored->tax_rate, 2, '.', ''));
        $this->assertNull($stored->state_id);
    }

    #[DataProvider('coherentJurisdictions')]
    public function test_update_still_accepts_every_shape_the_cart_can_match(array $jurisdiction): void
    {
        $row = $this->existingMethod();

        $response = $this->actingAs($this->merchant)->put(
            route('taxes-method.update', $row->id),
            array_merge(['name' => 'Renamed levy', 'tax_rate' => '7.00', 'priority' => 1], $jurisdiction)
        );

        $response->assertSessionMissing('error');
        $this->assertSame('Renamed levy', DB::table('tax_methods')->where('id', $row->id)->value('name'));
    }

    public function test_the_refusal_is_translated_in_both_languages(): void
    {
        $key = 'Choose a country before a state, and a state before a city.';

        foreach (['en', 'ar'] as $locale) {
            $strings = json_decode((string) file_get_contents(base_path('resources/lang/'.$locale.'.json')), true);

            $this->assertIsArray($strings, $locale.'.json is not valid JSON');
            $this->assertArrayHasKey($key, $strings, $locale.'.json has no entry for the refusal');
        }

        $ar = json_decode((string) file_get_contents(base_path('resources/lang/ar.json')), true);

        $this->assertNotSame($key, $ar[$key], 'the refusal falls back to English on an Arabic page');
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $ar[$key]);
    }

    /** @return array<string, array{0: string}> the two views that carry the cascade script */
    public static function cascadeViews(): array
    {
        return [
            'create form' => ['resources/views/taxes-method/create.blade.php'],
            'edit form' => ['resources/views/taxes-method/edit.blade.php'],
        ];
    }

    /**
     * THE FORM COULD POST A CITY WITH NO STATE, BY CLICKS ALONE.
     *
     * A source-level census, because the script is not exercised by any PHP test and the defect is
     * invisible server-side until the writers refuse the request. It reads the COUNTRY handler's own
     * body -- not "somewhere in the file" -- because the state handler already clears the city, and a
     * check that matched that one would pass with the fix deleted.
     */
    #[DataProvider('cascadeViews')]
    public function test_a_country_change_clears_the_city_as_well_as_the_state(string $view): void
    {
        $source = (string) file_get_contents(base_path($view));

        $start = strpos($source, "\$(document).on('change', '.country_change'");
        $end = strpos($source, "\$(document).on('change', '.state_chage'");

        $this->assertIsInt($start, $view.' no longer has a country change handler');
        $this->assertIsInt($end, $view.' no longer has a state change handler');
        $this->assertGreaterThan($start, $end, 'the handlers changed order, so the slice below would be empty');

        $handler = substr($source, $start, $end - $start);

        $removesTheWidget = strpos($handler, ".nice-select.city_chage').remove()");
        $resetsTheOptions = preg_match('/\.city_chage\x27\)\.html\(\x27<option value="0">/', $handler, $m, PREG_OFFSET_CAPTURE);
        $rebuildsTheWidgets = strpos($handler, "\$('select').niceSelect()");

        $this->assertNotFalse($removesTheWidget,
            $view.': the country handler no longer removes the stale city widget, so the old city stays selected');
        $this->assertSame(1, $resetsTheOptions,
            $view.': the country handler no longer resets the city options to the placeholder');
        $this->assertNotFalse($rebuildsTheWidgets, $view.': the handler no longer rebuilds the selects');

        // The reset has to land BEFORE the widgets are rebuilt, or niceSelect wraps the stale select.
        $this->assertLessThan($rebuildsTheWidgets, $removesTheWidget);
        $this->assertLessThan($rebuildsTheWidgets, $m[0][1]);
    }

    /**
     * The two views carry a copy of one script each. A fix made in one and not the other is how this
     * stayed broken in whichever form the person did not happen to use, so they are pinned equal.
     */
    public function test_the_two_forms_carry_the_same_cascade_script(): void
    {
        $scripts = [];

        foreach (self::cascadeViews() as $label => [$view]) {
            $this->assertSame(1, preg_match('/<script>.*?<\/script>/s', (string) file_get_contents(base_path($view)), $m),
                $view.' has no inline script block');
            $scripts[$label] = $m[0];
        }

        $this->assertSame($scripts['create form'], $scripts['edit form'],
            'the create and edit forms have drifted apart; fix the cascade script in both');
    }
}
