<?php

namespace Tests\Feature\Merchant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\Feature\Security\SecurityTestCase;

/**
 * D-10 — the printed receipt no longer carries a foreign brand's truncated refund policy, and no
 * policy has been put in its place.
 * =============================================================================================
 *
 * WHAT WAS REMOVED. `order/receipt.blade.php` hard-coded a WorkDo demo paragraph on every printed
 * customer receipt: merchandise may not be returned for refund at any time, "power bases" cannot
 * be exchanged, and no refunds are available for purchases made in "a Milo Showroom" — an
 * unrelated brand — ending mid-sentence on the words "and sales c".
 *
 * WHY THIS IS A TEST AND NOT JUST A DELETION. Two directions, and the second is the one the owner
 * was explicit about:
 *
 *   1. The text must really be gone from what the customer receives. A source grep cannot prove
 *      that here, because the explanatory Blade comment left in that view quotes the brand name to
 *      record what was removed. Blade strips `{{-- --}}` at compile time, so the RENDERED output is
 *      the only honest place to assert it — and asserting on the source would pass while the
 *      paragraph was still live, or fail while it was correctly gone, depending only on comment
 *      wording.
 *
 *   2. Nothing may have replaced it. "لا تستبدله بسياسة إرجاع أو استرداد جديدة من عندك" — do not
 *      substitute a return or refund policy of your own. A later well-meant edit that fills the
 *      empty footer with reassuring boilerplate would be authoring a commercial rule, so the
 *      rendered receipt is checked for refund-policy language generally, not just for the old
 *      string.
 *
 * THE VIEW IS RENDERED DIRECTLY rather than driven through the route. `order_receipt()` takes a
 * `Crypt::decrypt()`-ed id and calls `Order::order_detail()`, whose shape is incidental to this
 * assertion; and the route is anonymous with its own recorded history. What is being tested is
 * what the TEMPLATE emits, so the template is rendered with the data it declares it needs.
 */
class ReceiptReturnPolicyTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * Phrases that would mean somebody had written a refund rule into the receipt. Deliberately
     * about MEANING rather than the one removed string: the point is that no policy appears, not
     * that one particular sentence is absent.
     *
     * @return list<string>
     */
    private const POLICY_LANGUAGE = [
        'may not be returned',
        'non-refundable',
        'no refunds',
        'cannot be exchanged',
        'all sales are final',
        'Milo',
        'Power bases',
        'power bases',
    ];

    private function render(): string
    {
        [$merchant, $store] = $this->makeTenant();

        $orderId = (int) \DB::table('orders')->insertGetId([
            'product_order_id' => 'RCPT-' . uniqid(),
            'order_date' => now(),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_json' => json_encode([]),
            'product_id' => '',
            'product_price' => 10,
            'coupon_price' => 0,
            'delivery_price' => 0,
            'tax_price' => 0,
            'final_price' => 10,
            'payment_type' => 'cod',
            'payment_status' => 'Unpaid',
            'delivered_status' => 0,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = \App\Models\Order::order_detail($orderId);

        // PREMISE: the fixture really produced a renderable order. Without this, an empty or
        // error-shaped $order would render a near-empty document and every absence below would
        // pass for free.
        $this->assertIsArray($order, 'premise: order_detail did not return an array');
        $this->assertArrayNotHasKey('message', $order,
            'premise: order_detail reported an error, so the receipt would not render the real '
            . 'document: ' . json_encode($order['message'] ?? null));

        $html = View::make('order.receipt', [
            'order' => $order,
            'documentLocale' => 'en',
            'documentRtl' => false,
            'invoice_logo' => '',
        ])->render();

        // PREMISE: this is really the receipt and not an error page, so the absences below mean
        // something. The footer region the paragraph lived in is still present.
        $this->assertStringContainsString('footer', $html,
            'premise: the rendered output does not look like the receipt template');

        return $html;
    }

    public function test_the_rendered_receipt_carries_no_refund_policy_language(): void
    {
        $html = $this->render();

        $found = [];
        foreach (self::POLICY_LANGUAGE as $phrase) {
            if (str_contains($html, $phrase)) {
                $found[] = $phrase;
            }
        }

        $this->assertSame([], $found,
            "The printed receipt states a refund rule. If this is the WorkDo demo text, it has come "
            . "back; if it is new wording, it is a commercial rule somebody authored, which D-10 "
            . "forbids. A real policy may be shown only when it is approved and stored as a store "
            . "setting, escaped and translated. Found:\n  " . implode("\n  ", $found));
    }

    /**
     * The Blade comment that records the removal is a COMMENT, so it must not reach the customer.
     * This is the assertion that makes the test above trustworthy: it proves the rendered output
     * is what is being measured, not the source.
     */
    public function test_the_explanatory_comment_is_stripped_from_the_rendered_output(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('D-10', $html,
            'the Blade comment recording this decision reached the rendered receipt');
        $this->assertStringNotContainsString('{{--', $html);

        // And it IS in the source, so the removal stays documented where the next reader looks.
        $source = file_get_contents(resource_path('views/order/receipt.blade.php'));
        $this->assertNotFalse($source);
        $this->assertStringContainsString('D-10', $source,
            'the decision is no longer recorded in the view, so the next reader has no idea why '
            . 'the footer is empty and will fill it');
    }

    /**
     * The owner allowed a REAL policy to be shown if one is approved and stored in the store's
     * settings. There is none, and this pins that premise: the day somebody adds such a setting,
     * this test fails and whoever added it has to decide deliberately whether the receipt shows
     * it — rather than the receipt silently continuing to show nothing.
     */
    public function test_no_stored_return_policy_setting_exists_yet(): void
    {
        $candidates = \DB::table('settings')
            ->where(function ($q) {
                $q->where('name', 'like', '%polic%')
                  ->orWhere('name', 'like', '%refund%')
                  ->orWhere('name', 'like', '%invoice_term%');
            })
            ->pluck('name')
            ->all();

        $this->assertSame([], $candidates,
            "A policy-shaped setting now exists: " . implode(', ', $candidates) . ".\n"
            . "D-10 permits a REAL approved policy to be rendered on the receipt, escaped and "
            . "translated. Decide whether this is that policy. If it is, pass it into the view "
            . "(which today receives no settings at all) and update this test; if it is not, "
            . "narrow this query. Do not leave it ambiguous.");
    }
}
