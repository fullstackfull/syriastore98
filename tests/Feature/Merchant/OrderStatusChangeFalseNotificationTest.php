<?php

namespace Tests\Feature\Merchant;

use App\Models\EmailTemplate;
use App\Models\EmailTemplateLang;
use App\Models\Order;
use App\Models\OrderBillingDetail;
use App\Models\OrderNote;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Models\UserEmailTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5-ORD-12 — a status change that did not happen must not be announced to the buyer.
 * =================================================================================================
 *
 * THE DEFECT, as the ORDERS audit confirmed it (115 raised → 103 refuted → 12 confirmed; this was
 * #12, and the only one of the twelve whose cost leaves the system irreversibly).
 *
 * `OrderController::order_status_change()` composed and sent a `Status Change` e-mail, fired
 * `SendOrderReviewMail`, wrote an `order_notes` row and sent a WhatsApp/SMS **above** the line
 * `if ($responce['status'] == 'success')`. So every refusal the writer can return still notified
 * the buyer:
 *
 *   - an empty `delivered` — which only the ORDER BOARD could post: its move control is a
 *     `<select name="delivered">` whose first option was `<option value="">Move to…</option>`,
 *     with no `required`, no `disabled`, an always-enabled submit and no JavaScript anywhere on
 *     the screen, three cards per column with pixel-aligned Move pairs;
 *   - `cancel` on an order already delivered (App\Models\Order:640-644);
 *   - `return` on an order not yet delivered (App\Models\Order:699-703);
 *   - any unrecognised status, `pre_order` with the PreOrder module off among them (F81).
 *
 * The buyer got "Your Order is , !Hi ORD-2043". `OrderNote::order_note_data()` wrote the order's
 * own timeline `'Order status changed to '.$data['changeble_status'].'.'` → literally
 * **"Order status changed to ."** (App\Models\OrderNote:181). The merchant was told only "That
 * order status is not recognised.", so they had no way to know a message had left the system —
 * and the order's history now carried an entry contradicting its own status.
 *
 * WHAT THE FIX IS, AND WHAT IT IS NOT. Two guards and no rule change:
 *
 *   1. the controller refuses an empty `delivered` BEFORE the write, with a message a merchant can
 *      act on ("Choose a status to move this order to.") instead of one that blames the data;
 *   2. the whole notification block moved INSIDE the success branch, unchanged line for line.
 *
 * No status vocabulary, permission, transition, price, stock or payment behaviour is touched. Every
 * value the writer accepted before is accepted now, and the e-mail, the event, the note and the
 * SMS are byte-identical on the success path — which `test_a_successful_move_still_notifies_the_
 * buyer_and_writes_its_note` is here to prove, because a fix that silenced the feature would pass
 * every other assertion in this file.
 *
 * THE BOARD'S OWN MARKUP IS GUARDED TOO, and deliberately not instead: `required` plus a
 * `disabled selected` placeholder stops the slip in the browser, where the merchant gets told
 * which control is wrong. It is not a control for a request that never came from a browser, which
 * is why the server guard exists and why both are asserted below.
 *
 * HOW THE ASSERTIONS WERE CHOSEN. `order_notes` is the deterministic witness: a row is either
 * there or it is not, and it is the half of the damage that persists. `Mail::fake()` carries the
 * other half, and for it to BITE the three template rows must exist — `Utility::sendEmailTemplate`
 * returns early on a missing `EmailTemplate`, a missing `UserEmailTemplate` or `is_active != 1`,
 * so a mail assertion without them would pass against the unfixed code too. `test_premise_the_
 * seeded_template_really_does_send` is the control on exactly that.
 */
class OrderStatusChangeFalseNotificationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant(['language' => 'en']);
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->grantPermission(User::find($this->merchant->id), 'Manage Order');
        $this->merchant = User::find($this->merchant->id);
    }

    private function orderAt(int $deliveredStatus = 0, string $ref = 'ORD-12-A'): Order
    {
        $order = $this->makeOrder($this->store, [
            'product_order_id' => $ref,
            'delivered_status' => $deliveredStatus,
            'payment_status' => 'Unpaid',
        ]);

        /*
         * THE BUYER'S ADDRESS, and it is not decoration. `Order::order_detail()` reads
         * `delivery_informations.email` and `.phone` out of `order_billing_details`
         * (App\Models\Order:525-526); with no row there the e-mail recipient is '' and
         * `Mail::to([''])` throws inside `Utility::sendEmailTemplate`'s own try/catch, so NO mail
         * is sent whatever the controller does — and every `Mail::assertNothingSent()` below
         * would pass against the unfixed code. Measured: without this row
         * `test_premise_the_seeded_template_really_does_send` fails with 0 sent.
         */
        OrderBillingDetail::create([
            'order_id' => $order->id,
            'product_order_id' => $ref,
            'first_name' => 'Buyer',
            'last_name' => 'One',
            'email' => 'buyer+' . $order->id . '@example.test',
            'telephone' => '+963900000000',
            'address' => '1 Test Street',
            'delivery_address' => '1 Test Street',
        ]);

        return $order;
    }

    /**
     * The three rows `Utility::sendEmailTemplate('Status Change', ...)` needs before it will send
     * anything at all. Without them it returns `['is_success' => false]` on a missing template or
     * raises a caught warning on a missing UserEmailTemplate, and `Mail::assertNothingSent()`
     * becomes vacuous.
     */
    private function seedStatusChangeTemplate(): void
    {
        $owner = User::find($this->store->created_by);

        $template = EmailTemplate::firstOrCreate(
            ['name' => 'Status Change'],
            ['from' => 'Store Syria', 'created_by' => $owner->id]
        );

        UserEmailTemplate::updateOrCreate(
            ['template_id' => $template->id, 'user_id' => $owner->id],
            ['is_active' => 1]
        );

        EmailTemplateLang::updateOrCreate(
            ['parent_id' => $template->id, 'language' => $owner->language ?: 'en'],
            [
                'subject' => 'Your order status changed',
                'content' => 'Order {order_id} is now {order_status}.',
                'is_default' => 1,
            ]
        );

        /*
         * AND THE EIGHT MAIL SETTINGS, which is the step that is easy to miss and fatal to miss.
         * `Utility::sendEmailTemplate` does `config(['mail.host' => $setting['MAIL_HOST'], ...])`
         * on the array `getAdminAllSetting($owner->id)` returns. On an empty settings table that
         * is eight "Undefined array key" warnings, Laravel turns the first into an ErrorException,
         * and the method's OWN try/catch swallows it and returns
         * `['is_success' => false, 'error' => 'E-Mail has been not sent due to SMTP configuration']`
         * -- so not one mail leaves the application and a `Mail::assertNothingSent()` is true for
         * a reason that has nothing to do with the code under test. Measured before these rows
         * were added: `is_success: false`. With them: `is_success: true`.
         */
        foreach ([
            'MAIL_DRIVER' => 'smtp',
            'MAIL_HOST' => 'smtp.example.test',
            'MAIL_PORT' => '587',
            'MAIL_ENCRYPTION' => 'tls',
            'MAIL_USERNAME' => 'mailer',
            'MAIL_PASSWORD' => 'not-a-real-secret',
            'MAIL_FROM_ADDRESS' => 'no-reply@example.test',
            'MAIL_FROM_NAME' => 'Store Syria',
        ] as $name => $value) {
            Setting::updateOrCreate(
                ['name' => $name, 'created_by' => $owner->id, 'store_id' => $this->store->id],
                ['value' => $value]
            );
        }

        // 79 of 95 models are Cachable and the suite runs on CACHE_STORE=array, so a row written
        // here is invisible to the read inside the controller unless the cache is flushed -- and
        // `getAdminAllSetting()` additionally memoises the owner under `user_<id>`.
        (new EmailTemplate)->flushCache();
        (new UserEmailTemplate)->flushCache();
        (new EmailTemplateLang)->flushCache();
        (new Setting)->flushCache();
        \Illuminate\Support\Facades\Cache::forget('user_' . $owner->id);
    }

    private function notesFor(Order $order): int
    {
        return (int) DB::table('order_notes')->where('order_id', $order->id)->count();
    }

    private function move(Order $order, ?string $value, bool $ajax = false): \Illuminate\Testing\TestResponse
    {
        $headers = $ajax
            ? ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => '*/*']
            : [];

        return $this->actingAs($this->merchant)->post(
            '/order-status-change/' . $order->id,
            ['delivered' => $value, 'id' => $order->id],
            $headers
        );
    }

    // =============================================================================================
    //  THE PREMISES. Each of the three assertions below would make the rest of the file vacuous
    //  if it were false, and two of them have been false in this codebase before.
    // =============================================================================================

    public function test_premise_the_seeded_template_really_does_send(): void
    {
        /* If this fails, every `Mail::assertNothingSent()` in this file proves nothing: it would
         * pass against the unfixed controller too, because no mail was ever going to be sent. */
        $this->seedStatusChangeTemplate();
        Mail::fake();

        $order = $this->orderAt(0, 'ORD-12-PREMISE');
        $this->move($order, 'confirmed', true)->assertOk();

        Mail::assertSentCount(1);
    }

    public function test_premise_the_writer_refuses_each_value_this_test_relies_on(): void
    {
        /* The four refusals asserted below come from the WRITER, not from the controller. If one of
         * them started succeeding, the matching test would be asserting the absence of a
         * notification on a path that no longer refuses anything. */
        $pending = $this->orderAt(0, 'ORD-12-P1');
        $delivered = $this->orderAt(1, 'ORD-12-P2');

        $this->assertSame('error', Order::order_status_change([
            'order_id' => $pending->id, 'order_status' => '',
        ])['status'], 'the empty string');

        $this->assertSame('error', Order::order_status_change([
            'order_id' => $pending->id, 'order_status' => 'no_such_status',
        ])['status'], 'an unrecognised status');

        $this->assertSame('error', Order::order_status_change([
            'order_id' => $delivered->id, 'order_status' => 'cancel',
        ])['status'], 'cancel on a delivered order');

        $this->assertSame('error', Order::order_status_change([
            'order_id' => $pending->id, 'order_status' => 'return',
        ])['status'], 'return before delivery');
    }

    public function test_premise_the_board_renders_a_move_control_for_this_merchant(): void
    {
        $this->orderAt(0, 'ORD-12-BOARD');

        $html = $this->actingAs($this->merchant)->get(route('order.kanban'))->getContent();

        $this->assertStringContainsString('ss-board__card-move', $html,
            'premise: the board renders the move form whose markup the next test measures');
    }

    // =============================================================================================
    //  THE SERVER GUARD — an unset status
    // =============================================================================================

    public function test_pressing_move_with_no_status_chosen_writes_no_order_note(): void
    {
        $order = $this->orderAt(0, 'ORD-12-NOTE');

        $this->move($order, '');

        $this->assertSame(0, $this->notesFor($order),
            'an order note was written for a status change that did not happen; before this fix it '
            . 'read literally "Order status changed to ." and is the order\'s own history');
    }

    public function test_pressing_move_with_no_status_chosen_sends_no_mail(): void
    {
        $this->seedStatusChangeTemplate();
        Mail::fake();

        $order = $this->orderAt(0, 'ORD-12-MAIL');

        $this->move($order, '');

        Mail::assertNothingSent();
    }

    public function test_an_absent_delivered_key_is_refused_the_same_way(): void
    {
        /* Not the same request as the empty string: the board posts `delivered=""`, but a client
         * that omits the key entirely reached `$request->delivered === null` and took the same
         * path. `trim((string) null)` is '' and the guard catches both. */
        $this->seedStatusChangeTemplate();
        Mail::fake();

        $order = $this->orderAt(0, 'ORD-12-ABSENT');

        $this->actingAs($this->merchant)->post('/order-status-change/' . $order->id, ['id' => $order->id]);

        $this->assertSame(0, $this->notesFor($order));
        Mail::assertNothingSent();
    }

    public function test_a_whitespace_only_status_is_refused_too(): void
    {
        $this->seedStatusChangeTemplate();
        Mail::fake();

        $order = $this->orderAt(0, 'ORD-12-WS');

        $this->move($order, '   ');

        $this->assertSame(0, $this->notesFor($order));
        Mail::assertNothingSent();
    }

    public function test_the_order_row_is_untouched_when_no_status_was_chosen(): void
    {
        $order = $this->orderAt(0, 'ORD-12-ROW');

        $before = (array) DB::table('orders')->where('id', $order->id)->first();

        $this->move($order, '');

        $after = (array) DB::table('orders')->where('id', $order->id)->first();
        unset($before['updated_at'], $after['updated_at']);

        $this->assertSame($before, $after, 'the order row changed on a refused move');
    }

    public function test_the_merchant_is_told_to_choose_a_status_rather_than_blamed_for_the_data(): void
    {
        $order = $this->orderAt(0, 'ORD-12-MSG');

        $response = $this->move($order, '');

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $message = (string) session('error');

        $this->assertStringContainsString('Choose a status', $message,
            'the flash read: ' . $message);
        $this->assertStringNotContainsString('not recognised', $message,
            'the old message blamed the submitted data for a control that let the merchant submit '
            . 'nothing at all');
    }

    public function test_the_ajax_caller_gets_the_refusal_as_json_in_the_shape_it_already_reads(): void
    {
        /* The detail page reads `data.status` off this route (order/view.blade.php:853). The guard
         * must not invent a third response shape, and must not become a 4xx: the shipped Flutter
         * app forces a logout on 401 and the detail page's handler reads the body on any 200. */
        $order = $this->orderAt(0, 'ORD-12-AJAX');

        $response = $this->move($order, '', true);

        $response->assertOk()->assertJson(['status' => false]);
        $this->assertSame(0, $this->notesFor($order));
    }

    // =============================================================================================
    //  THE SECOND HALF — the other three refusals the moved block now covers
    // =============================================================================================

    public function test_a_refused_cancel_on_a_delivered_order_notifies_nobody(): void
    {
        $this->seedStatusChangeTemplate();
        Mail::fake();

        $order = $this->orderAt(1, 'ORD-12-CANCEL');

        $this->move($order, 'cancel');

        $this->assertSame(0, $this->notesFor($order),
            'the writer refused the cancel ("The order has been delivered...") and an order note '
            . 'was written anyway');
        Mail::assertNothingSent();
    }

    public function test_a_refused_return_before_delivery_notifies_nobody(): void
    {
        $this->seedStatusChangeTemplate();
        Mail::fake();

        $order = $this->orderAt(0, 'ORD-12-RETURN');

        $this->move($order, 'return');

        $this->assertSame(0, $this->notesFor($order));
        Mail::assertNothingSent();
    }

    public function test_an_unrecognised_status_notifies_nobody(): void
    {
        $this->seedStatusChangeTemplate();
        Mail::fake();

        $order = $this->orderAt(0, 'ORD-12-UNKNOWN');

        $this->move($order, 'no_such_status');

        $this->assertSame(0, $this->notesFor($order));
        Mail::assertNothingSent();
    }

    public function test_pre_order_with_the_module_off_notifies_nobody(): void
    {
        /* The realistic one. `pre_order` is `orders.delivered_status` 8 and is in the API's own
         * status vocabulary, but its arm is behind `module_is_active('PreOrder')`, which is off by
         * default — so a legitimate value fell through to the unrecognised arm and notified. */
        $this->seedStatusChangeTemplate();
        Mail::fake();

        $order = $this->orderAt(0, 'ORD-12-PREORDER');

        $this->move($order, 'pre_order');

        $this->assertSame(0, $this->notesFor($order));
        Mail::assertNothingSent();
    }

    // =============================================================================================
    //  THE CONTROL — the feature still works. Without this, silencing the notification entirely
    //  would pass every assertion above.
    // =============================================================================================

    public function test_a_successful_move_still_notifies_the_buyer_and_writes_its_note(): void
    {
        $this->seedStatusChangeTemplate();
        Mail::fake();

        $order = $this->orderAt(0, 'ORD-12-OK');

        $response = $this->move($order, 'confirmed', true);

        $response->assertOk()->assertJson(['status' => 'success', 'order_status' => 'Confirmed']);

        $this->assertSame(4, (int) Order::withoutGlobalScopes()->find($order->id)->delivered_status,
            'premise: the move really happened');

        $this->assertSame(1, $this->notesFor($order),
            'the successful move must still write its order note — the fix moved that block, it did '
            . 'not remove it');

        Mail::assertSentCount(1);

        $note = OrderNote::withoutGlobalScopes()->where('order_id', $order->id)->first();
        $this->assertSame('Order status changed to confirmed.', $note->notes,
            'the note text on the success path must be unchanged');
        $this->assertSame((int) $this->store->id, (int) $note->store_id,
            'the note still carries the order\'s own store_id');
    }

    public function test_every_move_the_board_offers_still_writes_exactly_one_note(): void
    {
        /* All four board moves, so the fix cannot have silenced one of them — the failure shape
         * that three working options make look like the merchant's mistake. */
        foreach (['confirmed' => 4, 'pickedup' => 5, 'shipped' => 6, 'delivered' => 1] as $word => $expected) {
            $order = $this->orderAt(0, 'ORD-12-' . $word);

            $this->move($order, $word, true)->assertOk();

            $this->assertSame($expected,
                (int) Order::withoutGlobalScopes()->find($order->id)->delivered_status,
                $word . ' did not write its status');
            $this->assertSame(1, $this->notesFor($order), $word . ' wrote ' . $this->notesFor($order) . ' notes');
        }
    }

    // =============================================================================================
    //  THE CLIENT GUARD — the board's own markup
    // =============================================================================================

    public function test_the_boards_move_control_cannot_be_submitted_empty(): void
    {
        $this->orderAt(0, 'ORD-12-MARKUP');

        $html = $this->actingAs($this->merchant)->get(route('order.kanban'))->getContent();

        $select = null;
        if (preg_match('/<select[^>]*name="delivered"[^>]*>/', $html, $m)) {
            $select = $m[0];
        }

        $this->assertNotNull($select, 'premise: the board renders a select named delivered');

        $this->assertStringContainsString('required', $select,
            'the move select has no `required`, so the browser will submit an empty status: ' . $select);

        $this->assertMatchesRegularExpression(
            '/<option value=""[^>]*\bdisabled\b/',
            $html,
            'the "Move to…" placeholder is not `disabled`, so it is a selectable value that posts'
        );

        $this->assertMatchesRegularExpression(
            '/<option value=""[^>]*\bselected\b/',
            $html,
            'the placeholder is not `selected`; without it the first real status becomes the shown '
            . 'default and the control pre-arms a move the merchant did not choose'
        );
    }
}
