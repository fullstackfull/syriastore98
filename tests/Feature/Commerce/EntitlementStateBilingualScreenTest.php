<?php

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Support\Finance\EntitlementStateLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GAP-149 / `P05-01` -- THE SIX ENTITLEMENT STATES AS AN ARABIC MERCHANT READS THEM.
 * =================================================================================================
 *
 * WHAT WAS ALREADY COVERED AND WHAT WAS NOT. `FinanceAdjustmentAccountabilityTest` drives a
 * `cancelled`, `refunded`, `pending` and `partially_paid` row through `/finance` and proves the
 * badge and the `<option>` carry the row's real state -- **in English only**. Every case there runs
 * under the suite's default locale, so the half of `P05-01` that CLAUDE.md states as a standing
 * requirement ("Bilingual Arabic (RTL) and English (LTR). Any UI change must be checked in both
 * directions") had no committed evidence on this screen at all.
 *
 * THAT IS NOT A THEORETICAL GAP. The states reach this screen through `__()`, and a key that is
 * present in `en.json` and absent from `ar.json` falls back to English **silently** -- no error, no
 * census able to see it, and an Arabic merchant reading an English word in the one column that says
 * whether the money is theirs. Measured 2026-11-25: all six phrases do resolve in Arabic, so this
 * file is a RATCHET over a working state rather than a reproduction. The mutations below are what
 * make it evidence: drop a state from the view, drop one from the validator, or break one label,
 * and a case here fails.
 *
 * IT ASSERTS THROUGH THE TRANSLATOR, NOT THROUGH `ar.json`.
 * `EntitlementStateLabelTest::test_every_phrase_resolves_in_arabic_through_the_translator` used to
 * read the file with `file_get_contents` and assert `array_key_exists`. That asks a weaker question
 * than the product does: a key can be in the file and still not be what `__()` answers with -- it
 * can be shadowed by `resources/lang/ar/*.php`, duplicated inside the JSON (the last wins, and
 * `json_decode` hides the first), or sitting in a file the loader does not read for this locale.
 * Every expectation below is COMPUTED by calling `__()` under locale `ar`, which is the same
 * question the Blade asks.
 *
 * WHY THE EXPECTED STRINGS ARE NEVER PASTED. A pasted Arabic literal pins today's wording, so an
 * approved rewording turns this file red for no defect; worse, a pasted string that happens to be
 * the ENGLISH one makes the whole case vacuous the moment the translation goes missing, which is
 * the exact failure it exists to catch. Each case therefore computes the phrase and separately
 * asserts the premise that it is NOT the English one.
 *
 * SCOPED TO THE ROW'S OWN `<select>`, because `/finance` also carries a wallet-transaction status
 * filter whose options include `cancelled`. A whole-page search for an option value is satisfied by
 * that filter -- measured in the sibling file, and CLAUDE.md's "scope the search to the block too"
 * in its exact form.
 */
class EntitlementStateBilingualScreenTest extends TestCase
{
    use RefreshDatabase;

    private int $storeId = 1;

    /**
     * The merchant the `<select>` actually renders for, in a chosen language.
     *
     * NOT the platform operator, and the reason is a measured property of this view rather than a
     * preference: the cell gates the control on a bare `isAbleTo('Edit Finance Payment Status')`,
     * which in this codebase REFUSES a `super admin` (Laratrust, `permissions_as_gates => false`,
     * no operator bypass). An operator is served the read-only badge, so an operator-driven
     * assertion about `<option>`s would measure the badge and pass for the wrong reason.
     *
     * `language` is what `SetLocate` reads (session `LANGUAGE`, else `auth()->user()->language`),
     * and `User` is `Cachable`, so the row is flushed and re-read -- otherwise the request is
     * served the pre-write language and every Arabic assertion below is really an English one.
     */
    private function merchantReadingIn(string $language): User
    {
        $merchant = User::find(DB::table('stores')->where('id', $this->storeId)->value('created_by'));

        $role = \App\Models\Role::firstOrCreate([
            'name' => 'gap149-finance-editor',
            'display_name' => 'GAP-149 finance editor',
            'created_by' => $merchant->id,
            'store_id' => $this->storeId,
        ]);

        /*
         * BOTH NAMES. `finance.index` carries `finance.permission:View Finance Dashboard`, so a
         * principal holding only the edit permission is served a 403 error document in which every
         * assertion about options passes or fails for the wrong reason.
         */
        foreach (['View Finance Dashboard', 'Edit Finance Payment Status'] as $name) {
            $role->givePermission(\App\Models\Permission::firstOrCreate(
                ['name' => $name],
                ['display_name' => $name],
            ));
        }

        $merchant->addRole($role);
        $merchant->current_store = $this->storeId;
        $merchant->language = $language;
        $merchant->save();
        $merchant->flushCache();

        $merchant = User::find($merchant->id);

        foreach (['View Finance Dashboard', 'Edit Finance Payment Status'] as $name) {
            $this->assertTrue($merchant->isAbleTo($name),
                "PREMISE: this principal does not hold '{$name}'. Without the first the page is a "
                . '403; without the second the <select> is never rendered and these assertions '
                . 'would measure the read-only badge.');
        }

        return $merchant;
    }

    private function seedFixture(): array
    {
        $owner = User::factory()->create(['email' => 'gap149-owner@example.test', 'type' => 'admin']);

        DB::table('stores')->insert([
            'id' => $this->storeId,
            'name' => 'GAP-149 store',
            'slug' => 'gap149-store',
            'email' => 'gap149@example.test',
            'theme_id' => 'stylique',
            'created_by' => $owner->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $walletId = DB::table('store_wallets')->insertGetId([
            'store_id' => $this->storeId, 'currency_code' => 'USD',
            'available_balance' => 0, 'pending_balance' => 0, 'withdrawal_pending_balance' => 0,
            'total_income' => 0, 'total_withdrawn' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderId = DB::table('orders')->insertGetId([
            'product_order_id' => 'ORD-GAP149', 'product_json' => json_encode([]),
            'product_price' => 0.0, 'delivery_price' => 0.0, 'tax_price' => 0.0,
            'coupon_price' => 0.0, 'final_price' => 250.0,
            'payment_type' => 'cod', 'payment_status' => 'Paid',
            'store_id' => $this->storeId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        /*
         * `paid_amount` IS 250 AND `paid_at` IS SET ON PURPOSE. `OrderEntitlementReversal` leaves
         * both exactly as they were -- that is the whole point of F124: the row still says how much
         * the customer paid and when, while saying the merchant is no longer entitled to it. A
         * fixture that zeroed them would describe a row the engine does not write.
         */
        $financialId = DB::table('store_order_financials')->insertGetId([
            'store_id' => $this->storeId, 'wallet_id' => $walletId, 'order_id' => $orderId,
            'currency_code' => 'USD', 'currency_symbol' => '$',
            'product_amount' => 250, 'discount_amount' => 0, 'tax_amount' => 0, 'shipping_amount' => 0,
            'customer_total' => 250, 'product_cost' => 0, 'gross_profit' => 0,
            'payment_status' => 'unpaid', 'paid_amount' => 250, 'remaining_amount' => 0,
            'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('walletId', 'orderId', 'financialId');
    }

    /** The one per-row control, asserted to be the only one so the case is about a known row. */
    private function theRowsPaymentStatusSelect(string $html): string
    {
        $this->assertSame(1, preg_match_all('/<select name="payment_status".*?<\/select>/s', $html, $m),
            'PREMISE: the page does not carry exactly one per-row payment_status select. Either the '
            . 'control is gone (this principal is reading the badge, or the page is a 403) or the '
            . 'fixture seeded more than one row.');

        return $m[0][0];
    }

    /**
     * The four the ENGINE writes and a merchant may not type, with `paid`/`unpaid` left out because
     * those two are the selectable pair and render through the other branch of the same `@if`.
     *
     * Deliberately the same set as the sibling file's `engineWrittenEntitlementStates`, and
     * deliberately NOT imported from it: a provider shared between two files is one definition that
     * can be narrowed once and silently shrink both ratchets.
     */
    public static function engineWrittenStates(): array
    {
        return [
            'cancelled' => ['cancelled'],
            'refunded' => ['refunded'],
            'pending' => ['pending'],
            'partially_paid' => ['partially_paid'],
        ];
    }

    // ----------------------------------------------------------------- the screen, in Arabic

    /**
     * @dataProvider engineWrittenStates
     */
    public function test_an_engine_written_row_renders_its_state_in_arabic_on_the_finance_screen(string $state): void
    {
        $f = $this->seedFixture();
        DB::table('store_order_financials')->where('id', $f['financialId'])
            ->update(['payment_status' => $state]);

        $merchant = $this->merchantReadingIn('ar');

        $response = $this->actingAs($merchant)->get(route('finance.index'));
        $response->assertOk();

        $this->assertSame('ar', app()->getLocale(),
            'PREMISE: the request did not resolve the Arabic locale, so every assertion below is '
            . 'really an English one and this case cannot fail for the reason it exists.');

        $select = $this->theRowsPaymentStatusSelect((string) $response->getContent());

        // Computed through the translator under the locale the request actually resolved.
        $arabic = EntitlementStateLabel::label($state);
        $english = $this->englishLabelFor($state);

        $this->assertNotSame($english, $arabic,
            "PREMISE: '{$english}' -- the finance screen's phrase for `{$state}` -- has no Arabic "
            . 'through the translator, so it falls back to English on a money column. Adding the '
            . 'key to resources/lang/ar.json is the fix; this assertion is what makes the fallback '
            . 'visible instead of silent.');

        $this->assertStringContainsString(
            '<option value="' . $state . '" selected disabled>',
            $select,
            "a `{$state}` row renders no selected+disabled option, so the browser falls back to the "
            . 'first option and shows the merchant a state the row is not in'
        );

        $this->assertStringContainsString($arabic, $select,
            "the `{$state}` option carries no Arabic label");

        /*
         * AND THE ENGLISH MUST BE ABSENT FROM THIS SELECT. `assertStringContainsString($arabic)`
         * alone cannot fail when a translation goes missing in the OTHER direction -- a cell
         * printing both would satisfy it. Scoped to the select, because the page's own chrome is
         * full of English identifiers that are not this label.
         */
        $this->assertStringNotContainsString($english, $select,
            "the `{$state}` option still carries the English phrase '{$english}' for an Arabic "
            . 'merchant');
    }

    public function test_both_selectable_states_are_offered_in_arabic_and_neither_is_preselected_on_a_reversed_row(): void
    {
        $f = $this->seedFixture();
        DB::table('store_order_financials')->where('id', $f['financialId'])
            ->update(['payment_status' => 'refunded']);

        $select = $this->theRowsPaymentStatusSelect((string) $this->actingAs($this->merchantReadingIn('ar'))
            ->get(route('finance.index'))->getContent());

        $this->assertSame('ar', app()->getLocale(), 'PREMISE: the Arabic locale did not resolve.');

        /*
         * THE OFFERED SET IS THE HELPER'S, so this case cannot drift from the validator: the
         * controller validates against the same `selectable()` call. Removing a state from that
         * list fails here AND at the door.
         */
        $this->assertSame(['paid', 'unpaid'], EntitlementStateLabel::selectable(),
            'PREMISE: the selectable set is not the pair this case was written for. If an owner '
            . 'decision (D-N43) widened it, this case needs rewriting rather than relaxing.');

        foreach (EntitlementStateLabel::selectable() as $selectable) {
            $arabic = EntitlementStateLabel::label($selectable);

            $this->assertNotSame($this->englishLabelFor($selectable), $arabic,
                "PREMISE: the phrase for `{$selectable}` has no Arabic through the translator.");

            $this->assertStringContainsString('<option value="' . $selectable . '" >', $select,
                "the {$selectable} option is gone from the row's select, or carries `selected` on a "
                . 'row that is not in that state -- which would make the browser submit it');

            $this->assertStringContainsString($arabic, $select,
                "the {$selectable} option carries no Arabic label");
        }
    }

    /**
     * EVERY ONE OF THE SIX, THROUGH THE TRANSLATOR, UNDER A REAL REQUEST'S LOCALE.
     *
     * The unit test asserts the same property against the container; this asserts it after
     * `SetLocate` has run, which is the only thing that proves the locale a merchant's request
     * resolves is the locale the labels are read under. Those are different questions: the suite's
     * default locale is `en`, so a label helper can pass a unit test and still render English on
     * the page if the middleware never resolves `ar`.
     */
    public function test_all_six_phrases_resolve_in_arabic_under_a_real_request(): void
    {
        $this->seedFixture();

        $this->actingAs($this->merchantReadingIn('ar'))->get(route('finance.index'))->assertOk();

        $this->assertSame('ar', app()->getLocale(), 'PREMISE: the Arabic locale did not resolve.');

        $this->assertCount(6, EntitlementStateLabel::states(),
            'PREMISE: the column no longer has six states, so this case is measuring a different '
            . 'vocabulary than the one it was written for.');

        foreach (EntitlementStateLabel::states() as $state) {
            $arabic = EntitlementStateLabel::label($state);
            $english = $this->englishLabelFor($state);

            $this->assertNotSame($english, $arabic,
                "'{$english}' -- the finance screen's phrase for `{$state}` -- is not translated "
                . 'into Arabic through the translator. It is not enough for the key to be in '
                . 'resources/lang/ar.json: it must be what __() answers with, because a key can be '
                . 'shadowed by resources/lang/ar/*.php or duplicated inside the JSON.');

            $this->assertNotSame('', trim($arabic), "the Arabic for `{$state}` is blank");
        }
    }

    // ----------------------------------------------------------------- the refusal, in Arabic

    /**
     * GAP-149 -- THE REFUSAL MUST NOT NAME THE FIELD IN ENGLISH.
     *
     * This is the measured defect this file's production change fixes, and it is reachable by
     * ORDINARY USE rather than by a crafted request. A `<option ... selected disabled>` submits NO
     * value, so on any of the four engine-written rows a merchant who presses Update without first
     * choosing `paid` or `unpaid` sends no `payment_status`, the validator refuses it, and the cell
     * prints the message. Widening the control to `pending` is what made that routine.
     *
     * MEASURED BEFORE THE FIX, by driving this route: the Arabic merchant read
     *
     *     القيمة المحددة لـ payment status غير صحيحة.
     *
     * -- an Arabic sentence with the raw snake-cased COLUMN name in the middle of it, because
     * Laravel derives `:attribute` from the field name and `resources/lang/ar/validation.php`'s
     * `attributes` array is empty (measured: `'attributes' => []`). The fix declares the attribute
     * name at the only place that knows what the field means to a merchant -- the validator in
     * `StoreFinanceController::updateFinancialPaymentStatus()` -- using the `Payment Status` key
     * that is already translated in both languages, so it needs no new string.
     */
    public function test_the_refusal_names_the_field_in_the_merchants_own_language(): void
    {
        $f = $this->seedFixture();
        DB::table('store_order_financials')->where('id', $f['financialId'])
            ->update(['payment_status' => 'cancelled']);

        $merchant = $this->merchantReadingIn('ar');

        $html = (string) $this->actingAs($merchant)
            ->from(route('finance.index'))
            ->followingRedirects()
            ->post(route('finance.order-financial.status', $f['financialId']), [
                // exactly what a browser sends when the only selected option is disabled
                'reason' => 'pressed Update without choosing a state',
            ])
            ->getContent();

        $this->assertSame('ar', app()->getLocale(),
            'PREMISE: the Arabic locale did not resolve, so the message below is the English one '
            . 'and this case cannot see the defect.');

        $fieldName = __('Payment Status');

        $this->assertNotSame('Payment Status', $fieldName,
            'PREMISE: `Payment Status` has no Arabic, so the fix would substitute one English '
            . 'string for another and this case would pass while the merchant still read English.');

        $expected = __('validation.required', ['attribute' => $fieldName]);

        $this->assertStringNotContainsString('validation.required', $expected,
            'PREMISE: the Arabic validation language file did not resolve, so the expected string '
            . 'is a translation key and this assertion would pass for the wrong reason.');

        $this->assertStringContainsString($expected, $html,
            'The refusal does not name the field the way a merchant knows it. Laravel derives '
            . ":attribute from the field NAME, and ar/validation.php's `attributes` array is empty, "
            . 'so an Arabic merchant reads the raw column name `payment status` inside an Arabic '
            . "sentence. The validator must declare the attribute as __('Payment Status').");

        /*
         * AND THE RAW COLUMN NAME MUST BE GONE FROM THE RENDERED REFUSAL. The positive assertion
         * above can hold while the old message is ALSO on the page -- two error surfaces, or a
         * second validator. This is the half that fails before the fix.
         *
         * Scoped to the refusal block, not the page: `payment_status` is the <select>'s own `name`
         * attribute and appears in the markup whatever the message says, so a whole-page search
         * would be unfalsifiable.
         */
        foreach ($this->renderedErrorMessages($html) as $message) {
            $this->assertStringNotContainsString('payment status', $message,
                'A rendered refusal still carries the raw English column name: ' . $message);
            $this->assertStringNotContainsString('payment_status', $message,
                'A rendered refusal still carries the raw column name: ' . $message);
        }
    }

    /**
     * The text of every error surface the cell prints.
     *
     * The view renders each refusal as `<div class="text-danger small mt-1" role="alert">`, and the
     * premise asserts at least one was found -- otherwise the loop above is empty and the case
     * passes without reading anything, which is how an absence assertion goes vacuous.
     */
    private function renderedErrorMessages(string $html): array
    {
        preg_match_all('#<div class="text-danger small mt-1" role="alert">(.*?)</div>#s', $html, $m);

        $messages = array_map(static fn ($t) => trim(html_entity_decode($t, ENT_QUOTES)), $m[1]);

        $this->assertNotEmpty($messages,
            'PREMISE: the page rendered no error surface at all, so the absence assertions below '
            . 'would hold whatever the message said. Either the refusal is invisible again or the '
            . "view's error markup changed.");

        return $messages;
    }

    /**
     * The English phrase for a state, read under locale `en` and restored afterwards.
     *
     * NOT a pasted literal and NOT the state name: it is the same `label()` call the Blade makes,
     * asked under the other locale, so "the Arabic differs from the English" is a question about
     * the translator in both directions rather than about this file's idea of English.
     */
    private function englishLabelFor(string $state): string
    {
        $was = app()->getLocale();
        app()->setLocale('en');

        try {
            return EntitlementStateLabel::label($state);
        } finally {
            app()->setLocale($was);
        }
    }
}
