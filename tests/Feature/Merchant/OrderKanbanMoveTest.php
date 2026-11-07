<?php

namespace Tests\Feature\Merchant;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5-R-05 — the order board's Move button never moved anything.
 * =================================================================================================
 *
 * A POST-CLOSURE PHASE 02 DEFECT, repaired under the owner's decision of 2026-11-03, which
 * authorises repairing "the existing Kanban 'Move' action not performing its intended operation"
 * and adds one carve-out: *"repair only the existing authorized action and UI integration. If
 * restoring it requires changing order state-transition semantics, stop that specific change and
 * report the owner decision required."*
 *
 * **No state-transition semantics are changed by this slice.** `Order::order_status_change()` — the
 * writer, its accepted vocabulary, its cancel guard, its money handling — is not touched. All three
 * faults below are the board talking to that writer in the wrong words.
 *
 * MEASURED IN A BROWSER BEFORE ANY CODE CHANGED
 * (docs/development/phase-2.5/tools/probe-kanban.cjs):
 *
 *     HTTP 200, 7 cards, 7 move forms          the board renders fine
 *     POST /order-status-change/1              the form really does submit
 *     response 200, browser lands on that url  the merchant ends up on raw JSON
 *     board before == board after              and nothing moved
 *
 * THE THREE FAULTS
 *
 *  1. THE FIELD NAME. `order/partials/kanban-card.blade.php:58` named the select `order_status`.
 *     `OrderController::order_status_change()` reads `$request->delivered` (:1655) — the name the
 *     detail page's AJAX caller sends (`order/view.blade.php:853`). So `$data['order_status']` was
 *     always null and the writer matched none of its branches. The board POSTed, was authorised,
 *     and changed nothing.
 *
 *  2. THE VALUE. The board offered `picked_up`; `Order::order_status_change()` tests
 *     `$order_status == 'pickedup'`, no underscore. So even with fault 1 repaired, one of the four
 *     moves would have gone on silently doing nothing — the worse failure, because three working
 *     options make the fourth look like the merchant's mistake.
 *
 *  3. THE RESPONSE. The handler returns `response()->json(...)` on every path. That is right for
 *     the detail page, which calls it by AJAX and reads the JSON; it strands a plain form POST on
 *     a page of raw JSON with no way back. The handler now redirects back with a flash when the
 *     caller is not asking for JSON, and `test_the_detail_pages_ajax_contract_is_unchanged` is the
 *     control that the AJAX caller's answer is byte-identical.
 */
class OrderKanbanMoveTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** What `Order::order_status_change()` writes for each word it accepts. Read from the model. */
    private const WRITES = [
        'confirmed' => 4,
        'pickedup'  => 5,
        'shipped'   => 6,
        'delivered' => 1,
    ];

    private User $merchant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->grantPermission(User::find($this->merchant->id), 'Manage Order');
        $this->merchant = User::find($this->merchant->id);
    }

    private function orderAt(int $status = 0, string $ref = 'KB-1'): Order
    {
        return $this->makeOrder($this->store, [
            'product_order_id' => $ref,
            'delivered_status' => $status,
            'payment_status' => 'Unpaid',
        ]);
    }

    private function boardHtml(): string
    {
        $response = $this->actingAs($this->merchant)->get(route('order.kanban'));
        $this->assertSame(200, $response->status(), 'premise: the board rendered');

        return $response->getContent();
    }

    // =============================================================================================
    //  FAULT 1 — the field name
    // =============================================================================================

    /**
     * THE CONTRACT between the board and the writer, asserted against BOTH ends so it cannot drift
     * again: the select must carry the name the controller reads.
     */
    public function test_the_move_select_is_named_for_the_key_the_handler_reads(): void
    {
        $this->orderAt();

        $this->assertMatchesRegularExpression(
            '/<select[^>]+name="delivered"/', $this->boardHtml(),
            'the board\'s move select no longer sends the key OrderController::order_status_change '
            . 'reads, so every move is authorised and then discarded');
    }

    /** And the handler really does read that key — the other end of the same contract. */
    public function test_premise_the_handler_reads_the_delivered_key(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/OrderController.php'));

        $this->assertStringContainsString("\$data['order_status'] = \$request->delivered;", $source,
            'the handler reads a different request key now, so the board is sending the wrong one '
            . 'again — change the view to match, not this test');
    }

    // =============================================================================================
    //  FAULT 2 — the value
    // =============================================================================================

    /**
     * EVERY move the board offers must be a word the writer accepts. This is the assertion that
     * catches `picked_up`, and it is written as a sweep rather than as four cases so a fifth
     * option added later cannot slip past it.
     */
    public function test_every_move_the_board_offers_is_a_word_the_writer_accepts(): void
    {
        $this->orderAt();
        $html = $this->boardHtml();

        preg_match('/<select[^>]+name="delivered"[^>]*>(.*?)<\/select>/s', $html, $m);
        $this->assertNotEmpty($m, 'premise: the board renders a move select');

        preg_match_all('/<option value="([^"]*)"/', $m[1], $options);
        $offered = array_values(array_filter($options[1]));   // drop the empty "Move to…" option

        $this->assertNotEmpty($offered, 'premise: the select offers at least one move');

        foreach ($offered as $value) {
            $this->assertArrayHasKey($value, self::WRITES,
                "the board offers '$value', which Order::order_status_change() matches no branch "
                . "for — the merchant picks it, the POST is authorised, and nothing happens");
        }
    }

    // =============================================================================================
    //  THE REPAIR, END TO END — every offered move really moves the order
    // =============================================================================================

    /**
     * REWRITTEN AFTER REVIEW. The first version posted the four hardcoded words under the key
     * `delivered` itself, so it never read the board and passed against the fully UNREPAIRED
     * code — the handler already read `delivered`. It sat under a heading claiming it was end to
     * end, which is the same "passes for the wrong reason" shape this project keeps meeting.
     *
     * It now takes BOTH the field name and the values out of the rendered board, so reverting
     * fault 1 (the name) or fault 2 (the value) fails it.
     */
    public function test_each_move_the_board_offers_really_changes_the_order(): void
    {
        $this->orderAt(0, 'KB-PARSE');
        $html = $this->boardHtml();

        /*
         * ANCHORED ON `id="ss-move-`, NOT ON "the first select on the page". The first draft of
         * this matched the FILTER BAR's status select — whose options are the numeric
         * delivered_status values — and failed with "the board offers '4'". The board's move
         * control is the one the card partial gives that id.
         */
        preg_match('/<select[^>]*id="ss-move-[^"]*"[^>]*>(.*?)<\/select>/s', $html, $sel);
        $this->assertNotEmpty($sel, 'premise: the board renders a move select');

        preg_match('/<select[^>]*id="ss-move-[^"]*"[^>]*name="([^"]+)"/', $html, $nameMatch);
        if (empty($nameMatch)) {
            preg_match('/<select[^>]*name="([^"]+)"[^>]*id="ss-move-/', $html, $nameMatch);
        }
        $this->assertNotEmpty($nameMatch, 'premise: the move select carries a name attribute');

        $field = $nameMatch[1];
        preg_match_all('/<option value="([^"]*)"/', $sel[1], $opts);
        $offered = array_values(array_filter($opts[1]));
        $this->assertNotEmpty($offered, 'premise: the select offers at least one move');

        foreach ($offered as $word) {
            $order = $this->orderAt(0, 'KB-' . $word);

            $this->actingAs($this->merchant)
                ->post(route('order.status.change', $order->id), [$field => $word]);

            $this->assertArrayHasKey($word, self::WRITES,
                "the board offers '$word', which the writer has no branch for");
            $this->assertSame(self::WRITES[$word],
                (int) Order::withoutGlobalScopes()->find($order->id)->delivered_status,
                "the board's own '$word' option, posted under the board's own field name "
                . "'$field', did not change the order");
        }
    }

    /** FAULT 3: a form caller is returned to the board, not left on a page of JSON. */
    public function test_a_form_post_is_redirected_back_instead_of_answered_with_json(): void
    {
        $order = $this->orderAt();

        $response = $this->actingAs($this->merchant)
            ->from(route('order.kanban'))
            ->post(route('order.status.change', $order->id), ['delivered' => 'confirmed']);

        $response->assertRedirect(route('order.kanban'));
        $this->assertStringNotContainsString('application/json',
            strtolower((string) $response->headers->get('content-type')),
            'the board still answers a plain form POST with raw JSON');

        // AND THE MERCHANT IS TOLD. `layouts/app.blade.php:768` renders Session::get('success')
        // through show_toastr, so this key is the one the chrome actually reads -- a redirect
        // that carried the message under any other name would be a silent success.
        $response->assertSessionHas('success');
    }

    // =============================================================================================
    //  NEGATIVE CONTROL — the detail page's contract is byte-identical
    // =============================================================================================

    /**
     * THE ONE THAT PROTECTS THE SHIPPED CLIENT. order/view.blade.php:853 calls this route by AJAX
     * and reads `data.status` and `data.order_status` off the JSON. If the redirect above leaked
     * into that path, the detail page's status control would break everywhere.
     */
    public function test_the_detail_pages_ajax_contract_is_unchanged(): void
    {
        $order = $this->orderAt();

        $response = $this->actingAs($this->merchant)
            ->postJson(route('order.status.change', $order->id), ['delivered' => 'confirmed']);

        $response->assertOk()
            ->assertJson(['status' => 'success', 'order_status' => 'Confirmed']);
    }

    /**
     * THE OTHER LIMB, and the one the shipped client actually uses.
     *
     * `expectsJson()` is `(ajax() && !pjax() && acceptsAnyContentType()) || wantsJson()`.
     * `postJson` above satisfies the SECOND limb (`Accept: application/json`). jQuery's `$.ajax`
     * with no `dataType` — which is what order/view.blade.php:858 is — satisfies the FIRST:
     * `X-Requested-With: XMLHttpRequest` plus an accept-anything Accept. Without this case, a
     * later narrowing to `if ($request->wantsJson())` would keep the test above green and break
     * the detail page for every merchant.
     *
     * Both headers are needed to reproduce it, and that is not obvious: `Request::create()`
     * injects a browser-like Accept, so with X-Requested-With alone `acceptsAnyContentType()` is
     * false and the route redirects. Measured.
     */
    public function test_the_jquery_shaped_call_also_gets_json(): void
    {
        $order = $this->orderAt();

        $this->actingAs($this->merchant)
            ->post(route('order.status.change', $order->id), ['delivered' => 'confirmed'],
                ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => '*/*'])
            ->assertOk()
            ->assertJson(['status' => 'success', 'order_status' => 'Confirmed']);
    }

    // =============================================================================================
    //  NEGATIVE CONTROLS — nothing about authorization, tenancy or money moved
    // =============================================================================================

    public function test_a_principal_without_the_order_permission_cannot_move_an_order(): void
    {
        $order = $this->orderAt();

        $outsider = $this->makeStaff($this->merchant, ['name' => 'No Orders']);
        $outsider->current_store = $this->store->id;
        $outsider->save();
        $outsider = User::find($outsider->id);

        // The RESPONSE as well as the database: asserting only that nothing changed would also
        // pass if the endpoint 500'd for everyone, which is the classic shape of a control that
        // proves nothing. The positive controls in this class show the endpoint works.
        $this->actingAs($outsider)
            ->post(route('order.status.change', $order->id), ['delivered' => 'confirmed'])
            ->assertForbidden();

        $this->assertSame(0, (int) Order::withoutGlobalScopes()->find($order->id)->delivered_status,
            'a principal without Manage Order moved an order');
    }

    public function test_another_tenants_order_cannot_be_moved(): void
    {
        $other = $this->makeMerchant(['email' => 'other-' . uniqid() . '@example.test']);
        $otherStore = $this->makeStore($other, ['default_language' => 'en']);
        $victim = $this->makeOrder($otherStore, [
            'product_order_id' => 'VICTIM', 'delivered_status' => 0, 'payment_status' => 'Unpaid',
        ]);

        $this->actingAs($this->merchant)
            ->post(route('order.status.change', $victim->id), ['delivered' => 'confirmed'])
            ->assertForbidden();

        $this->assertSame(0, (int) Order::withoutGlobalScopes()->find($victim->id)->delivered_status,
            'a merchant moved another store\'s order');
    }

    /**
     * THE OWNER'S COD DECISION OF 2026-10-24, pinned here because this slice edits this handler:
     * *"Delivered must never, by itself, mark COD as Paid or credit a merchant wallet."*
     */
    public function test_moving_an_order_to_delivered_still_does_not_touch_payment_status(): void
    {
        $order = $this->orderAt();

        $this->actingAs($this->merchant)
            ->post(route('order.status.change', $order->id), ['delivered' => 'delivered']);

        $after = Order::withoutGlobalScopes()->find($order->id);
        $this->assertSame(1, (int) $after->delivered_status, 'premise: the move happened');
        $this->assertSame('Unpaid', $after->payment_status,
            'marking an order delivered changed its payment status, which the owner\'s decision '
            . 'of 2026-10-24 forbids');
    }
}
