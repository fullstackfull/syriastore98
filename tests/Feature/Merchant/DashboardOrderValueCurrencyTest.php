<?php

namespace Tests\Feature\Merchant;

use App\Http\Controllers\HomeController;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P5-R7-C — THE DASHBOARD'S MOST PROMINENT MONEY TILE ADDS CURRENCIES TOGETHER.
 * =================================================================================================
 *
 * `HomeController.php:364-366` is one ungrouped aggregate:
 *
 *     $total_revenues = (clone $orderQuery)
 *         ->where('delivered_status', '!=', self::ORDER_STATUS_CANCEL)
 *         ->sum('final_price');
 *
 * with no reference to `orders.currency_code`, and `overview.blade.php:374` formats it with
 * `currency_format_with_sym($total_revenues, getCurrentStore())`, which reads the STORE's own
 * settings and stamps `defult_currancy_symbol` over the result. A store that has taken 100 EUR and
 * 50 USD is shown their sum behind the euro symbol.
 *
 * AND THE HINT SAYS SO EXPLICITLY. `overview.blade.php:376` asserts the figure is "in this store's
 * currency". For a mixed store that sentence is false, which makes this worse than the category
 * and brand cards ANA-TOP fixed: those were silent, this one states the thing that is not true.
 *
 * WHAT THIS SLICE DOES *NOT* DO, because an adversarial reading refuted it: it does NOT add a
 * collected-payments counterpart here. That was proposed and rejected on audience, period and
 * cost -- the dashboard tile is all-time and its reader is not reconciling money. The defect to
 * fix is the currency one.
 *
 * TWO THINGS THE SAME READING SAID MUST NOT HAPPEN, both pinned below:
 *
 *  1. The hint must keep its F257 clauses. It ends "...excluding orders that were cancelled.
 *     Returned orders are still included." The returns clause is OWNER-DECISION text
 *     (`HomeController.php:343-350`) and `grep -rn "Returned orders are still included" tests/`
 *     returned NOTHING before this file, so replacing the literal would have dropped it with the
 *     Feature gate green.
 *  2. If the definition half is ever single-sourced it must be `grossOrderValue()`, NOT
 *     `orderValue()`. This tile is `SUM(final_price)` -- arithmetically `$TotalgrossSale`, the
 *     shipping-and-tax-inclusive figure -- and `_sales_stats.blade.php:14-22` shows the codebase
 *     attaching `orderValue()` to the NET figure instead. Attaching the item-level sentence here
 *     would mislabel the tile while appearing to reduce duplication.
 */
class DashboardOrderValueCurrencyTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * THE PREFIXES, NOT THE WHOLE SENTENCES. `__($key, ['amounts' => ''])` renders ": ." --
     * a string no page contains -- so an assertion built that way is satisfied by the
     * substitution rather than by the behaviour. Measured: two not-contains cases below passed
     * against the UNFIXED view. These stop before the placeholder and carry no apostrophe, so
     * they survive both Blade's escaping and any change to how the amounts are listed.
     */
    private const ANOTHER_CURRENCY_SENTENCE = 'Also ordered in another currency';

    private const NOT_COMBINED_SENTENCE = 'are in more than one currency and are not combined';

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        $this->grantPermission(User::find($this->merchant->id), 'Manage Order');

        $this->merchant = User::find($this->merchant->id);

        DB::table('settings')->insert([
            'store_id' => $this->store->id, 'name' => 'defult_currancy', 'value' => 'EUR',
            'created_by' => $this->store->created_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function dashboard(): string
    {
        $response = $this->actingAs($this->merchant)->get('/dashboard');
        $response->assertStatus(200);

        return (string) $response->getContent();
    }

    private function orderIn(string $currency, float $amount, int $status = HomeController::ORDER_STATUS_DELIVERED): void
    {
        $order = $this->makeOrder($this->store, [
            'final_price' => $amount,
            'delivered_status' => $status,
        ]);

        DB::table('orders')->where('id', $order->id)->update(['currency_code' => $currency]);
        (new Order)->flushCache();

        $this->assertSame($currency, DB::table('orders')->where('id', $order->id)->value('currency_code'),
            'premise failed: the order does not carry the currency this case is about');
    }

    public function test_two_currencies_are_not_added_into_the_order_value_tile(): void
    {
        $this->orderIn('EUR', 100.00);
        $this->orderIn('USD', 50.00);

        $body = $this->dashboard();

        $this->assertStringNotContainsString('150.00', $body,
            'the tile added 100 EUR and 50 USD into one figure, which is not a quantity of either');

        $this->assertStringContainsString('100.00', $body,
            "the store's own currency total is missing, so the figure was withheld rather than "
            . 'bucketed');
    }

    public function test_money_in_another_currency_is_disclosed_rather_than_dropped(): void
    {
        $this->orderIn('EUR', 100.00);
        $this->orderIn('USD', 50.00);

        $this->assertStringContainsString('USD', $this->dashboard(),
            'the 50 USD is neither added nor disclosed, so it has silently vanished from the '
            . "merchant's dashboard");
    }

    public function test_the_hint_does_not_claim_one_currency_for_a_mixed_store(): void
    {
        /*
         * The sentence "in this store's currency" is the part that is actually FALSE, as opposed
         * to merely silent, so it is asserted on its own.
         *
         * THROUGH `e()`, BECAUSE THE FIRST DRAFT PASSED FOR THE WRONG REASON. Blade escapes the
         * apostrophe to `&#039;`, so a raw literal containing `store's` never appears in the page
         * and the assertion was satisfied by the escaping rather than by the fix. Measured on the
         * rendered dashboard before correcting it. `e()` is what the page itself applies.
         */
        $this->orderIn('EUR', 100.00);
        $this->orderIn('USD', 50.00);

        $body = $this->dashboard();

        $this->assertStringNotContainsString(
            e('The total value of orders placed, in this store\'s currency, excluding orders that were cancelled.'),
            $body,
            'the hint still asserts the whole figure is in this store\'s currency, which is false '
            . 'when the store has taken another one'
        );
    }

    // -------------------------------------------------------- what must NOT change (owner text)

    public function test_the_cancellation_and_returns_clauses_survive(): void
    {
        /*
         * F257. `HomeController.php:343-350` records that returns (status 3) are STILL INCLUDED
         * and calls it a deliberate non-decision; `overview.blade.php:22-24` says the hint is
         * where a merchant learns it. Nothing in tests/ pinned the sentence before this case, so
         * a refactor that replaced the literal with a shared one would have dropped the
         * disclosure invisibly. This is that pin.
         */
        $this->orderIn('EUR', 100.00);

        $body = $this->dashboard();

        $this->assertStringContainsString('excluding orders that were cancelled', $body,
            'the cancellation exclusion is no longer disclosed on the dashboard');

        $this->assertStringContainsString(e('Returned orders are still included'), $body,
            'the F257 returns disclosure was dropped from the Order value hint -- it is an owner '
            . 'decision and the hint is where a merchant learns it');
    }

    public function test_a_single_currency_store_still_sees_its_plain_total(): void
    {
        /* The common case must not regress into a caveat-laden tile: one currency, one figure. */
        $this->orderIn('EUR', 100.00);
        $this->orderIn('EUR', 25.50);

        $body = $this->dashboard();

        $this->assertStringContainsString('125.50', $body,
            'a single-currency store no longer sees its own total');

        $this->assertStringNotContainsString('which is not added in and not converted', $body,
            'a single-currency store was shown a foreign-currency caveat that does not apply');
    }

    public function test_a_cancelled_order_is_still_excluded_whatever_its_currency(): void
    {
        /* The predicate must survive the rewrite: F257 excludes cancellations, and that is the
           one inclusion rule this tile has. */
        $this->orderIn('EUR', 100.00);
        $this->orderIn('EUR', 999.00, HomeController::ORDER_STATUS_CANCEL);

        $body = $this->dashboard();

        $this->assertStringNotContainsString('1,099.00', $body,
            'a cancelled order was counted into the tile');
        $this->assertStringContainsString('100.00', $body,
            'the surviving order is gone too, so the predicate excludes more than cancellations');
    }

    private function orderWithNoRecordedCurrency(float $amount): void
    {
        $order = $this->makeOrder($this->store, [
            'final_price' => $amount,
            'delivered_status' => HomeController::ORDER_STATUS_DELIVERED,
        ]);

        DB::table('orders')->where('id', $order->id)->update(['currency_code' => null]);
        (new Order)->flushCache();

        $this->assertNull(DB::table('orders')->where('id', $order->id)->value('currency_code'),
            'premise failed: the order still carries a currency, so this case is not the one '
            . 'about unrecorded history');
    }

    private function setStoreCurrencyTo(string $value): void
    {
        DB::table('settings')
            ->where('store_id', $this->store->id)
            ->where('name', 'defult_currancy')
            ->update(['value' => $value]);

        $this->assertSame($value, DB::table('settings')
            ->where('store_id', $this->store->id)
            ->where('name', 'defult_currancy')
            ->value('value'), 'premise failed: the store currency setting did not change');
    }

    /**
     * REVIEW FINDING F3 -- the one this slice would otherwise have shipped.
     *
     * `orders.currency_code` is NOT backfilled by its migration, so every order a store placed
     * before the stamp landed carries NULL and lands in `MetricResult`'s `unknown` bucket.
     * `totalIn()` reads ONE bucket and never throws, so reading the store's code directly drops
     * that entire history out of the headline -- silently, with the Feature gate green, on
     * exactly the long-established stores that have the most of it.
     *
     * `DashboardOrderValueTest` cannot catch this: it writes no currency setting, so every order
     * is stamped with the same default and there is only ever one bucket. It passes either way.
     */
    public function test_orders_with_no_recorded_currency_are_still_in_the_headline(): void
    {
        $this->orderIn('EUR', 100.00);
        $this->orderWithNoRecordedCurrency(50.00);

        $body = $this->dashboard();

        $this->assertStringContainsString('150.00', $body,
            'the 50.00 placed before the currency stamp existed was dropped from the tile, so a '
            . 'store that has traded for years sees only its recent orders');

        $this->assertStringNotContainsString('unknown', $body,
            '"unknown" is a bucket key, not a currency, and it has been printed to the merchant '
            . 'as though it were one they trade in');
    }

    public function test_unrecorded_history_is_not_described_as_another_currency(): void
    {
        $this->orderIn('EUR', 100.00);
        $this->orderWithNoRecordedCurrency(50.00);

        $this->assertStringNotContainsString(self::ANOTHER_CURRENCY_SENTENCE, $this->dashboard(),
            'orders with no recorded currency were described to the merchant as being in another '
            . 'currency, which is a claim the data does not support');
    }

    /**
     * REVIEW FINDING F4. `defult_currancy` is merchant-written and owner decision D-N34 leaves
     * existing rows alone, so the stored value can be a SYMBOL (FIN-SYM, FIN-CUR-2). Reading it
     * unnormalised names a bucket that holds nothing: `totalIn('EUR sign')` is 0.00, and the tile
     * reads "nothing was ordered" for a store that has taken money.
     */
    public function test_a_store_whose_currency_setting_is_a_symbol_still_sees_its_money(): void
    {
        $this->setStoreCurrencyTo('€');
        $this->orderIn('EUR', 100.00);

        $body = $this->dashboard();

        $this->assertStringContainsString('100.00', $body,
            'the tile read an un-normalised setting as a currency code, found an empty bucket '
            . 'and showed the merchant nothing');

        $this->assertStringNotContainsString(self::ANOTHER_CURRENCY_SENTENCE, $body,
            "the store's own and only currency was listed back to it as \"another\" currency");
    }

    /**
     * The state the word "Also" cannot describe: more than one currency AND no configured store
     * currency to choose between them, so there is no headline for anything to be "also" to.
     */
    public function test_a_mixed_store_with_no_configured_currency_is_not_told_also(): void
    {
        $this->setStoreCurrencyTo('');
        $this->orderIn('EUR', 100.00);
        $this->orderIn('USD', 50.00);

        $body = $this->dashboard();

        $this->assertStringNotContainsString(self::ANOTHER_CURRENCY_SENTENCE, $body,
            'the hint said money was ordered "also" in another currency when no figure was shown '
            . 'for it to be additional to');

        $this->assertStringContainsString(self::NOT_COMBINED_SENTENCE, $body,
            'the two currencies were left undisclosed, so the merchant sees an unavailable tile '
            . 'with no statement of why');

        /* The component's OWN unavailable state (`stat.blade.php:94`), which only a null `value`
           reaches. Handing it the translated string "Not available" renders that text as though
           it were a figure, in the figure's own type, without the class a stylesheet or a screen
           reader can act on. */
        $this->assertStringContainsString('ss-stat--unavailable', $body,
            'the tile was given a string instead of null, so it presents "no figure" as a figure');
    }

    /**
     * THE `unknown` FILTER IS LOAD-BEARING ONLY IN THIS STATE, which is why a mutation removing
     * it survived every other case here. When the store HAS a currency the controller folds the
     * unrecorded rows into it, so there is no `unknown` bucket left for the view to mis-name --
     * the filter is then belt-and-braces. When the store has NO usable currency nothing can be
     * assumed, the fold is a no-op, and the bucket reaches the view intact. The filter is the
     * only thing standing between the merchant and the sentence "50.00 unknown".
     */
    public function test_unrecorded_money_is_never_named_as_a_currency_to_the_merchant(): void
    {
        $this->setStoreCurrencyTo('');
        $this->orderIn('EUR', 100.00);
        $this->orderWithNoRecordedCurrency(50.00);

        $body = $this->dashboard();

        $this->assertStringNotContainsString('unknown', $body,
            '"unknown" is this codebase\'s internal name for "no currency was recorded", and it '
            . 'has been printed to the merchant as though it were a currency they trade in');

        $this->assertStringContainsString(__('Currency not recorded'), $body,
            'the unattributed 50.00 was dropped from the hint instead of disclosed, so a '
            . 'merchant reading it believes their store has taken 100.00 and no more');

        $this->assertStringContainsString('100.00', $body,
            'the money that DOES have a recorded currency was withheld as well, so the tile says '
            . 'less than it honestly can');
    }

    /**
     * BOTH DIRECTIONS, because every money surface here is bilingual and a hint assembled in PHP
     * is the shape that silently ships English. The locale comes from `users.language` --
     * `SetLocate` re-resolves it per request, so `app()->setLocale()` in a test does not survive
     * the call and would make this pass for the wrong reason.
     */
    public function test_the_currency_sentences_are_arabic_for_an_arabic_merchant(): void
    {
        $this->merchant->language = 'ar';
        $this->merchant->save();
        (new User)->flushCache();
        $this->merchant = User::find($this->merchant->id);

        $this->orderIn('EUR', 100.00);
        $this->orderIn('USD', 50.00);

        $body = $this->dashboard();

        $this->assertStringContainsString('dir="rtl"', $body,
            'premise failed: the page did not render right-to-left, so this is not the Arabic '
            . 'rendering and proves nothing about it');

        $this->assertStringContainsString(
            'كما سُجّلت طلبات بعملة أخرى',
            $body,
            'the disclosure that money sits in another currency was shown to an Arabic merchant '
            . 'in English');

        $this->assertStringNotContainsString(self::ANOTHER_CURRENCY_SENTENCE, $body,
            'the English sentence is on the Arabic page, so the string was concatenated rather '
            . 'than translated');
    }
}
