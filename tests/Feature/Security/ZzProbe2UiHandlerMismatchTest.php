<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

/**
 * TEMPORARY PROBE 2 -- not a deliverable. Plan-order approve/reject buttons, and the refund screens.
 */
class ZzProbe2UiHandlerMismatchTest extends SecurityTestCase
{
    use RefreshDatabase;

    public function test_probe_plan_order_approve_reject_buttons_offered_to_the_payer(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $planOrder = \App\Models\PlanOrder::create([
            'order_id' => 'PO-' . uniqid(),
            'plan_name' => 'Starter',
            'plan_id' => 1,
            'price' => 100,
            'payment_type' => 'Bank Transfer',
            'payment_status' => 'Pending',
            'receipt' => 'uploads/slip.png',
            'user_id' => $merchant->id,
            'store_id' => $store->id,
        ]);

        $page = $this->actingAs($merchant)->get('/plan/orders/show/' . $planOrder->id);
        fwrite(STDERR, "\n[PLANORDER] payer GET /plan/orders/show/{id} => " . $page->getStatusCode() . "\n");
        $body = $page->getStatusCode() === 200 ? $page->getContent() : '';
        fwrite(STDERR, "[PLANORDER] page offers order.approve button: " . (str_contains($body, 'order_approve/') ? 'YES' : 'no') . "\n");
        fwrite(STDERR, "[PLANORDER] page offers order.reject button: " . (str_contains($body, 'order_reject/') ? 'YES' : 'no') . "\n");

        fwrite(STDERR, "[PLANORDER] payer GET /order_approve/{id} => "
            . $this->actingAs($merchant)->get('/order_approve/' . $planOrder->id)->getStatusCode() . "\n");
        fwrite(STDERR, "[PLANORDER] payer GET /order_reject/{id} => "
            . $this->actingAs($merchant)->get('/order_reject/' . $planOrder->id)->getStatusCode() . "\n");

        $this->assertTrue(true);
    }

