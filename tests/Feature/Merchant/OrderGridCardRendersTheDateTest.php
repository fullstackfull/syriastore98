<?php

namespace Tests\Feature\Merchant;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE ORDER GRID CARD SHOWS A REAL DATE, AND AN ABSENT ONE AS A DASH.  P2.5-DESIGN-C
 * =================================================================================================
 *
 * THE POSITIVE CONTROL FOR `OrderGridCardContentTest`, and it is load-bearing rather than tidy.
 * That file drives `dateOrDash()` with five absent-date inputs and asserts a bare `-` for each --
 * which a helper returning `-` for EVERYTHING would also satisfy. This is the case that says the
 * card really renders a date.
 *
 * IT IS A FEATURE TEST BECAUSE IT HAS TO BE. `Utility::dateFormat()` resolves the auth factory and
 * then reads the `settings` table for the merchant's date format -- measured, in that order, as
 * two separate failures while trying to keep this beside its siblings in `tests/Unit/Ui`. Driving
 * the real route is the stronger place for it anyway: it proves the CARD shows the date, not
 * merely that a helper can format one.
 *
 * THE DEFECT IT PINS. The card showed NO date at all while both of its siblings did -- the list has
 * a `Date` column and the board card carries one -- so every card read `<customer> / Price :
 * <amount> / #<ref>` and nothing else. The obvious repair, `Utility::dateFormat($order->order_date)`
 * in the view, would have reintroduced **F19**: the epoch printed for a missing date, already found
 * and fixed once on the list. Hence `dateOrDash()`, and hence the absent-date case below.
 */
class OrderGridCardRendersTheDateTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->merchant, $this->store] = $this->makeTenant();
        $this->grantPermission($this->merchant, 'Manage Order');
        $this->merchant = User::find($this->merchant->id);
    }

    private function gridHtml(): string
    {
        $response = $this->actingAs($this->merchant)->get('/order-grid');

        $this->assertSame(200, $response->getStatusCode(), 'premise: the grid route answers');

        return (string) $response->getContent();
    }

    /** A recorded date reaches the card. */
    public function test_a_recorded_order_date_is_rendered_on_the_card(): void
    {
        $order = $this->makeOrder($this->store, ['order_date' => '2026-03-17']);

        $html = $this->gridHtml();

        $this->assertStringContainsString(
            $order->product_order_id,
            $html,
            'premise: the order under test is on the page'
        );
        $this->assertStringContainsString(
            'ss-order-card__date',
            $html,
            'the card must carry a date block at all'
        );
        $this->assertStringContainsString(
            '2026',
            $html,
            'the recorded order date must appear on the card. It showed no date at all before '
            . 'P2.5-DESIGN-C, while the list and the board both did.'
        );
    }

    /**
     * AND AN ABSENT DATE IS A DASH, NOT THE EPOCH. This is F19 on a second screen, driven through
     * the rendered card rather than through the helper, because the view is where it would come
     * back.
     */
    public function test_an_order_with_no_date_shows_a_dash_and_never_1970(): void
    {
        $order = $this->makeOrder($this->store, ['order_date' => null]);

        $html = $this->gridHtml();

        $this->assertStringContainsString(
            $order->product_order_id,
            $html,
            'premise: the dateless order is on the page'
        );
        $this->assertStringNotContainsString(
            '1970',
            $html,
            'F19: a missing order date rendered as the epoch. `dateOrDash()` exists to stop this '
            . 'and the card must go through it.'
        );
        $this->assertMatchesRegularExpression(
            '/ss-order-card__date[^>]*>.*?<span class="ss-num">\s*-\s*<\/span>/s',
            $html,
            'a missing date must render as a bare dash inside the date figure'
        );
    }

    /**
     * THE CARD'S MONEY IS A TABULAR FIGURE. Driven here as well as in the source ratchet, because
     * a class that is present in the Blade but stripped or overridden in the rendered page would
     * satisfy the source read and nothing else.
     */
    public function test_the_card_money_is_rendered_inside_a_tabular_figure(): void
    {
        $this->makeOrder($this->store, ['final_price' => 1234.56]);

        $html = $this->gridHtml();

        $this->assertStringContainsString('ss-order-card__money', $html);
        $this->assertMatchesRegularExpression(
            '/ss-order-card__money[^>]*>.*?<span class="ss-num">[^<]*1,234\.56/s',
            $html,
            'the price must render inside an `.ss-num` figure, with its cents. Both halves have '
            . 'been defects on this screen: the figure was a bare text run, and the separator '
            . 'default rendered 1,234.56 as `1,234,56` until D-42.3.'
        );
    }
}
