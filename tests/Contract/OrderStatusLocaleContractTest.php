<?php

namespace Tests\Contract;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F154 — the additive status fields, and the promise that nothing else moved.
 * =================================================================================================
 *
 * The admin API never runs `SetLocate` (measured from the booted kernel: the `web` group carries
 * it, the `api` group has two middleware and neither is it), so every `__()`-rendered field
 * reaches the Flutter app in the application default language rather than the merchant's.
 *
 * The fix is additive, and this file exists to hold BOTH halves of that word:
 *
 *   NEW      `order_status_key` is stable and machine-readable; `order_status_label` follows the
 *            merchant's own language.
 *   UNMOVED  `order_status_text` keeps its exact value, and `payment_status` keeps being a bare
 *            English literal — because the Flutter client compares it to `'paid'` in five places
 *            (`order_status_badge.dart:389,418,477`, `payment_status_bottom_sheet.dart:26`,
 *            `notification_screen.dart:543`). Localising that field would silently mark every
 *            Arabic merchant's paid orders unpaid.
 *
 * The last point is why this is a CONTRACT test and not a unit test: the risk is not in the new
 * code, it is in what the new code might have disturbed.
 */
class OrderStatusLocaleContractTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function orderIn($store, int $deliveredStatus = 0, string $paymentStatus = 'paid'): Order
    {
        $product = Product::forceCreate([
            'name' => 'f154-' . uniqid(), 'slug' => 'f154-' . uniqid(),
            'product_stock' => 10, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100, 'sale_price' => 100, 'stock_status' => 'in_stock',
            'status' => 1, 'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        return Order::create([
            'product_order_id' => 'ORD-' . strtoupper(uniqid()),
            'order_date' => now(),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_json' => json_encode([[
                'product_id' => $product->id, 'name' => $product->name,
                'quantity' => 1, 'price' => 100, 'variant_id' => 0,
            ]]),
            'product_id' => (string) $product->id,
            'product_price' => 100, 'coupon_price' => 0, 'delivery_price' => 0,
            'tax_price' => 0, 'final_price' => 100,
            'payment_type' => 'cod', 'payment_status' => $paymentStatus,
            'delivered_status' => $deliveredStatus,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function orderListFor($merchant, $store): array
    {
        $token = User::find($merchant->id)->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/orderlist?store_id=' . $store->id);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'the order list did not answer 200, so this test cannot read its payload. Body: '
            . substr((string) $response->getContent(), 0, 300)
        );

        return (array) $response->json();
    }

    /** The first order row in the payload, wherever the envelope puts it. */
    private function firstRow(array $payload): array
    {
        $rows = $payload['data']['data'] ?? $payload['data'] ?? [];

        $this->assertIsArray($rows);
        $this->assertNotEmpty($rows, 'the order list returned no rows, so every assertion would be vacuous');

        return (array) $rows[0];
    }

    // ---------------------------------------------------------------- the new fields

    public function test_the_payload_carries_a_stable_key_and_a_localised_label(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission(User::find($merchant->id), 'Manage Order');
        $this->assertCurrencyRatesIsMigrated();
        $this->orderIn($store, 6);  // shipped

        $row = $this->firstRow($this->orderListFor($merchant, $store));

        $this->assertArrayHasKey('order_status_key', $row, 'order_status_key is absent from the payload');
        $this->assertArrayHasKey('order_status_label', $row, 'order_status_label is absent from the payload');

        $this->assertSame('shipped', $row['order_status_key']);
        $this->assertNotSame('', trim((string) $row['order_status_label']));
    }

    /**
     * THE KEY IS THE SAME BYTES FOR AN ARABIC MERCHANT. This is the contract a consumer branches
     * on; if it moved with the locale it would be no better than the field it replaces.
     */
    public function test_the_key_is_identical_for_an_arabic_merchant(): void
    {
        [$english, $storeEn] = $this->makeTenant();
        $this->grantPermission(User::find($english->id), 'Manage Order');
        $this->assertCurrencyRatesIsMigrated();
        $this->orderIn($storeEn, 4);  // confirmed

        [$arabic, $storeAr] = $this->makeTenant();
        User::where('id', $arabic->id)->update(['language' => 'ar']);
        $this->grantPermission(User::find($arabic->id), 'Manage Order');
        $this->orderIn($storeAr, 4);

        $enRow = $this->firstRow($this->orderListFor($english, $storeEn));
        $arRow = $this->firstRow($this->orderListFor(User::find($arabic->id), $storeAr));

        $this->assertSame('confirmed', $enRow['order_status_key']);
        $this->assertSame(
            $enRow['order_status_key'],
            $arRow['order_status_key'],
            'order_status_key differs between an English and an Arabic merchant, so it is not a '
            . 'stable identifier and a consumer cannot branch on it.'
        );
    }

    // ---------------------------------------------------------------- nothing else moved

    /**
     * `payment_status` MUST STAY A BARE ENGLISH LITERAL.
     *
     * Five places in the Flutter client compare it to `'paid'`. If this ever starts arriving
     * localised, every Arabic merchant's paid orders render as unpaid — including in the
     * payment-status bottom sheet, which is a money surface.
     */
    public function test_payment_status_is_still_a_bare_english_literal_for_an_arabic_merchant(): void
    {
        [$merchant, $store] = $this->makeTenant();
        User::where('id', $merchant->id)->update(['language' => 'ar']);
        $this->grantPermission(User::find($merchant->id), 'Manage Order');
        $this->assertCurrencyRatesIsMigrated();
        $this->orderIn($store, 1, 'paid');

        $row = $this->firstRow($this->orderListFor(User::find($merchant->id), $store));

        $this->assertSame(
            'paid',
            strtolower(trim((string) ($row['payment_status'] ?? ''))),
            'payment_status is no longer the literal the Flutter client compares against. '
            . 'order_status_badge.dart:389,418,477, payment_status_bottom_sheet.dart:26 and '
            . 'notification_screen.dart:543 all do `== \'paid\'`.'
        );
    }

    /**
     * `order_status_text` is still present and still a non-empty string: no consumer lost it.
     *
     * Asserted against `/vieworder` and not `/orderlist`, because the two endpoints have
     * genuinely different shapes and this field belongs to only one of them. The list row is the
     * serialised model -- `delivered_status` (the integer) plus appended attributes including
     * `delivered_status_string` -- while `order_status_text` is built by
     * `Order::order_detail()`. Measured, after a first version of this case asserted it on the
     * list and failed for that reason rather than for a regression.
     */
    public function test_order_status_text_is_untouched_on_the_detail_endpoint(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission(User::find($merchant->id), 'Manage Order');
        $this->assertCurrencyRatesIsMigrated();
        $order = $this->orderIn($store, 6);

        $token = User::find($merchant->id)->createToken('auth_token')->plainTextToken;

        $detail = (array) $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/vieworder?store_id=' . $store->id . '&order_id=' . $order->id)
            ->json('data');

        $this->assertArrayHasKey('order_status_text', $detail, 'an existing consumer lost order_status_text');
        $this->assertNotSame('', trim((string) $detail['order_status_text']));

        // And the two new fields are there beside it rather than instead of it.
        $this->assertSame('shipped', $detail['order_status_key'] ?? null);
        $this->assertNotSame('', trim((string) ($detail['order_status_label'] ?? '')));
    }

    /** The list carries them too, so the two endpoints agree. */
    public function test_the_list_carries_the_same_two_fields(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission(User::find($merchant->id), 'Manage Order');
        $this->assertCurrencyRatesIsMigrated();
        $this->orderIn($store, 1);

        $row = $this->firstRow($this->orderListFor($merchant, $store));

        $this->assertSame('delivered', $row['order_status_key'] ?? null);
        $this->assertNotSame('', trim((string) ($row['order_status_label'] ?? '')));

        // The list's own translated string is untouched.
        $this->assertArrayHasKey('delivered_status_string', $row);
    }

    // ---------------------------------------------------------------- tenant isolation

    /**
     * The new fields must not have widened what a merchant can see. Driven as a whole-payload
     * check rather than a count, because the hazard is a row appearing at all.
     */
    public function test_a_merchant_sees_only_their_own_orders(): void
    {
        [$mine, $myStore] = $this->makeTenant();
        $this->grantPermission(User::find($mine->id), 'Manage Order');
        $this->assertCurrencyRatesIsMigrated();
        $myOrder = $this->orderIn($myStore, 0);

        [$other, $otherStore] = $this->makeTenant();
        $this->grantPermission(User::find($other->id), 'Manage Order');
        $theirOrder = $this->orderIn($otherStore, 0);

        $body = json_encode($this->orderListFor($mine, $myStore));

        $this->assertStringContainsString(
            (string) $myOrder->product_order_id,
            (string) $body,
            'premise: the merchant cannot see their own order, so the absence below proves nothing'
        );
        $this->assertStringNotContainsString(
            (string) $theirOrder->product_order_id,
            (string) $body,
            'A merchant can see another tenant\'s order in the list the F154 fields were added to.'
        );
    }
}