    public function test_probe_refund_screens(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        $refund = \App\Models\OrderRefund::create([
            'order_id' => $order->id,
            'refund_status' => 'Pending',
            'refund_reason' => 'Other',
            'product_refund_id' => '[]',
            'product_refund_price' => 0,
            'refund_amount' => 10,
            'store_id' => $store->id,
        ]);

        // A role holding ONLY the refund-list permission.
        $this->grantPermission($merchant, 'Manage Order Refund Request');
        $merchant = $merchant->fresh();
        fwrite(STDERR, "\n[REFUND-A] isAbleTo('Manage Order Refund Request')=" . ($merchant->isAbleTo('Manage Order Refund Request') ? 'T' : 'F')
            . " isAbleTo('Show Order')=" . ($merchant->isAbleTo('Show Order') ? 'T' : 'F') . "\n");

        fwrite(STDERR, "[REFUND-A] GET /refund-request (list) => " . $this->actingAs($merchant)->get('/refund-request')->getStatusCode() . "\n");

        $this->actingAs($merchant);
        $actionHtml = view('order.refund-action', ['refund_request' => $refund])->render();
        fwrite(STDERR, "[REFUND-A] refund-action partial offers refund-request.show: " . (str_contains($actionHtml, 'refund-request/') ? 'YES' : 'no') . "\n");

        $cipher = Crypt::encrypt($order->id);
        fwrite(STDERR, "[REFUND-A] GET /refund-request/<cipher> (detail) => "
            . $this->actingAs($merchant)->get('/refund-request/' . $cipher)->getStatusCode() . "\n");

        // Now the reverse principal: 'Show Order' only -- can open the detail page, cannot act.
        [$m2, $s2] = $this->makeTenant();
        $o2 = $this->makeOrder($s2);
        $r2 = \App\Models\OrderRefund::create([
            'order_id' => $o2->id,
            'refund_status' => 'Pending',
            'refund_reason' => 'Other',
            'product_refund_id' => '[]',
            'product_refund_price' => 0,
            'refund_amount' => 10,
            'store_id' => $s2->id,
        ]);
        $this->grantPermission($m2, 'Show Order');
        $m2 = $m2->fresh();
        fwrite(STDERR, "\n[REFUND-B] isAbleTo('Show Order')=" . ($m2->isAbleTo('Show Order') ? 'T' : 'F')
            . " isAbleTo('Manage Order Refund Request')=" . ($m2->isAbleTo('Manage Order Refund Request') ? 'T' : 'F') . "\n");

        $c2 = Crypt::encrypt($o2->id);
        $detail = $this->actingAs($m2)->get('/refund-request/' . $c2);
        fwrite(STDERR, "[REFUND-B] GET /refund-request/<cipher> (detail) => " . $detail->getStatusCode() . "\n");
        $body = $detail->getStatusCode() === 200 ? $detail->getContent() : '';
        foreach (['accept-button', 'cancel-button', 'manage-button', 'refund-amount'] as $hook) {
            fwrite(STDERR, "[REFUND-B] detail page offers '{$hook}': " . (str_contains($body, $hook) ? 'YES' : 'no') . "\n");
        }
        fwrite(STDERR, "[REFUND-B] POST /update-refund-status => "
            . $this->actingAs($m2)->post('/update-refund-status', ['refund_id' => $r2->id, 'refund_status' => 'Accepted'])->getStatusCode() . "\n");
        fwrite(STDERR, "[REFUND-B] POST /cancel-refund-status => "
            . $this->actingAs($m2)->post('/cancel-refund-status', ['refund_id' => $r2->id])->getStatusCode() . "\n");
        fwrite(STDERR, "[REFUND-B] POST /refund-stock => "
            . $this->actingAs($m2)->post('/refund-stock', ['refund_id' => $r2->id])->getStatusCode() . "\n");
        fwrite(STDERR, "[REFUND-B] POST /refund-amount/{$o2->id} => "
            . $this->actingAs($m2)->post('/refund-amount/' . $o2->id, ['refund_id' => $r2->id])->getStatusCode() . "\n");

        $this->assertTrue(true);
    }

    public function test_probe_customer_view_page_order_links(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Customer');
        $role = \App\Models\Role::where('name', 'granted-role-' . $merchant->id)->first();
        $role->givePermission(\App\Models\Permission::firstOrCreate(['name' => 'Show Customer'], ['display_name' => 'Show Customer']));
        $merchant->flushCache();
        $merchant = $merchant->fresh();

        $customer = \App\Models\Customer::create([
            'first_name' => 'Cart', 'last_name' => 'Owner',
            'email' => 'cv' . uniqid() . '@example.test',
            'password' => bcrypt('x'),
            'store_id' => $store->id,
            'status' => 1,
            'regiester_date' => now(),
        ]);
        $order = $this->makeOrder($store, ['customer_id' => $customer->id, 'is_guest' => 0]);

        fwrite(STDERR, "\n[CUSTVIEW] isAbleTo('Show Customer')=" . ($merchant->isAbleTo('Show Customer') ? 'T' : 'F')
            . " isAbleTo('Show Order')=" . ($merchant->isAbleTo('Show Order') ? 'T' : 'F') . "\n");

        $page = $this->actingAs($merchant)->get('/customer/' . $customer->id);
        fwrite(STDERR, "[CUSTVIEW] GET /customer/{id} (cart page) => " . $page->getStatusCode() . "\n");
        $body = $page->getStatusCode() === 200 ? $page->getContent() : '';
        fwrite(STDERR, "[CUSTVIEW] page offers order.view links: " . (str_contains($body, 'order-view/') ? 'YES' : 'no') . "\n");

        $cipher = Crypt::encrypt($order->id);
        fwrite(STDERR, "[CUSTVIEW] GET /order-view/<cipher> => "
            . $this->actingAs($merchant)->get('/order-view/' . $cipher)->getStatusCode() . "\n");

        $this->assertTrue(true);
    }
}
