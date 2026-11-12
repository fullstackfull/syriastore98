<?php

namespace Tests\Feature\ApiContract;

use App\Models\CurrencyRate;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5-TAX-CURRENCY — `taxlist` now tells the app which currency the store prices in.
 * =============================================================================================
 *
 * WHY THIS FIELD EXISTS. The admin app's tax screen printed a hard-coded `$` in front of a
 * fixed-amount tax. It was not a default that happened to be wrong for Syrian stores -- it was
 * invented, because this endpoint returned the paginator and nothing else, and **nothing in that
 * app caches a store currency**: every `currencySymbol` in it is assigned from the response of
 * that screen's own endpoint. The `$` was removed as soon as the defect was found, which left the
 * amount with no unit at all; this field is what lets the right symbol take its place.
 *
 * IT IS ADDITIVE. Every key the paginator already sent is still sent, and `currency_symbol` sits
 * beside them under the same name `ProductList` already uses, so an existing consumer sees no
 * change and the app parses it the same way it parses the product list.
 *
 * WHAT IS ASSERTED, AND WHY EACH CASE IS HERE:
 *
 *   * the field is present and carries the store's ACTUAL symbol, from its active
 *     `currency_rates` row -- the thing the hard-coded `$` was standing in for;
 *   * a store configured for USD still gets `$`, so the fallback was not broken while fixing it;
 *   * an UNCONFIGURED store does NOT get `$`. That is the whole point: the old code's failure mode
 *     was to assume dollars, and this asserts the new code assumes nothing;
 *   * the pagination keys are untouched, because a contract that quietly dropped `current_page`
 *     would break the app's infinite scroll while this test watched the wrong thing;
 *   * tenant isolation: the symbol comes from the CALLER'S store, not from whichever
 *     `currency_rates` row happens to be first.
 */
class TaxListCurrencyContractTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * Drives the real endpoint as a real merchant and returns its `data` payload.
     *
     * @return array{0: array<string, mixed>, 1: object}
     */
    private function taxListFor(?string $currencyCode, ?string $symbol): array
    {
        [$merchant, $store] = $this->makeTenant();

        $this->grantPermission(User::find($merchant->id), 'Manage Tax');

        if ($currencyCode !== null) {
            \App\Models\Setting::create([
                'name' => 'defult_currancy',
                'value' => $currencyCode,
                'store_id' => $store->id,
                'created_by' => $merchant->id,
            ]);
        }

        if ($currencyCode !== null && $symbol !== null) {
            CurrencyRate::create([
                'store_id' => $store->id,
                'currency_code' => $currencyCode,
                // `currency_name` is NOT NULL and `rate_per_usd` is the column's real name --
                // measured from the Gate F migration, not assumed.
                'currency_name' => $currencyCode,
                'symbol' => $symbol,
                'rate_per_usd' => 1,
                'is_active' => 1,
            ]);
        }

        // Only the columns `taxes` really has in this schema: id, name, store_id, timestamps
        // (`2023_12_22_060417_create_taxes_table.php`). The rate columns the app reads live on the
        // production table and are not needed here -- this test is about the ENVELOPE, and all it
        // needs is one row so `data` is non-empty.
        $tax = new Tax();
        $tax->forceFill([
            'name' => 'VAT',
            'store_id' => $store->id,
        ]);
        $tax->save();

        $token = User::find($merchant->id)->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/taxlist?store_id=' . $store->id);

        $response->assertOk();

        $payload = $response->json();

        $this->assertSame(
            1,
            $payload['status'] ?? null,
            'taxlist did not answer with status 1, so nothing below was measured.'
        );

        return [$payload['data'] ?? [], $store];
    }

    public function test_the_store_symbol_is_sent_for_a_configured_store(): void
    {
        [$data] = $this->taxListFor('SYP', 'ل.س');

        $this->assertArrayHasKey(
            'currency_symbol',
            $data,
            'taxlist stopped sending `currency_symbol`. The app\'s tax screen has no other source '
            . 'for it and would show a bare number again.'
        );

        $this->assertSame(
            'ل.س',
            $data['currency_symbol'],
            'taxlist did not report the store\'s own symbol from its active currency_rates row.'
        );
    }

    public function test_a_usd_store_still_gets_the_dollar_sign(): void
    {
        // The fallback that WAS the defect when applied unconditionally is still correct when the
        // store really is configured for dollars.
        [$data] = $this->taxListFor('USD', null);

        $this->assertSame('$', $data['currency_symbol'] ?? null);
    }

    public function test_an_unconfigured_store_is_not_assumed_to_price_in_dollars(): void
    {
        // THE CASE THE OLD CODE GOT WRONG. With a currency configured but no active rate row, the
        // honest answer is the currency CODE -- a poor symbol, but not a false one.
        [$data] = $this->taxListFor('SYP', null);

        $this->assertSame(
            'SYP',
            $data['currency_symbol'] ?? null,
            'an unconfigured non-USD store is being told its currency is dollars, which is the '
            . 'exact failure the hard-coded `$` produced.'
        );
    }

    public function test_the_addition_did_not_disturb_the_pagination_contract(): void
    {
        [$data] = $this->taxListFor('SYP', 'ل.س');

        foreach (['current_page', 'data', 'last_page', 'per_page', 'total'] as $key) {
            $this->assertArrayHasKey(
                $key,
                $data,
                $key . ' is no longer sent by taxlist. The addition of currency_symbol was meant '
                . 'to be additive; dropping a pagination key breaks the app\'s list.'
            );
        }

        $this->assertNotEmpty($data['data'], 'the tax row itself is no longer being returned.');
    }

    public function test_the_symbol_comes_from_the_callers_own_store(): void
    {
        // TENANT ISOLATION. `currency_rates` is not in config/tenancy.php's scoped_models, so an
        // unscoped lookup would read whichever row came first -- and in a shared database that is
        // another merchant's. The store predicate is what prevents it, and this is what would
        // notice if it were dropped.
        [$otherMerchant, $otherStore] = $this->makeTenant();
        CurrencyRate::create([
            'store_id' => $otherStore->id,
            'currency_code' => 'EUR',
            'currency_name' => 'Euro',
            'symbol' => '€',
            'rate_per_usd' => 1,
            'is_active' => 1,
        ]);
        \App\Models\Setting::create([
            'name' => 'defult_currancy',
            'value' => 'EUR',
            'store_id' => $otherStore->id,
            'created_by' => $otherMerchant->id,
        ]);

        [$data] = $this->taxListFor('SYP', 'ل.س');

        $this->assertSame(
            'ل.س',
            $data['currency_symbol'] ?? null,
            'taxlist reported a currency symbol belonging to another tenant\'s store.'
        );
    }
}
