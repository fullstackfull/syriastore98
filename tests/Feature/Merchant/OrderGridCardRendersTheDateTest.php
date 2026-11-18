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
 *
 * EVERY ASSERTION HERE IS ABOUT ONE ORDER'S CARD, NOT ABOUT THE PAGE -- and both halves of that were
 * defects in this file until 2026-11-18. The page is ~73,000 characters of which a card is a few
 * hundred, so a search of the whole page answers for the chrome:
 *
 *   - `assertStringContainsString('2026', $page)` was this file's whole proof that a recorded date
 *     reaches the card. MEASURED: a page whose only order has NO date still contains `2026` -- the
 *     footer prints the year. With `dateOrDash()` mutated to return `-` for everything (a card that
 *     never shows a date, the exact helper the paragraph above says this file exists to catch) all
 *     three tests still passed. The positive control controlled nothing.
 *   - `assertStringNotContainsString('1970', $page)` could fail with a perfect card. MEASURED on the
 *     same page: six stylesheet links carry `?v=<time()>` (`layouts/app.blade.php`), and a ten-digit
 *     Unix time reads `...1970...` in 600 of the 2,000,000 seconds around the day this was written
 *     -- 3 in 10,000, with a 100-second run at 1795197000; three `Crypt::encrypt()` values, ~600
 *     base64 characters, are redrawn on every render; and the page prints the store's NAME three
 *     times, so a shop called "Souq 1970" fails it every time. The shared fixture's own references
 *     were a fourth source and the only one fixed (`SecurityTestCase::uniqueFixtureRef()`): one
 *     full-suite run failed this assertion, the page was not kept, and which of the four it was is
 *     not known.
 *
 * So `cardOf()` cuts out the card that shows a given reference, and the assertions read its date
 * figure and its visible text. The first two tests put TWO orders on the page, because a helper
 * that returned the first card would otherwise satisfy a page with one; and the dateless order's
 * neighbour carries a real date in 1970, so that "this card does not say 1970" is decided on a
 * page that does. Seven mutants of the helper, the view and these assertions were run against this
 * file (session-local, not committed) and all seven fail a test here; the first of them -- the
 * dash-for-everything helper -- is the one the old file let through.
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

    /**
     * THE HEADER OF THE ONE CARD THAT SHOWS THIS ORDER: its reference, customer, status, money and
     * date -- everything the card says, and none of the action links below it (those carry the
     * encrypted ids). Fails, as a premise, when no card on the page shows the reference: a renamed
     * wrapper class must stop these tests, not make them pass against an empty string.
     */
    private function cardOf(string $html, string $reference): string
    {
        $cards = explode('<div class="card user-card order-card', $html);
        array_shift($cards);                       // the page before the first card

        foreach ($cards as $card) {
            if (str_contains($card, '<bdi>#' . $reference . '</bdi>')) {
                $header = explode('<div class="card-body', $card)[0];

                return substr($header, (int) strpos($header, '>') + 1);   // drop the rest of the wrapper's own tag
            }
        }

        $this->fail("premise: no order card on the page shows the reference {$reference} (" . count($cards) . ' card(s) found).');
    }

    /** What the card's date figure reads, as text. */
    private function dateFigure(string $card): string
    {
        $this->assertSame(
            1,
            preg_match('/ss-order-card__date[^>]*>.*?<span class="ss-num">(.*?)<\/span>/s', $card, $m),
            'the card must carry a date block with a figure in it'
        );

        return trim(html_entity_decode(strip_tags($m[1])));
    }

    /** Everything the card SAYS: its text with the markup, the attributes and the links removed. */
    private function visibleText(string $card): string
    {
        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($card))));
    }

    /** A recorded date reaches the card that belongs to it. */
    public function test_a_recorded_order_date_is_rendered_on_the_card(): void
    {
        $dated = $this->makeOrder($this->store, ['order_date' => '2026-03-17']);
        $dateless = $this->makeOrder($this->store, ['order_date' => null]);

        $html = $this->gridHtml();

        $figure = $this->dateFigure($this->cardOf($html, $dated->product_order_id));

        $this->assertNotSame('-', $figure, 'the card of an order with a recorded date shows a dash. It showed no date at all before '
            . 'P2.5-DESIGN-C, while the list and the board both did.');
        $this->assertStringContainsString('2026', $figure, "the recorded order date must appear in the card's date figure, which reads `{$figure}`.");
        $this->assertMatchesRegularExpression('/(?<!\d)17(?!\d)/', $figure, "the day of the recorded date is missing from the figure `{$figure}`.");

        // the other card on the same page is the dateless one, and it says so: the figure above was read from the RIGHT card
        $this->assertSame('-', $this->dateFigure($this->cardOf($html, $dateless->product_order_id)));
    }

    /**
     * AND AN ABSENT DATE IS A DASH, NOT THE EPOCH. This is F19 on a second screen, driven through
     * the rendered card rather than through the helper, because the view is where it would come
     * back.
     */
    public function test_an_order_with_no_date_shows_a_dash_and_never_1970(): void
    {
        $dateless = $this->makeOrder($this->store, ['order_date' => null]);

        // THE NEIGHBOUR HAS A REAL DATE IN 1970, and `dateOrDash()` prints a real date. So the PAGE says 1970, legitimately, one card
        // away -- which is what makes "this card does not" a statement about this card. Without it the two assertions below held for
        // the whole page on all but 3 seconds in 10,000, and a mutant that read the page instead of the card survived.
        $neighbour = $this->makeOrder($this->store, ['order_date' => '1970-06-15']);

        $html = $this->gridHtml();
        $card = $this->cardOf($html, $dateless->product_order_id);

        $this->assertStringContainsString(
            '1970',
            $this->dateFigure($this->cardOf($html, $neighbour->product_order_id)),
            'premise: the neighbouring card shows its real 1970 date, so the page says 1970.'
        );

        $this->assertSame('-', $this->dateFigure($card), 'a missing date must render as a bare dash inside the date figure');
        $this->assertStringNotContainsString(
            '1970',
            $this->visibleText($card),
            'F19: a missing order date rendered as the epoch. `dateOrDash()` exists to stop this '
            . 'and the card must go through it.'
        );
    }

    /**
     * `1970` SOMEWHERE ELSE ON THE PAGE IS NOT THE CARD SHOWING THE EPOCH. The store's name is the
     * one stray source a test can produce on demand (the clock and the encrypted ids are the other
     * two), and it is a real one: the page prints it three times. The whole-page assertion this
     * file used to make fails on this page with a correct card -- the premise below is that
     * assertion, inverted.
     */
    public function test_the_year_1970_elsewhere_on_the_page_is_not_the_card_showing_the_epoch(): void
    {
        $this->store->name = 'Souq 1970';
        $this->store->save();

        $dateless = $this->makeOrder($this->store, ['order_date' => null]);

        $html = $this->gridHtml();

        $this->assertStringContainsString('Souq 1970', $html, 'premise: the page prints the store name, so the PAGE says 1970.');

        $card = $this->cardOf($html, $dateless->product_order_id);

        $this->assertSame('-', $this->dateFigure($card));
        $this->assertStringNotContainsString('1970', $this->visibleText($card), 'the card of a dateless order says 1970.');
    }

    /**
     * THE SCOPING DOES NOT HIDE THE THING IT HUNTS. A hand-built page, because `dateOrDash()` turns
     * every epoch input into a dash and so the real route cannot be made to print one: card A shows
     * the epoch and is caught; card B is correct and passes although the page says `1970` in four
     * other places -- a stylesheet version, an encrypted-looking link, card A, and the footer.
     */
    public function test_a_card_that_shows_the_epoch_is_still_caught_and_its_neighbour_is_not_blamed(): void
    {
        $card = fn (string $ref, string $date, string $link) => '<div class="card user-card order-card border mb-0">'
            . '<div class="card-header p-3 border-bottom h-100"><a class="ss-card__title ss-order-card__ref" dir="ltr" href="/order/' . $link . '" title="' . $ref . '"><bdi>#' . $ref . '</bdi></a>'
            . '<span class="ss-card__meta ss-order-card__date text-end"><span class="visually-hidden">Date</span><span class="ss-num">' . $date . '</span></span></div>'
            . '<div class="card-body p-3"><a href="/order/' . $link . '/edit">Edit</a></div></div>';

        $page = '<link rel="stylesheet" href="/css/custom.css?v=1795031970">'
            . $card('ORD-A', 'Jan 1, 1970', 'eyJpdiI6abc')
            . $card('ORD-B', '-', 'eyJpdiI61970xyz')
            . '<footer>&copy; 1970</footer>';

        $this->assertSame(5, substr_count($page, '1970'), 'premise: the page says 1970 five times, one of them on card A.');

        $this->assertSame('Jan 1, 1970', $this->dateFigure($this->cardOf($page, 'ORD-A')));
        $this->assertStringContainsString('1970', $this->visibleText($this->cardOf($page, 'ORD-A')), 'a card that shows the epoch must be caught.');

        $this->assertSame('-', $this->dateFigure($this->cardOf($page, 'ORD-B')));
        $this->assertStringNotContainsString('1970', $this->visibleText($this->cardOf($page, 'ORD-B')), 'a correct card was blamed for text outside it.');
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
