<?php

namespace Tests\Feature\ApiContract;

use App\Http\Controllers\HomeController;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F29 — every order status the database can hold must have its own text in the API.
 * =============================================================================================
 *
 * `Order::order_detail()` initialises `order_status_text` to `__('Pending')` and then overwrites
 * it from a chain of `if ($order->delivered_status == N)`. The chain covered 0, 1, 2, 3, 4, 5 and
 * 6. `orders.delivered_status` holds NINE values, so **7 (Partially Paid) and 8 (Pre Order) kept
 * the default** — and the shipped app renders that string, so a merchant who had taken a part
 * payment and a customer who had pre-ordered were both told "Pending".
 *
 * WHY A TEST OVER ALL NINE RATHER THAN TWO CASES. The defect is not "two statuses were missed",
 * it is "the default is a real status name, so a miss is invisible". Any tenth value added to
 * `delivered_status` would fall through the same way and report Pending, and nothing would say
 * so. This enumerates the statuses from `HomeController`'s own constants and requires each to
 * produce text that is not the default — so the next addition fails here rather than in an app.
 *
 * IT ASSERTS THE NUMERIC FIELD IS UNCHANGED TOO. `order_status` is what a client switches on, and
 * the owner's approval of F29 was for display and translation only. If a later change to the text
 * also moved the number, that is a contract break and this says so.
 */
class OrderStatusTextTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The nine values `orders.delivered_status` holds, with the text the WEB panel renders. */
    private const EXPECTED = [
        0 => 'Pending',
        HomeController::ORDER_STATUS_DELIVERED => 'Delivered',
        2 => 'Cancel',
        4 => 'Comfirmed',          // the host's own spelling; keyed and translated as-is
        5 => 'Picked Up',
        6 => 'Shipped',
        HomeController::ORDER_STATUS_PARTIALLY_PAID => 'Partially Paid',
        HomeController::ORDER_STATUS_PRE_ORDER => 'Pre Order',
    ];

    private function orderWithStatus(int $status): Order
    {
        [$merchant, $store] = $this->makeTenant();

        return $this->makeOrder($store, [
            'delivered_status' => $status,
            'created_by' => $merchant->id,
        ]);
    }

    public function test_the_constants_this_guards_still_hold_their_values(): void
    {
        // A premise: if a constant moved, the table above is describing a different status.
        $this->assertSame(1, HomeController::ORDER_STATUS_DELIVERED);
        $this->assertSame(7, HomeController::ORDER_STATUS_PARTIALLY_PAID);
        $this->assertSame(8, HomeController::ORDER_STATUS_PRE_ORDER);
    }

    public function test_every_status_reports_its_own_text_and_its_own_number(): void
    {
        $seen = [];

        foreach (self::EXPECTED as $status => $expected) {
            $order = $this->orderWithStatus((int) $status);

            $detail = Order::order_detail($order->id);
            $this->assertIsArray($detail, 'order_detail did not return an array for status ' . $status);

            $text = $detail['order_status_text'] ?? null;
            $number = $detail['order_status'] ?? null;

            $seen[(int) $status] = ['text' => $text, 'number' => $number];

            $this->assertSame($expected, $text,
                'status ' . $status . ' reports ' . json_encode($text) . ' instead of '
                . json_encode($expected) . '. Measured before F29: 7 and 8 both reported '
                . '"Pending", because the if-chain did not cover them and the default is a real '
                . 'status name.');

            $this->assertSame((int) $status, (int) $number,
                'status ' . $status . ': order_status must still carry the number a client '
                . 'switches on. F29 was approved for display and translation only.');
        }

        // and the two that were wrong are specifically not the default any more
        $this->assertNotSame('Pending', $seen[7]['text'], 'status 7 still reports the default');
        $this->assertNotSame('Pending', $seen[8]['text'], 'status 8 still reports the default');
    }

    public function test_both_corrected_strings_are_translated_in_arabic(): void
    {
        $ar = json_decode(file_get_contents(base_path('resources/lang/ar.json')), true);

        foreach (['Partially Paid', 'Pre Order'] as $string) {
            $this->assertArrayHasKey($string, $ar,
                'ar.json is missing "' . $string . '", so an Arabic client would read English');
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $ar[$string],
                'ar.json holds no Arabic for "' . $string . '"');
        }
    }
}
