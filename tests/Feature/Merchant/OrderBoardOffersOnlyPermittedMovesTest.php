<?php

namespace Tests\Feature\Merchant;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Support\Orders\FulfilmentStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P4-F033 (second half) -- THE MERCHANT ORDER SURFACES OFFERED MOVES THE SERVER REFUSES.
 * =================================================================================================
 *
 * `FulfilmentStateMachine` (GAP-135) closed the writer: `Order::order_status_change()` now asks it
 * before any arm writes, so a transition out of a settled order is refused. The SERVER half is
 * correct and is not touched by this slice. The UI half was not: three surfaces went on drawing
 * controls for transitions the machine refuses, so a merchant could pick a status, submit, and be
 * answered with a refusal -- a control that exists only to be declined.
 *
 * MEASURED BEFORE ANY VIEW CHANGED, by this file's own census tests against the rendered pages:
 *
 *   ORDER BOARD (`order/kanban.blade.php` + `order/partials/kanban-card.blade.php`)
 *     delivered_status 1 Delivered  offered confirmed, pickedup, shipped   -- 3 refused
 *     delivered_status 2 Cancel     offered confirmed, pickedup, shipped, delivered -- 4 refused
 *     delivered_status 3 Return     offered confirmed, pickedup, shipped, delivered -- 4 refused
 *                                                                           ========
 *                                                                           11 refused offers
 *
 *   ORDER DETAIL (`order/view.blade.php`)
 *     delivered_status 4 Confirmed      offered cancel -- refused
 *     delivered_status 5 Picked Up      offered cancel -- refused
 *     delivered_status 7 Partially Paid offered cancel -- refused
 *     delivered_status 8 Pre Order      offered cancel -- refused
 *                                              ========
 *                                              4 refused offers
 *
 * The board's rule was `reject the move whose LABEL equals the current status's label`, which is
 * why a cancelled or returned order -- neither of which names any move -- kept all four, and why a
 * delivered one lost only `delivered`. The detail page's rule was five hand-written lists of
 * `delivered_status` integers, one per word, and the `cancel` list said `0, 4, 5, 7, 8` while the
 * machine permits `cancel` from Pending ALONE.
 *
 * =================================================================================================
 * WHAT THIS FILE ASSERTS, AND WHY IT IS AN INTERSECTION RATHER THAN A COPY
 * =================================================================================================
 *
 * The property pinned here is ONE-DIRECTIONAL: **nothing is offered that the machine refuses.** It
 * is deliberately NOT "everything the machine permits is offered", and that is not laziness.
 * `FulfilmentStateMachine`'s own docblock records that it is NOT a full workflow restriction --
 * "Backwards moves AMONG the in-flight states ... stay permitted, because a merchant correcting a
 * mis-click is a business-rule question" -- so its matrix is broader than either surface's
 * affordances on purpose:
 *
 *   - the matrix permits `return` out of Confirmed, Picked Up and Shipped; the detail page offers
 *     Return Order from Delivered only, and widening that would put pre-delivery orders into the
 *     return/refund workflow, which is a material business-rule change (CLAUDE.md section 2
 *     escalate set);
 *   - the matrix permits `confirmed` out of Picked Up and Shipped; neither surface offers a
 *     backwards step, and adding one is a product decision;
 *   - the board deliberately carries neither `cancel` nor `return` at all -- both are irreversible
 *     and money-bearing and belong on the detail page, where the merchant sees the whole order
 *     (pinned by `OrderKanbanTest::test_the_move_control_offers_only_transitions_the_existing_writer_accepts`).
 *
 * So each surface keeps its own, narrower intent and every candidate is passed through
 * `FulfilmentStateMachine::permits()` in ONE place per surface. Drift can then only ever NARROW
 * the offer, never widen it past the server.
 */
class OrderBoardOffersOnlyPermittedMovesTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * Every value `orders.delivered_status` is known to hold, plus one outside the vocabulary.
     *
     * 99 is not decoration: `OrderController::order_kanban_view()` discovers statuses from a
     * grouped COUNT precisely so a tenth value cannot vanish, and
     * `FulfilmentStateMachine::stateOf()` answers `null` for it, which the machine documents as
     * NOT GOVERNED -- "as movable as it is today". A census that only drove 0..8 could not tell a
     * fix that respects that from one that strands those rows.
     */
    private const STATUSES = [0, 1, 2, 3, 4, 5, 6, 7, 8, 99];

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
        $this->grantPermission(User::find($this->merchant->id), 'Show Order');

        $this->merchant = User::find($this->merchant->id);
    }

    // =============================================================================================
    //  FIXTURES
    // =============================================================================================

    private function orderAt(int $status, string $ref): Order
    {
        return $this->makeOrder($this->store, [
            'product_order_id' => $ref,
            'delivered_status' => $status,
            'payment_status' => 'Unpaid',
            // NOT a guest. `order/view.blade.php` gates Return Order on `is_guest == 0`, so the
            // default guest fixture would hide the one move the machine permits out of Delivered
            // and the control below would pass against an empty menu.
            'is_guest' => 0,
            'customer_id' => 0,
        ]);
    }

    /** @return array<int, Order> delivered_status => the order at it */
    private function oneOrderPerStatus(): array
    {
        $orders = [];

        foreach (self::STATUSES as $status) {
            $orders[$status] = $this->orderAt($status, 'MOVE-' . $status);
        }

        return $orders;
    }

    // =============================================================================================
    //  RENDERING
    // =============================================================================================

    private function boardHtml(): string
    {
        $response = $this->actingAs($this->merchant)->get(route('order.kanban'));

        $this->assertSame(200, $response->status(),
            'premise: the board rendered. Every "is absent" assertion below would pass against a '
            . 'redirect or an error page.');

        return (string) $response->getContent();
    }

    private function detailHtml(Order $order): string
    {
        $response = $this->actingAs($this->merchant)
            ->get('/order-view/' . Crypt::encrypt($order->id));

        $this->assertSame(200, $response->status(), sprintf(
            'premise: the detail page for the order at delivered_status %s rendered',
            $order->delivered_status
        ));

        return (string) $response->getContent();
    }

    // =============================================================================================
    //  PARSING THE RENDERED SURFACES -- SCOPED TO THE CONTROL, NEVER THE PAGE
    // =============================================================================================

    /**
     * Strip HTML comments before parsing.
     *
     * Blade's own `{{-- --}}` never reaches the response, so this is belt and braces rather than
     * the live hazard -- but CLAUDE.md records four censuses in one day satisfied or broken by
     * prose about the thing they measure, and a `<!-- -->` added to any of these three views
     * would be the fifth.
     */
    private function withoutHtmlComments(string $html): string
    {
        return (string) preg_replace('/<!--.*?-->/s', '', $html);
    }

    /**
     * The moves the BOARD offers for one order, read out of that order's own select.
     *
     * ANCHORED ON `id="ss-move-{id}"`. Not "the first select on the page" -- that is the filter
     * bar, whose options are the numeric `delivered_status` values -- and not the whole board
     * either, because nine cards in seven columns render nine selects and a page-wide match would
     * answer "does any card offer this" for every status at once.
     *
     * @return list<string>|null null when this card draws no move control at all
     */
    private function boardOffersFor(string $html, Order $order): ?array
    {
        $html = $this->withoutHtmlComments($html);

        if (preg_match('/<select[^>]*id="ss-move-' . $order->id . '"[^>]*>(.*?)<\/select>/s', $html, $select) !== 1) {
            return null;
        }

        preg_match_all('/<option value="([^"]*)"/', $select[1], $options);

        // Drop the empty `Move to…` placeholder, which is a disabled prompt and not a move.
        return array_values(array_filter($options[1], fn ($value) => $value !== ''));
    }

    /**
     * The moves the DETAIL page offers, read out of the status dropdown alone.
     *
     * SCOPED TO THE MENU. The page is a thousand lines and carries a second `.order_status`
     * reference in its jQuery block; a page-wide search for `data-value` would answer a different
     * question. The menu holds only a heading and anchors -- no nested `<div>` -- so the first
     * `</div>` after its opening tag is its own, and `assertDetailMenuLooksLikeTheMenu()` refuses
     * a capture that does not.
     *
     * @return list<string>|null null when the page draws no status menu at all
     */
    private function detailOffersIn(string $html): ?array
    {
        $html = $this->withoutHtmlComments($html);

        if (preg_match('/<div class="dropdown-menu ss-menu".*?<\/div>/s', $html, $menu) !== 1) {
            return null;
        }

        $this->assertStringContainsString('dropdown-header', $menu[0],
            'premise: the captured block is the status menu, not some other dropdown');
        $this->assertStringNotContainsString('<div', substr($menu[0], 5),
            'premise: the status menu holds no nested <div>, so this capture is the whole menu '
            . 'and not a fragment of it -- if a <div> is added here, scope this regex properly '
            . 'rather than deleting this premise');

        preg_match_all('/data-value="([^"]*)"/', $menu[0], $values);

        return array_values(array_filter($values[1], fn ($value) => $value !== ''));
    }

    // =============================================================================================
    //  PREMISES -- the census is measuring a real control, and the machine really refuses things
    // =============================================================================================

    /**
     * THE MACHINE REFUSES SOMETHING. Without this, every assertion in this file is satisfied by a
     * machine that permits everything, and the whole suite would be green against the unfixed
     * writer.
     */
    public function test_premise_the_state_machine_refuses_the_transitions_this_file_is_about(): void
    {
        $this->assertFalse(FulfilmentStateMachine::permits(FulfilmentStateMachine::STATE_CANCELLED, 'delivered'),
            'premise: a cancelled order cannot be marched into Delivered');
        $this->assertFalse(FulfilmentStateMachine::permits(FulfilmentStateMachine::STATE_RETURN, 'confirmed'),
            'premise: a returned order cannot be re-confirmed');
        $this->assertFalse(FulfilmentStateMachine::permits(FulfilmentStateMachine::STATE_DELIVERED, 'shipped'),
            'premise: a delivered order cannot be un-delivered');
        $this->assertFalse(FulfilmentStateMachine::permits(FulfilmentStateMachine::STATE_CONFIRMED, 'cancel'),
            'premise: cancel is refused out of Confirmed');
        $this->assertNull(FulfilmentStateMachine::stateOf(99),
            'premise: 99 is outside the vocabulary, so the machine does not govern it');
    }

    /** THE BOARD DRAWS A MOVE CONTROL AT ALL, so "offers nothing refused" is not vacuous. */
    public function test_premise_the_board_draws_a_move_control_with_moves_in_it(): void
    {
        $order = $this->orderAt(0, 'PREMISE-BOARD');

        $offered = $this->boardOffersFor($this->boardHtml(), $order);

        $this->assertNotNull($offered, 'premise: the pending card carries a move select');
        $this->assertNotEmpty($offered, 'premise: that select offers at least one move');
    }

    /** AND SO DOES THE DETAIL PAGE. */
    public function test_premise_the_detail_page_draws_a_status_menu_with_moves_in_it(): void
    {
        $offered = $this->detailOffersIn($this->detailHtml($this->orderAt(0, 'PREMISE-DETAIL')));

        $this->assertNotNull($offered, 'premise: the pending order\'s detail page carries a status menu');
        $this->assertNotEmpty($offered, 'premise: that menu offers at least one move');
    }

    // =============================================================================================
    //  THE CENSUS -- the whole before-state in one failure, per surface
    // =============================================================================================

    /**
     * NOT ONE ASSERTION PER STATUS. The point of collecting every violation and failing once is
     * that the failure message IS the measurement: a per-status assertion stops at the first
     * refused offer and hides the other ten.
     */
    public function test_the_order_board_offers_no_move_the_state_machine_refuses(): void
    {
        $orders = $this->oneOrderPerStatus();
        $html = $this->boardHtml();

        $violations = [];
        $controlsSeen = 0;

        foreach ($orders as $status => $order) {
            /*
             * THE CARD ITSELF, FIRST. Without this the loop below silently skips any status whose
             * order never reached the board, and a census that measured three columns would read
             * as a clean sweep of nine.
             */
            $this->assertStringContainsString('#MOVE-' . $status, $html, sprintf(
                'premise: the order at delivered_status %d is on the board at all, so this census '
                . 'measured it rather than skipping it', $status
            ));

            $offered = $this->boardOffersFor($html, $order);

            if ($offered === null) {
                continue;
            }

            $controlsSeen++;

            foreach ($offered as $word) {
                $refusal = FulfilmentStateMachine::refusalFor($status, $word);

                if ($refusal !== null) {
                    $violations[] = sprintf('delivered_status %d offers "%s" -> %s', $status, $word, $refusal);
                }
            }
        }

        /*
         * SEVEN, NOT TEN, AND THE NUMBER IS THE POINT. The board carries neither `cancel` nor
         * `return`, so out of Delivered (1), Cancelled (2) and Returned (3) there is nothing it
         * may offer and those three cards carry no control. The other seven -- Pending, the three
         * in-flight states, Partially Paid, Pre Order and the ungoverned 99 -- must all still have
         * one, or this census is passing on an empty board. Calibrated to the FIXED behaviour on
         * purpose: a floor set to what the broken board happened to render could not detect a fix
         * that deleted the control everywhere.
         */
        $this->assertSame(7, $controlsSeen,
            'the number of board cards carrying a move control changed. Seven statuses have a '
            . 'permitted board move and three do not; anything else means a control was lost or '
            . 'an impossible one gained.');

        $this->assertSame([], $violations, sprintf(
            "the order board offers %d transition(s) that Order::order_status_change() refuses:\n  %s\n",
            count($violations), implode("\n  ", $violations)
        ));
    }

    public function test_the_order_detail_page_offers_no_move_the_state_machine_refuses(): void
    {
        $violations = [];
        $menusSeen = 0;

        foreach (self::STATUSES as $status) {
            $order = $this->orderAt($status, 'DETAIL-' . $status);
            $offered = $this->detailOffersIn($this->detailHtml($order));

            if ($offered === null) {
                continue;
            }

            $menusSeen++;

            foreach ($offered as $word) {
                $refusal = FulfilmentStateMachine::refusalFor($status, $word);

                if ($refusal !== null) {
                    $violations[] = sprintf('delivered_status %d offers "%s" -> %s', $status, $word, $refusal);
                }
            }
        }

        $this->assertGreaterThanOrEqual(7, $menusSeen,
            'premise: the detail page drew a status menu for at least the seven statuses it has '
            . 'always drawn one for (0, 1, 4, 5, 6, 7, 8)');

        $this->assertSame([], $violations, sprintf(
            "the order detail page offers %d transition(s) that Order::order_status_change() refuses:\n  %s\n",
            count($violations), implode("\n  ", $violations)
        ));
    }

    // =============================================================================================
    //  CONTROLS -- the fix is a narrowing, not a deletion
    // =============================================================================================

    /**
     * THE MOVES THAT REALLY ARE PERMITTED ARE STILL THERE.
     *
     * Without this, "offer nothing refused" is satisfied perfectly by offering nothing at all,
     * which is how a fix of this shape silently removes the merchant's only way to work.
     */
    public function test_the_board_still_offers_every_in_flight_move_it_always_did(): void
    {
        $orders = $this->oneOrderPerStatus();
        $html = $this->boardHtml();

        // from `delivered_status` => the moves this surface has always drawn for it and which the
        // machine permits. Read off the board before the fix and unchanged by it.
        $expected = [
            0  => ['confirmed', 'pickedup', 'shipped', 'delivered'],
            4  => ['pickedup', 'shipped', 'delivered'],
            5  => ['confirmed', 'shipped', 'delivered'],
            6  => ['confirmed', 'pickedup', 'delivered'],
            7  => ['confirmed', 'pickedup', 'shipped', 'delivered'],
            8  => ['confirmed', 'pickedup', 'shipped', 'delivered'],
            // NOT GOVERNED, so left exactly as movable as it is today.
            99 => ['confirmed', 'pickedup', 'shipped', 'delivered'],
        ];

        foreach ($expected as $status => $words) {
            $offered = $this->boardOffersFor($html, $orders[$status]);

            $this->assertNotNull($offered, "the board stopped drawing a move control at status {$status}");

            sort($offered);
            sort($words);

            $this->assertSame($words, $offered, sprintf(
                'the board\'s offer at delivered_status %d changed. A narrowing of the REFUSED '
                . 'moves must not touch the permitted ones.', $status
            ));
        }
    }

    /** A settled order's card offers NOTHING -- there is nowhere for it to go. */
    public function test_a_settled_order_gets_no_move_control_on_the_board(): void
    {
        $orders = $this->oneOrderPerStatus();
        $html = $this->boardHtml();

        foreach ([FulfilmentStateMachine::STATE_CANCELLED, FulfilmentStateMachine::STATE_RETURN] as $status) {
            $this->assertSame([], $this->boardOffersFor($html, $orders[$status]) ?? [], sprintf(
                'a card at delivered_status %d offers a move, but the machine permits none out of '
                . 'a settled order', $status
            ));
        }

        // AND THE CARD IS STILL ON THE BOARD. Hiding the order instead of the control would lose
        // it from the only screen the merchant is looking at.
        foreach ([FulfilmentStateMachine::STATE_CANCELLED, FulfilmentStateMachine::STATE_RETURN] as $status) {
            $this->assertStringContainsString('MOVE-' . $status, $html,
                'the settled order disappeared from the board rather than losing its move control');
        }
    }

    /**
     * A DELIVERED CARD OFFERS NOTHING EITHER, and that needs saying separately from the settled
     * pair: the machine permits `delivered` (a re-stamp) and `return` out of Delivered, and the
     * board carries neither -- `delivered` because re-stamping the state the order is in is not a
     * move, `return` because the board deliberately has no money-bearing control.
     */
    public function test_a_delivered_order_gets_no_move_control_on_the_board(): void
    {
        $orders = $this->oneOrderPerStatus();

        $this->assertSame([],
            $this->boardOffersFor($this->boardHtml(), $orders[FulfilmentStateMachine::STATE_DELIVERED]) ?? [],
            'a delivered card offers a move the writer refuses');
    }

    /** The board still carries no irreversible, money-bearing control for ANY status. */
    public function test_the_board_still_offers_neither_cancel_nor_return_anywhere(): void
    {
        $this->oneOrderPerStatus();
        $html = $this->withoutHtmlComments($this->boardHtml());

        $this->assertStringNotContainsString('value="cancel"', $html,
            'the board must not offer an irreversible money-bearing transition');
        $this->assertStringNotContainsString('value="return"', $html);
    }

    public function test_the_detail_page_still_offers_every_move_it_always_did_that_is_permitted(): void
    {
        // from `delivered_status` => the words this page has always offered MINUS the refused
        // ones. Read off the page before the fix; only `cancel` out of 4, 5, 7 and 8 is removed.
        $expected = [
            0  => ['cancel', 'confirmed', 'pickedup', 'shipped', 'delivered'],
            1  => ['return'],
            4  => ['pickedup', 'shipped', 'delivered'],
            5  => ['shipped', 'delivered'],
            6  => ['delivered'],
            7  => ['pickedup', 'shipped', 'delivered'],
            8  => ['confirmed', 'pickedup', 'shipped', 'delivered'],
        ];

        foreach ($expected as $status => $words) {
            $offered = $this->detailOffersIn($this->detailHtml($this->orderAt($status, 'KEEP-' . $status)));

            $this->assertNotNull($offered, "the detail page stopped drawing a status menu at status {$status}");

            sort($offered);
            sort($words);

            $this->assertSame($words, $offered, sprintf(
                'the detail page\'s offer at delivered_status %d changed beyond removing the '
                . 'transitions the machine refuses', $status
            ));
        }
    }

    /**
     * CANCEL SURVIVES WHERE IT IS PERMITTED, named on its own because it is the move this slice
     * removes four times and a fix that removed it everywhere would pass every census above.
     */
    public function test_cancel_is_still_offered_on_a_pending_order(): void
    {
        $offered = $this->detailOffersIn($this->detailHtml($this->orderAt(0, 'CANCEL-OK')));

        $this->assertContains('cancel', $offered ?? [],
            'a pending order can be cancelled and the detail page must still say so');
        $this->assertTrue(FulfilmentStateMachine::permits(0, 'cancel'),
            'premise: the machine really does permit it, so this is a control and not a copy of '
            . 'the view');
    }

    /** And Return Order survives on the one state it is offered from. */
    public function test_return_is_still_offered_on_a_delivered_order(): void
    {
        $offered = $this->detailOffersIn($this->detailHtml($this->orderAt(1, 'RETURN-OK')));

        $this->assertContains('return', $offered ?? [],
            'a delivered order can be returned and the detail page must still say so');
    }

    /**
     * THE GUEST RULE IS NOT THE MACHINE'S AND MUST SURVIVE. `order/view.blade.php` has always
     * gated Return Order on `is_guest == 0`; a rewrite that derived the menu from the machine
     * alone would quietly drop it.
     */
    public function test_a_guest_order_is_still_not_offered_a_return(): void
    {
        $order = $this->makeOrder($this->store, [
            'product_order_id' => 'GUEST-1',
            'delivered_status' => 1,
            'payment_status' => 'Unpaid',
            'is_guest' => 1,
        ]);

        $offered = $this->detailOffersIn($this->detailHtml($order)) ?? [];

        $this->assertNotContains('return', $offered,
            'a guest order was offered Return Order, which this page has never offered');
    }

    /**
     * NOTHING WAS RESTYLED, AND THAT IS MEASURED RATHER THAN ASSERTED IN PROSE.
     *
     * This slice changes WHICH controls render and nothing about how any of them looks: no new
     * class, no new colour, no new glyph, so there is no new contrast pair to measure. A claim of
     * that shape is worth exactly as much as its evidence, so the surviving rows' class lists and
     * icons are pinned here, in BOTH directions — and the labels are asserted to differ between
     * them, which is what proves the Arabic page really was rendered and not just the English one
     * again. (`OrderStatusMenuIconSemanticsTest` pins the same glyphs against the SOURCE, which
     * cannot see whether the row still reaches the page.)
     */
    public function test_the_surviving_controls_carry_exactly_the_markup_they_always_did(): void
    {
        // word => [the anchor's class list, the icon's class list]
        $expected = [
            'confirmed' => ['dropdown-item order_status ss-menu__item', 'ti ti-circle-check text-success me-1'],
            'pickedup'  => ['dropdown-item order_status ss-menu__item', 'ti ti-package text-success me-1'],
            'shipped'   => ['dropdown-item order_status ss-menu__item', 'ti ti-truck-delivery text-success me-1'],
            'delivered' => ['dropdown-item order_status ss-menu__item', 'ti ti-check text-success me-1'],
            'cancel'    => ['dropdown-item order_status ss-menu__item ss-menu__item--danger', 'ti ti-ban text-danger me-1'],
        ];

        $order = $this->orderAt(0, 'MARKUP-0');
        $labels = [];

        foreach (['en' => 'Set order status', 'ar' => null] as $language => $_) {
            $this->merchant->language = $language;
            $this->merchant->save();
            $this->merchant = User::find($this->merchant->id);

            $page = $this->withoutHtmlComments($this->detailHtml($order));

            $this->assertSame($language === 'ar', str_contains($page, 'dir="rtl"'), sprintf(
                'premise: the page rendered %s, so this measurement is about that direction',
                $language === 'ar' ? 'right-to-left' : 'left-to-right'
            ));

            preg_match('/<div class="dropdown-menu ss-menu".*?<\/div>/s', $page, $menu);
            $this->assertNotEmpty($menu, 'premise: the status menu is on the page');

            foreach ($expected as $word => [$anchorClass, $iconClass]) {
                $this->assertMatchesRegularExpression(
                    '/<a class="' . preg_quote($anchorClass, '/') . '" href="#" data-value="'
                    . preg_quote($word, '/') . '">\s*<i class="' . preg_quote($iconClass, '/')
                    . '"><\/i>\s*(\S[^<]*)/',
                    $menu[0],
                    sprintf('the "%s" row\'s own markup changed in the %s page. This slice decides '
                        . 'WHICH rows render and must leave every surviving row byte-identical.',
                        $word, $language)
                );

                preg_match('/data-value="' . preg_quote($word, '/') . '">\s*<i[^>]*><\/i>\s*([^<]+)/',
                    $menu[0], $label);
                $labels[$language][$word] = trim($label[1]);
            }
        }

        foreach (array_keys($expected) as $word) {
            $this->assertNotSame($labels['en'][$word], $labels['ar'][$word], sprintf(
                'the "%s" row reads "%s" in BOTH languages. Either the Arabic page was not really '
                . 'rendered -- in which case the RTL half of this test proves nothing -- or that '
                . 'row lost its `ar.json` entry and `__()` is falling back to English on a '
                . 'merchant-facing control, which is silent by design. Both are real; neither is '
                . 'a reason to delete this assertion.',
                $word, $labels['en'][$word]
            ));
        }
    }

    /**
     * AN ORDER WITH NOTHING TO OFFER GETS NO MENU AT ALL, rather than one holding a heading and no
     * controls.
     *
     * A MEASURED CONSEQUENCE OF THE FIX, named here so it is a decision and not a surprise. The
     * enclosing condition used to be a list of `delivered_status` integers that included 1, and
     * `is_guest != 0` then removed the only row Delivered has — so a DELIVERED GUEST order opened
     * a dropdown reading "Set order status" with nothing under it. Statuses 2, 3 and anything
     * unrecognised have never had a menu, so this makes the delivered-guest case consistent with
     * what the page already does rather than inventing a behaviour.
     *
     * AND THE PAGE STILL WORKS: the order is still readable and still says what status it is in.
     * Suppressing the control must not suppress the information.
     */
    public function test_an_order_with_nothing_to_offer_draws_no_empty_status_menu(): void
    {
        $guestDelivered = $this->makeOrder($this->store, [
            'product_order_id' => 'EMPTY-MENU',
            'delivered_status' => FulfilmentStateMachine::STATE_DELIVERED,
            'payment_status' => 'Unpaid',
            'is_guest' => 1,
        ]);

        $page = $this->withoutHtmlComments($this->detailHtml($guestDelivered));

        $this->assertNull($this->detailOffersIn($page),
            'a dropdown was drawn for an order with no permitted move. An empty "Set order status" '
            . 'menu is a control that offers nothing, which is the class of thing this slice removes.');

        $this->assertStringNotContainsString('Set order status', $page,
            'the menu heading is on the page without any menu under it');

        $this->assertStringContainsString('EMPTY-MENU', $page,
            'the order itself stopped rendering, which is a much worse defect than the one fixed');
        $this->assertStringContainsString('Delivered', $page,
            'the page no longer says what status the order is in');
    }

    // =============================================================================================
    //  BILINGUAL -- the offered SET must not depend on the language
    // =============================================================================================

    /**
     * THE SAME CENSUS IN ARABIC, and this is not ceremony.
     *
     * The board's rule WAS `reject the move whose translated LABEL equals the translated label of
     * the current status` -- a comparison between two `__()` results. That works only while both
     * sides happen to be worded identically in every catalogue: one reworded entry and the board
     * starts offering a different set of moves in Arabic than in English, which no English-only
     * test could see. Deriving the offer from the machine's WORDS removes the dependency, and this
     * test is what proves the dependency is gone rather than merely unlikely.
     */
    public function test_the_offered_set_is_the_same_in_arabic_on_both_surfaces(): void
    {
        $orders = $this->oneOrderPerStatus();

        $english = [];
        $html = $this->boardHtml();

        foreach ($orders as $status => $order) {
            // `?? []` so a card with NO control -- which after the fix is what the three settled
            // and delivered statuses get -- compares as an empty set rather than throwing.
            $english[$status] = $this->boardOffersFor($html, $order) ?? [];
        }

        $this->merchant->language = 'ar';
        $this->merchant->save();
        $this->merchant = User::find($this->merchant->id);

        $arabicHtml = $this->boardHtml();

        $this->assertStringContainsString('dir="rtl"', $arabicHtml,
            'premise: the board really did render right-to-left, so this is an RTL measurement');

        foreach ($orders as $status => $order) {
            $arabic = $this->boardOffersFor($arabicHtml, $order) ?? [];

            sort($english[$status]);
            sort($arabic);

            $this->assertSame($english[$status], $arabic, sprintf(
                'the board offers a different set of moves in Arabic than in English at '
                . 'delivered_status %d', $status
            ));
        }

        // AND NOTHING REFUSED SLIPPED IN WITH THE LANGUAGE.
        foreach ($orders as $status => $order) {
            foreach ($this->boardOffersFor($arabicHtml, $order) ?? [] as $word) {
                $this->assertNull(FulfilmentStateMachine::refusalFor($status, $word), sprintf(
                    'the Arabic board offers "%s" at delivered_status %d, which the writer refuses',
                    $word, $status
                ));
            }
        }

        // THE DETAIL PAGE, SAME QUESTION.
        foreach ([0, 1, 4, 5, 6, 7, 8] as $status) {
            $order = $this->orderAt($status, 'AR-' . $status);
            $offered = $this->detailOffersIn($this->detailHtml($order)) ?? [];

            foreach ($offered as $word) {
                $this->assertNull(FulfilmentStateMachine::refusalFor($status, $word), sprintf(
                    'the Arabic detail page offers "%s" at delivered_status %d, which the writer '
                    . 'refuses', $word, $status
                ));
            }
        }
    }

    // =============================================================================================
    //  END TO END -- every move the UI offers really is accepted by the writer
    // =============================================================================================

    /**
     * THE ONE THAT CANNOT PASS FOR THE WRONG REASON.
     *
     * Every census above compares the rendered surface against `FulfilmentStateMachine`. If the
     * machine and the writer ever disagreed, all of them would be green while the merchant still
     * met a refusal. This drives the real route instead: it takes each offered move off the
     * rendered board and POSTs it, and asserts the order actually moved.
     */
    public function test_every_move_the_board_offers_is_accepted_by_the_writer(): void
    {
        foreach (self::STATUSES as $status) {
            $probe = $this->orderAt($status, 'E2E-' . $status);
            $offered = $this->boardOffersFor($this->boardHtml(), $probe) ?? [];

            foreach ($offered as $word) {
                $subject = $this->orderAt($status, 'E2E-' . $status . '-' . $word);

                $this->actingAs($this->merchant)
                    ->postJson(route('order.status.change', $subject->id), ['delivered' => $word])
                    ->assertOk()
                    ->assertJson(['status' => 'success'], sprintf(
                        'the board offered "%s" at delivered_status %d and the writer refused it',
                        $word, $status
                    ));

                $this->assertNotSame($status,
                    (int) Order::withoutGlobalScopes()->find($subject->id)->delivered_status,
                    sprintf('the board offered "%s" at delivered_status %d and nothing moved',
                        $word, $status));
            }
        }
    }
}
