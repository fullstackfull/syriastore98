<?php

namespace Tests\Feature\Merchant;

use App\Http\Controllers\HomeController;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Translation\MessageSelector;
use Tests\Feature\Security\SecurityTestCase;

/**
 * WS C — the attention rows render the wrong grammatical number in Arabic.
 * =================================================================================================
 *
 * WHAT WAS WRONG, MEASURED RATHER THAN INFERRED. Six live `trans_choice` keys were stored in
 * `resources/lang/ar.json` as TWO segments, `singular|plural`, the shape English needs. Arabic
 * does not have two forms, and Laravel does not pretend it does:
 * `MessageSelector::getPluralIndex('ar', $n)` returns SIX indices — 0 for zero, 1 for one, 2 for
 * two, 3 for 3–10, 4 for 11–99, 5 for 100+ — and `choose()` falls back to `$segments[0]` whenever
 * the index it computed is absent. With two segments present that produced, for every count a
 * merchant can actually see:
 *
 *      n = 1            index 1   ->  segment[1]   the PLURAL, for one item
 *      n = 2, 3 … 99    index 2-4 ->  absent       falls back to segment[0], the SINGULAR
 *      n = 100 +        index 5   ->  absent       falls back to segment[0], the SINGULAR
 *
 * So the one count that should be singular was plural, and every count that should be plural was
 * singular. Not a near-miss on an edge case: the Arabic dashboard was wrong for 1, wrong for 2,
 * wrong for 5, wrong for 40 and wrong for 300. English was unaffected, which is why it survived —
 * `getPluralIndex('en', $n)` is `$n == 1 ? 0 : 1` and two segments is exactly right for it.
 *
 * THE FIX IS THE FRAMEWORK'S OWN MECHANISM, not a helper. Each Arabic value now declares explicit
 * ranges (`{1} … |{2} … |[3,10] … |[11,99] … |[100,*] …`). `MessageSelector::choose()` runs
 * `extract()` BEFORE it ever asks for a plural index, so a value with ranges is locale-independent
 * and cannot fall back to the wrong segment. `LynomiaChat`'s own integration view already used
 * this syntax, so it is the house style rather than an invention here.
 *
 * WHY THERE IS A RATCHET TEST AND NOT JUST ASSERTIONS ON SIX STRINGS. These values are translation
 * content: the next person to touch them will be editing Arabic prose, not reading
 * `MessageSelector`. `test_every_arabic_plural_value_declares_its_own_ranges` fails the moment any
 * Arabic plural value goes back to the two-segment shape — including a NEW key added later, which
 * assertions on today's six would not catch.
 *
 * AND ONE TEST DRIVES A REAL PAGE. Everything above can be proved against the translator in
 * isolation, and proving it there only shows the selector works. `test_the_order_screen_…` renders
 * `/order` through a real login with the merchant's language set to Arabic, so the assertion passes
 * through `SetLocate`, `ViewServiceProvider`, the component and the view — the path a merchant
 * actually takes.
 */
class DashboardPluralFormsTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * The keys that a view really calls, with the Arabic forms each count must produce.
     *
     * Arabic counted-noun agreement, with the numeral rendered in its own element beside the
     * phrase (which is how `x-ss.attention-item` and the order screen both build these rows):
     *   1 singular, 2 dual, 3–10 plural, 11–99 singular accusative, 100+ singular genitive.
     *
     * @return array<string, array<int, string>>
     */
    private function expectations(): array
    {
        return [
            'order is waiting to be confirmed|orders are waiting to be confirmed' => [
                1 => 'طلب بانتظار التأكيد',
                2 => 'طلبان بانتظار التأكيد',
                3 => 'طلبات بانتظار التأكيد',
                10 => 'طلبات بانتظار التأكيد',
                11 => 'طلبًا بانتظار التأكيد',
                99 => 'طلبًا بانتظار التأكيد',
                100 => 'طلب بانتظار التأكيد',
                317 => 'طلب بانتظار التأكيد',
            ],
            'product is out of stock|products are out of stock' => [
                1 => 'منتج نفد من المخزون',
                2 => 'منتجان نفدا من المخزون',
                3 => 'منتجات نفدت من المخزون',
                11 => 'منتجًا نفد من المخزون',
                100 => 'منتج نفد من المخزون',
            ],
            'order has been returned|orders have been returned' => [
                1 => 'طلب مُرتجَع',
                2 => 'طلبان مُرتجَعان',
                3 => 'طلبات مُرتجَعة',
                11 => 'طلبًا مُرتجَعًا',
                100 => 'طلب مُرتجَع',
            ],
            'return is waiting for a decision|returns are waiting for a decision' => [
                1 => 'مرتجع بانتظار القرار',
                2 => 'مرتجعان بانتظار القرار',
                3 => 'مرتجعات بانتظار القرار',
                11 => 'مرتجعًا بانتظار القرار',
                100 => 'مرتجع بانتظار القرار',
            ],
            'order is still unpaid|orders are still unpaid' => [
                1 => 'طلب غير مدفوع',
                2 => 'طلبان غير مدفوعين',
                3 => 'طلبات غير مدفوعة',
                11 => 'طلبًا غير مدفوع',
                100 => 'طلب غير مدفوع',
            ],
        ];
    }

    // ---------------------------------------------------------------------------------------
    //  THE SELECTOR
    // ---------------------------------------------------------------------------------------

    public function test_arabic_renders_the_singular_for_one_and_not_the_plural(): void
    {
        App::setLocale('ar');

        foreach ($this->expectations() as $key => $byCount) {
            $this->assertSame($byCount[1], trans_choice($key, 1),
                "one item renders the wrong grammatical number in Arabic for '{$key}'");
        }
    }

    public function test_arabic_renders_the_dual_for_two(): void
    {
        App::setLocale('ar');

        foreach ($this->expectations() as $key => $byCount) {
            $this->assertSame($byCount[2], trans_choice($key, 2),
                "two items do not render the Arabic dual for '{$key}'");
        }
    }

    public function test_arabic_renders_the_plural_for_three_to_ten(): void
    {
        App::setLocale('ar');

        foreach ($this->expectations() as $key => $byCount) {
            $this->assertSame($byCount[3], trans_choice($key, 3), $key);
        }

        $pending = array_key_first($this->expectations());
        $this->assertSame($this->expectations()[$pending][10], trans_choice($pending, 10));
    }

    public function test_arabic_renders_the_accusative_singular_for_eleven_to_ninety_nine(): void
    {
        App::setLocale('ar');

        foreach ($this->expectations() as $key => $byCount) {
            $this->assertSame($byCount[11], trans_choice($key, 11), $key);
        }

        $pending = array_key_first($this->expectations());
        $this->assertSame($this->expectations()[$pending][99], trans_choice($pending, 99));
    }

    public function test_arabic_renders_the_genitive_singular_from_one_hundred(): void
    {
        App::setLocale('ar');

        foreach ($this->expectations() as $key => $byCount) {
            $this->assertSame($byCount[100], trans_choice($key, 100), $key);
        }

        $pending = array_key_first($this->expectations());
        $this->assertSame($this->expectations()[$pending][317], trans_choice($pending, 317));
    }

    /**
     * THE FORMS MUST ACTUALLY DIFFER. Without this, a value that declared five ranges all holding
     * the SAME text would satisfy every assertion above, and the merchant would be back where they
     * started with a passing suite.
     */
    public function test_the_arabic_forms_for_one_two_and_many_are_three_different_strings(): void
    {
        App::setLocale('ar');

        foreach (array_keys($this->expectations()) as $key) {
            $forms = [trans_choice($key, 1), trans_choice($key, 2), trans_choice($key, 3)];

            $this->assertCount(3, array_unique($forms),
                "Arabic one/two/many collapse to fewer than three distinct forms for '{$key}': "
                . implode(' | ', $forms));
        }
    }

    public function test_english_still_selects_on_one_versus_many(): void
    {
        App::setLocale('en');

        $this->assertSame('order is waiting to be confirmed',
            trans_choice('order is waiting to be confirmed|orders are waiting to be confirmed', 1));
        $this->assertSame('orders are waiting to be confirmed',
            trans_choice('order is waiting to be confirmed|orders are waiting to be confirmed', 2));
        $this->assertSame('orders are waiting to be confirmed',
            trans_choice('order is waiting to be confirmed|orders are waiting to be confirmed', 40));
    }

    /**
     * THE RATCHET. Every Arabic value for a key with plural segments must declare its own ranges,
     * and must declare enough of them to cover the six Arabic categories. A two-segment value —
     * the shape that produced the defect — fails here whatever its wording, and so does a new key
     * added later in the broken shape.
     */
    public function test_every_arabic_plural_value_declares_its_own_ranges(): void
    {
        $arabic = json_decode((string) file_get_contents(resource_path('lang/ar.json')), true);
        $this->assertIsArray($arabic);

        $pluralKeys = array_filter(array_keys($arabic), fn ($k) => str_contains((string) $k, '|'));

        /* ANTI-VACUITY. A census that finds nothing passes for the wrong reason, and this file has
           produced that reading before: if the keys are ever renamed away from the piped form this
           test must fail rather than quietly assert nothing. */
        $this->assertGreaterThanOrEqual(6, count($pluralKeys),
            'no Arabic plural keys were found at all, so this test proved nothing');

        $selector = new MessageSelector();

        foreach ($pluralKeys as $key) {
            $segments = explode('|', (string) $arabic[$key]);

            $this->assertGreaterThanOrEqual(5, count($segments),
                "the Arabic value for '{$key}' has only " . count($segments) . ' segment(s). '
                . "Arabic needs a form for one, two, 3-10, 11-99 and 100+; with fewer, "
                . 'MessageSelector::choose() falls back to the first segment for most counts.');

            foreach ($segments as $i => $segment) {
                $this->assertMatchesRegularExpression('/^[\{\[]/', $segment,
                    "segment {$i} of the Arabic value for '{$key}' declares no range condition, so "
                    . 'it is selected by plural index and can be reached for the wrong count');
            }

            /* And the ranges must really resolve: a malformed condition makes `extract()` return
               null and puts the value straight back on the broken index path. */
            foreach ([1, 2, 3, 11, 100] as $n) {
                $chosen = $selector->choose((string) $arabic[$key], $n, 'ar');
                $this->assertDoesNotMatchRegularExpression('/^[\{\[]/', $chosen,
                    "n={$n} on '{$key}' selected a segment with its condition still attached, "
                    . 'which means no range matched and the fallback fired');
            }
        }
    }

    // ---------------------------------------------------------------------------------------
    //  THE REAL PAGE
    // ---------------------------------------------------------------------------------------

    /**
     * Driven end to end: a real login, `users.language = 'ar'`, the real middleware, the real
     * component. One pending order must produce the Arabic SINGULAR on the page.
     */
    public function test_the_order_screen_renders_the_arabic_singular_for_one_pending_order(): void
    {
        $merchant = $this->makeMerchant(['language' => 'ar']);
        $store = $this->makeStore($merchant, ['default_language' => 'ar']);
        $merchant->current_store = $store->id;
        $merchant->save();
        $this->grantPermission(User::find($merchant->id), 'Manage Order');
        $merchant = User::find($merchant->id);

        $this->makeOrder($store, ['delivered_status' => HomeController::ORDER_STATUS_PENDING]);

        $body = (string) $this->actingAs($merchant)->get(route('order.index'))->getContent();

        $this->assertStringContainsString('ss-attention', $body,
            'the attention block did not render, so this test could not have measured its wording');
        $this->assertStringContainsString('طلب بانتظار التأكيد', $body);
        $this->assertStringNotContainsString('طلبات بانتظار التأكيد', $body,
            'one pending order rendered the Arabic PLURAL on the real page');
    }

    /**
     * The same page with three, which must now be the plural — so the test above cannot be
     * satisfied by a value that simply lost its plural form.
     */
    public function test_the_order_screen_renders_the_arabic_plural_for_three_pending_orders(): void
    {
        $merchant = $this->makeMerchant(['language' => 'ar']);
        $store = $this->makeStore($merchant, ['default_language' => 'ar']);
        $merchant->current_store = $store->id;
        $merchant->save();
        $this->grantPermission(User::find($merchant->id), 'Manage Order');
        $merchant = User::find($merchant->id);

        for ($i = 0; $i < 3; $i++) {
            $this->makeOrder($store, ['delivered_status' => HomeController::ORDER_STATUS_PENDING]);
        }

        $body = (string) $this->actingAs($merchant)->get(route('order.index'))->getContent();

        $this->assertStringContainsString('طلبات بانتظار التأكيد', $body,
            'three pending orders did not render the Arabic plural on the real page');
    }

    /**
     * The error summary interpolates `:count` INSIDE the sentence, so its Arabic forms have to
     * survive `makeReplacements()` running over a line that still carries its range conditions.
     */
    public function test_the_error_summary_count_is_substituted_inside_the_arabic_forms(): void
    {
        App::setLocale('ar');
        $key = 'There is a problem with :count field|There is a problem with :count fields';

        $this->assertSame('هناك مشكلة في حقل واحد', trans_choice($key, 1, ['count' => 1]));
        $this->assertSame('هناك مشكلة في حقلين', trans_choice($key, 2, ['count' => 2]));
        $this->assertSame('هناك مشكلة في 4 حقول', trans_choice($key, 4, ['count' => 4]));
        $this->assertSame('هناك مشكلة في 12 حقلًا', trans_choice($key, 12, ['count' => 12]));

        foreach ([1, 2, 4, 12] as $n) {
            $this->assertStringNotContainsString(':count', trans_choice($key, $n, ['count' => $n]),
                "the :count placeholder survived unreplaced for n={$n}");
        }
    }
}
