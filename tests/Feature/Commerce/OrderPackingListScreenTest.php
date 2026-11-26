<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Feature\Security\SecurityTestCase;

/**
 * MASTER PLAN `ORD-008` (GAP-185) — THE PICKING AND PACKING LIST ON THE ORDER SCREEN.
 * ===================================================================================
 *
 * Plan text: `docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-WITH-AMENDMENTS.md:287`,
 * section 04-C — «Picking وPacking والباركود والملصقات والفواتير.» The label and invoice nouns were
 * already two buttons at the top of this very page (`shippinglabel.pdf`, `order.receipt`); picking
 * and packing were absent from the whole codebase, measured by whole-word census.
 *
 * `App\Support\Orders\PackingSlip` is unit-tested in `PackingSlipTest`. This file asserts the half
 * that cannot be: that the document reaches the merchant's screen, with the quantities on it, in
 * both reading directions, and that it prints alone.
 *
 * WHY THE SCREEN AND NOT A DOCUMENT ROUTE. A standalone `order-packing-list/{id}` document beside
 * the receipt and the label is the shape the other two have, and it needs one line in `routes/web.php`
 * plus one `OrderDocumentAccessGuard::TARGETS` entry. This workstream does not own `routes/`, so the
 * list is rendered on the order detail screen instead — which is already behind
 * `OrderDocumentAccessGuard`, so no new access decision is made — and the route is named in the
 * handover as the remaining step rather than left implicit.
 */
class OrderPackingListScreenTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;

    private \App\Models\Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        /* Burn store id 1: `getCurrentStore()` answers the literal 1 for an operator with an empty
           `current_store`, and a store under test that IS store 1 collapses distinct numbers. */
        $decoy = $this->makeMerchant();
        $this->makeStore($decoy);

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        /* `OrderController::order_view()` gates on these; a 403 would satisfy an absence assertion
           without the document existing at all. */
        $this->grantPermission(User::find($this->merchant->id), 'Manage Order');
        $this->grantPermission(User::find($this->merchant->id), 'Show Order');
        $this->merchant = User::find($this->merchant->id);
    }

    private function orderWithTwoLines(): Order
    {
        $simple = Product::forceCreate([
            'name' => 'Woollen scarf',
            'slug' => 'woollen-scarf-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'trending' => 0,
            'status' => 1,
            'variant_product' => 0,
            'track_stock' => 1,
            'product_stock' => 50,
            'stock_status' => 'in_stock',
            'store_id' => $this->store->id,
            'created_by' => $this->merchant->id,
        ]);
        (new Product)->flushCache();

        return $this->makeOrder($this->store, [
            'product_id' => (string) $simple->id,
            'delivered_status' => 4,
            'payment_status' => 'Paid',
            /* `qty` AND `quantity` are both sent, because `Order::order_detail()` keeps only one of
               them depending on `payment_type` and the fixture must survive either branch. */
            'product_json' => json_encode([
                [
                    'product_id' => $simple->id,
                    'name' => 'Woollen scarf as recorded',
                    'qty' => 3,
                    'quantity' => 3,
                    'price' => 10,
                    'orignal_price' => 10,
                    'final_price' => 10,
                    'variant_id' => null,
                    'variant_name' => null,
                ],
                [
                    'product_id' => $simple->id,
                    'name' => 'Linen wrap as recorded',
                    'qty' => 4,
                    'quantity' => 4,
                    'price' => 20,
                    'orignal_price' => 20,
                    'final_price' => 20,
                    'variant_id' => null,
                    'variant_name' => 'Large / Ecru',
                ],
            ]),
        ]);
    }

    /** The rendered order screen, with the premise that it rendered at all. */
    private function screen(Order $order): string
    {
        $response = $this->actingAs($this->merchant)
            ->get(route('order.view', Crypt::encrypt($order->id)));

        $this->assertSame(200, $response->getStatusCode(),
            'the order screen did not render, so nothing below measures the packing list. Body: '
            . substr((string) $response->getContent(), 0, 400));

        $body = (string) $response->getContent();

        /* THE PREMISE THE WHOLE FILE RESTS ON. A screen rendering zero line items satisfies every
           assertion here for free -- the exact vacuity `OrderPanelProductScopeTest` records three
           green tests against. */
        $this->assertStringContainsString('as recorded', $body,
            'the screen rendered no line items, so the packing list has nothing to list');

        return $body;
    }

    // ══════════════════════════════════════════════════════════════════════════════ THE DOCUMENT

    /** The list is on the screen, with every line and the unit total. */
    public function test_the_packing_list_is_rendered_with_its_quantities(): void
    {
        $body = $this->screen($this->orderWithTwoLines());

        $this->assertStringContainsString('id="ss-packing-slip"', $body,
            'the picking and packing list is not on the order screen. ORD-008 names Picking and '
            . 'Packing beside the barcode, label and invoice nouns this page already has.');

        $this->assertStringContainsString('Woollen scarf as recorded', $body);
        $this->assertStringContainsString('Linen wrap as recorded', $body);
        $this->assertStringContainsString('Large / Ecru', $body,
            'the variant name is missing, so a packer cannot tell which variant to pull');

        /* 3 + 4 = 7 units. Asserted against the slip's own footer cell rather than anywhere on the
           page, because "7" appears in a date and a price too -- the scoping rule CLAUDE.md §3
           records (an assertion over a whole document answers "does this string exist anywhere"). */
        $footer = $this->slipFooter($body);
        $this->assertStringContainsString('7', $footer,
            'the unit total is not 7 (3 + 4). Footer cell: ' . $footer);
    }

    /** The printed tick box is there, and it is not a form control that posts nowhere. */
    public function test_the_list_offers_a_tick_box_that_is_not_an_input(): void
    {
        $body = $this->slipMarkup($this->screen($this->orderWithTwoLines()));

        $this->assertStringContainsString('ss-packing-slip__box', $body,
            'there is no tick box, so the document is a packing slip but not a PICKING list');
        $this->assertStringNotContainsString('<input', $body,
            'the tick box is a real input. It posts nowhere and has no label, so it would be an '
            . 'unlabelled control on the page — it is printed for a pen.');
    }

    /** No money on the slip — the same contract `PackingSlipTest` pins on the builder. */
    public function test_the_rendered_list_carries_no_money(): void
    {
        $body = $this->slipMarkup($this->screen($this->orderWithTwoLines()));

        foreach (['$', '10.00', '20.00', '70.00'] as $needle) {
            $this->assertStringNotContainsString($needle, $body,
                'the rendered packing list contains ' . $needle . '. The slip travels in the '
                . 'parcel; the priced document is the receipt, and putting money here would mean '
                . 'choosing which figure to print.');
        }
    }

    /**
     * IT PRINTS ALONE, AND IN THE RIGHT DIRECTION.
     *
     * The print rule is asserted on the stylesheet it ships with, comments stripped — CLAUDE.md §3
     * records eight occasions where a census was satisfied by prose about the thing it measured, one
     * of them a CSS comment recording a property a draft had used.
     */
    public function test_the_print_rule_isolates_the_slip_and_uses_a_logical_offset(): void
    {
        $css = $this->slipStylesheet($this->screen($this->orderWithTwoLines()));

        $this->assertStringContainsString('@media print', $css,
            'there is no print rule, so printing the slip prints the whole admin page');
        $this->assertStringContainsString('body.ss-print-packing * { visibility: hidden; }', $css,
            'the rest of the page is not blanked while the slip prints');
        $this->assertStringContainsString('inset-inline-start', $css,
            'the printed slip is positioned with a physical offset. This page renders '
            . 'right-to-left in Arabic, so `left` would push it off the page.');
        $this->assertStringNotContainsString('left: 0', $css,
            'a physical `left` offset is still in the print rule');
    }

    /** The class is removed again, so a cancelled print cannot leave the page blank. */
    public function test_the_print_class_is_cleared_after_printing(): void
    {
        $body = $this->screen($this->orderWithTwoLines());

        $this->assertStringContainsString("window.addEventListener('afterprint'", $body,
            'nothing clears the print class, so cancelling the print dialog leaves every element '
            . 'on the order screen invisible');
        $this->assertStringContainsString("classList.remove('ss-print-packing')", $body);
    }

    // ══════════════════════════════════════════════════════════════════════════════ BILINGUAL

    /**
     * RIGHT-TO-LEFT: the list is there, and the page really is in RTL.
     *
     * THE DIRECTION IS A SETTING, NOT THE LOCALE, and the first version of this case got that wrong.
     * `layouts/app.blade.php` sets `dir` from `$setting['SITE_RTL']`, which
     * `Utility::GetValueByName('SITE_RTL')` reads out of the `settings` table for the current
     * store -- so `app()->setLocale('ar')` in a test body changed nothing and the case failed
     * against a page that had rendered `dir="ltr"`. The row is inserted here instead, and the
     * `dir="rtl"` assertion is the premise that proves it took.
     */
    public function test_the_list_renders_right_to_left(): void
    {
        $order = $this->orderWithTwoLines();
        $this->turnRtlOn();

        $body = $this->screen($order);

        $this->assertStringContainsString('dir="rtl"', $body,
            'premise: the page did not render right-to-left, so this case is not measuring the '
            . 'RTL direction at all');
        $this->assertStringContainsString('id="ss-packing-slip"', $body,
            'the packing list disappeared when the page was rendered right-to-left');
        $this->assertStringContainsString('Woollen scarf as recorded', $body,
            'the line items disappeared when the page was rendered right-to-left');

        /* The one property that would break here, asserted on the slip's own stylesheet rather than
           anywhere on the page: a physical `text-align: right` on the quantity column reads as the
           WRONG edge once the page flips. */
        $css = $this->slipStylesheet($body);
        $this->assertStringContainsString('text-align: end', $css,
            'the quantity column is aligned with a physical edge, which lands on the wrong side of '
            . 'a right-to-left slip');
        $this->assertStringNotContainsString('text-align: right', $css);
    }

    /** LEFT-TO-RIGHT — the control for the case above, and the suite's default state. */
    public function test_the_list_renders_left_to_right(): void
    {
        $body = $this->screen($this->orderWithTwoLines());

        $this->assertStringContainsString('Picking and packing list', $body);
        $this->assertStringNotContainsString('dir="rtl"', $body,
            'premise: the default page already rendered right-to-left, so the RTL case above '
            . 'proves nothing about direction');
        $this->assertStringContainsString('id="ss-packing-slip"', $body);
    }

    /**
     * `settings.SITE_RTL = on` for the store under test, with every cache the readers consult
     * cleared.
     *
     * `Setting` is `Cachable` and `Utility::Setting()` memoises per store, so a raw insert alone
     * leaves the layout reading the pre-fixture value -- the measured false reading CLAUDE.md
     * records three times.
     */
    private function turnRtlOn(): void
    {
        \App\Models\Setting::create([
            'name' => 'SITE_RTL',
            'value' => 'on',
            'store_id' => $this->store->id,
            'created_by' => $this->merchant->id,
        ]);

        (new \App\Models\Setting)->flushCache();
        \Illuminate\Support\Facades\Cache::flush();
    }

    // ══════════════════════════════════════════════════════════════════════════════ AN EMPTY ORDER

    /** An order with no line items says so rather than rendering an empty table. */
    public function test_an_order_with_no_lines_says_so(): void
    {
        $order = $this->makeOrder($this->store, [
            'delivered_status' => 4,
            'payment_status' => 'Paid',
            'product_json' => '[]',
        ]);

        $response = $this->actingAs($this->merchant)
            ->get(route('order.view', Crypt::encrypt($order->id)));

        $this->assertSame(200, $response->getStatusCode(),
            'the order screen did not render for an order with no line items');

        $body = (string) $response->getContent();

        $this->assertStringContainsString('id="ss-packing-slip"', $body);
        $this->assertStringContainsString('no line items to pick', $body,
            'an order with no lines rendered a table with no rows instead of saying so');
    }

    // ══════════════════════════════════════════════════════════════════════════════ HELPERS

    /** Just the slip's own markup, so an assertion cannot be answered by the rest of the page. */
    private function slipMarkup(string $body): string
    {
        $start = strpos($body, 'id="ss-packing-slip"');
        $this->assertNotFalse($start, 'the slip is not on the page, so it cannot be scoped to');

        /* The partial emits `<!-- /ss-packing-slip -->` for exactly this: without a hard end
           anchor the scope ran on for 905 lines and the no-money and no-input assertions were
           answering about the whole order screen, which has prices and form controls of its own. */
        $end = strpos($body, '<!-- /ss-packing-slip -->', $start);
        $this->assertNotFalse($end, 'the slip\'s end anchor is missing, so these assertions would '
            . 'be answered by the rest of the order screen rather than by the slip');

        return substr($body, $start, ($end === false ? strlen($body) : $end) - $start);
    }

    /** The footer cell holding the unit total. */
    private function slipFooter(string $body): string
    {
        $markup = $this->slipMarkup($body);
        $start = strpos($markup, '<tfoot>');
        $this->assertNotFalse($start, 'the slip has no footer, so the unit total is not printed');

        return substr($markup, $start, (strpos($markup, '</tfoot>') ?: strlen($markup)) + 8 - $start);
    }

    /** The slip's own stylesheet, with CSS comments stripped. */
    private function slipStylesheet(string $body): string
    {
        $start = strpos($body, '.ss-packing-slip__head');
        $this->assertNotFalse($start, 'the slip\'s stylesheet is not on the page');

        $end = strpos($body, '</style>', $start);
        $css = substr($body, $start, ($end === false ? strlen($body) : $end) - $start);

        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }
}
