<?php

namespace Tests\Unit\Ui;

use App\Support\DsBadge;
use App\Support\OrderStatusTone;
use PHPUnit\Framework\TestCase;

/**
 * P2.5-GRID-STATUS — the order grid card told the merchant the status with COLOUR ALONE (WCAG 1.4.1)
 * =================================================================================================
 *
 * `resources/views/order/grid.blade.php`'s `@php` block set `$btn_class` to
 * `'fa fa-check text-danger'` for Cancel and `'fa fa-check text-primary'` for Confirmed -- THE SAME
 * Font Awesome glyph, separated by nothing but a colour utility -- and the card's header rendered
 * that glyph as the ONLY place the status appeared. No visible text named it anywhere on the card,
 * and the stylesheets give `.order-card .user-image` only geometry (a 45x45 flex box and
 * `font-size: 22px`): no `::before`, no label.
 *
 * MEASURED IN THE RUNNING ADMIN (dark + Arabic, which is how the dev fixture is served), the
 * Confirmed glyph was `#335DA8` on the host's `#292A33` card = **2.22:1**, so on that surface it
 * also failed WCAG 1.4.11's 3:1 for a non-text graphic. And on the `theme-5` preset the merchant's
 * `--theme-color` is `#b9406b`, hue 338.7deg, which is 5.5 DEGREES from `text-danger`'s 344.2deg:
 * Cancel and Confirmed are then not merely colour-dependent, they are the same colour.
 *
 * THE LABEL HAD TO BE FIXED BEFORE IT COULD BE SHOWN. The `@php` chain had NO branch for
 * `delivered_status` 7 (Partially Paid) or 8 (Pre Order), and none for a bare 3, so all three fell
 * through to the initialiser and were labelled **"Pending"**. While the label was a tooltip nobody
 * triggered, that was invisible; a badge makes it visible, so showing the label without adding the
 * branches would have converted a hidden wrong label into visible wrong text. This file measures
 * the chain by EVALUATING IT -- the view's own source, with `__()` flattened -- rather than reading
 * it, because the mapping is the whole risk of this repair.
 *
 * THE THREE RETURN SUB-STATES KEEP THREE DISTINCT LABELS. `OrderStatusTone` deliberately holds no
 * labels, and the board's `$ssLabels` has one word ("Return") for status 3. Sourcing the label from
 * either would collapse "Return request processing", "Return" and "Return cancelled" into one --
 * and since the Approve/Cancel buttons render only for `delivered_status == 3 && return_status == 1`,
 * the merchant would see a card labelled "Return" with two approve buttons and no way to tell it
 * from a settled return. One TONE (danger) for all three is the deliberate part; one LABEL is not.
 */
class OrderGridStatusLabelTest extends TestCase
{
    private const GRID = 'resources/views/order/grid.blade.php';

    private const SHEETS = ['public/css/custom.css', 'public/css/rtl-custom.css'];

    /** Every label the chain can produce, with the status that must produce it. */
    private const EXPECTED = [
        '0,0' => 'Pending',
        '1,0' => 'Delivered',
        '2,0' => 'Cancel',
        '3,1' => 'Return request processing',
        '3,2' => 'Return',
        '3,3' => 'Return cancelled',
        '4,0' => 'Comfirmed',
        '5,0' => 'Picked Up',
        '6,0' => 'Shipped',
        '7,0' => 'Partially Paid',
        '8,0' => 'Pre Order',
        // The fall-through cases that used to read "Pending".
        '3,0' => 'Return',
        '3,9' => 'Return',
    ];

    private function path(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }

    private function grid(): string
    {
        return (string) file_get_contents($this->path(self::GRID));
    }

    /** The card header, which is where the status is rendered. */
    private function header(): string
    {
        $html = $this->grid();

        $start = strpos($html, '<div class="card-header');
        $end = strpos($html, '<div class="card-body');
        $this->assertNotFalse($start, 'premise: the card still has a header');
        $this->assertNotFalse($end, 'premise: the card still has a body');

        return substr($html, $start, $end - $start);
    }

    /**
     * RUNS the view's own status chain for one order, and returns the $title it produces.
     *
     * Reading the chain would let a transcription error through; the labels are the whole risk of
     * making them visible, so the chain is executed. `__('X')` is flattened to `'X'` -- the
     * catalogue is asserted separately, in both languages.
     */
    private function labelFor(int $delivered, int $return = 0): string
    {
        $html = $this->grid();

        $open = strpos($html, '@php');
        $close = strpos($html, '@endphp');
        $this->assertNotFalse($open, 'premise: the view still has a @php status block');
        $this->assertNotFalse($close);

        $src = substr($html, $open + 4, $close - ($open + 4));

        // Comments first: the block carries a long banner, and it names class constants whose
        // `::` the guard below would otherwise read as executable complexity.
        $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);
        $src = (string) preg_replace('#^\s*//.*$#m', '', $src);

        // Flatten __('...') to the bare key, and refuse anything else that would need a container.
        $src = (string) preg_replace("/__\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*\)/", "'$1'", $src);
        $this->assertStringNotContainsString('__(', $src,
            'the status chain calls __() in a form this test cannot flatten, so it can no longer '
            . 'be evaluated and the mapping would go unmeasured');
        $this->assertDoesNotMatchRegularExpression('/\b(?:new|function|static|::)\b/', $src,
            'the status chain now does more than assign labels; re-read it before trusting this');

        $order = (object) ['delivered_status' => $delivered, 'return_status' => $return];
        $title = null;
        $btn_class = null;

        eval($src);

        $this->assertIsString($title, "the status chain produced no label for "
            . "delivered_status=$delivered, return_status=$return");

        return $title;
    }

    // =============================================================================================
    //  THE LABEL IS VISIBLE, AS TEXT, ON THE CARD
    // =============================================================================================

    public function test_the_card_header_renders_the_status_as_visible_text(): void
    {
        $header = $this->header();

        $this->assertStringContainsString(
            'OrderStatusTone::badge((int) $order->delivered_status, $title)', $header,
            'the card header does not render the status as a labelled design-system badge, so the '
            . 'status is still conveyed by the hue of an `fa fa-check` glyph alone. Cancel and '
            . 'Confirmed use the SAME glyph and differ only by `text-danger` vs `text-primary`.');

        $this->assertStringNotContainsString('{{ $btn_class }}', $header,
            'the colour-only status glyph is still rendered in the card header');
    }

    public function test_the_status_is_no_longer_a_focusable_control_with_no_purpose(): void
    {
        $header = $this->header();

        $this->assertStringNotContainsString('dropdown-item', $header,
            'the inert `<a href="#" class="dropdown-item" data-value="confirmed">` is still in the '
            . 'card header. Nothing binds a click handler to `.dropdown-item` in the admin and '
            . 'there is no dropdown around it, so it was a status label wearing a control\'s '
            . 'clothes -- the same defect the order LIST had and fixed by using spans.');

        $this->assertStringNotContainsString('data-value="confirmed"', $header);
    }

    public function test_the_badge_markup_is_a_span_and_carries_its_own_label(): void
    {
        $html = OrderStatusTone::badge(4, 'Comfirmed');

        $this->assertStringStartsWith('<span class="ss-badge', $html);
        $this->assertStringContainsString('Comfirmed', $html, 'the badge carries no visible label');
        $this->assertStringContainsString('ss-badge__dot', $html,
            'the dot is the second, non-colour channel beside the text');
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString('<button', $html);
    }

    /** The label is escaped by DsBadge, which is why `{!! !!}` is correct and safe here. */
    public function test_the_label_is_escaped_so_the_unescaped_echo_is_safe(): void
    {
        $this->assertStringContainsString('&lt;b&gt;', DsBadge::render('info', '<b>x</b>'));
    }

    /**
     * Line 87 of the view opens `<span class="text-dark text-md">` and never closes it before the
     * enclosing `</div>`, so anything placed after it lands INSIDE an auto-closed `.text-dark`
     * span -- where P2.5-A-05's `html[data-ss-theme="dark"] body .text-dark { color: ... }`
     * would repaint it. The badge must be a SIBLING of those spans, before `.user-content`.
     */
    public function test_the_badge_is_not_inside_the_unclosed_text_dark_span(): void
    {
        $header = $this->header();

        $badge = strpos($header, 'OrderStatusTone::badge');
        $content = strpos($header, 'user-content');
        $firstTextDark = strpos($header, 'text-dark');

        $this->assertNotFalse($badge);
        $this->assertNotFalse($content);
        $this->assertNotFalse($firstTextDark);

        $this->assertLessThan($content, $badge,
            'the badge is rendered after `.user-content`, not as the first child of '
            . '`.user-img-wrp`');
        $this->assertLessThan($firstTextDark, $badge,
            'the badge is rendered after a `.text-dark` span opens. That span is never closed '
            . 'before the row\'s `</div>`, so the badge would inherit the dark-mode `.text-dark` '
            . 'override and a light-mode-only check would not notice.');
    }

    // =============================================================================================
    //  THE LABELS THEMSELVES — measured by running the chain
    // =============================================================================================

    public function test_every_status_the_host_can_store_gets_its_own_correct_label(): void
    {
        foreach (self::EXPECTED as $key => $expected) {
            [$delivered, $return] = array_map('intval', explode(',', $key));

            $this->assertSame($expected, $this->labelFor($delivered, $return), sprintf(
                'delivered_status=%d, return_status=%d is labelled wrongly. Before this repair '
                . '7 (Partially Paid), 8 (Pre Order) and a bare 3 all fell through the chain and '
                . 'read "Pending" -- which only mattered once the label became visible.',
                $delivered, $return));
        }
    }

    public function test_the_three_return_sub_states_keep_three_distinct_labels(): void
    {
        $labels = [$this->labelFor(3, 1), $this->labelFor(3, 2), $this->labelFor(3, 3)];

        $this->assertCount(3, array_unique($labels),
            'the three return sub-states no longer have three distinct labels: '
            . implode(' / ', $labels) . '. The Approve/Cancel buttons render only for '
            . 'return_status == 1, so collapsing them leaves a card labelled "Return" with two '
            . 'approve buttons and no way to tell it from a settled return.');

        // The single shared TONE is deliberate, and is the same table the list and board use.
        $this->assertSame('danger', OrderStatusTone::for(3));
    }

    public function test_no_status_falls_through_to_the_initialiser_any_more(): void
    {
        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $status) {
            $this->assertNotSame('Pending', $this->labelFor($status),
                "delivered_status=$status is still labelled Pending by fall-through");
        }
    }

    public function test_every_label_exists_in_both_catalogues(): void
    {
        $en = json_decode((string) file_get_contents($this->path('resources/lang/en.json')), true);
        $ar = json_decode((string) file_get_contents($this->path('resources/lang/ar.json')), true);

        $this->assertGreaterThan(3000, count($en), 'premise: en.json parsed');
        $this->assertGreaterThan(3000, count($ar), 'premise: ar.json parsed');

        foreach (array_unique(array_values(self::EXPECTED)) as $label) {
            $this->assertArrayHasKey($label, $en, "en.json has no `$label`");
            $this->assertArrayHasKey($label, $ar, "ar.json has no `$label`, so making the label "
                . 'VISIBLE would show an Arabic merchant readable English where every other '
                . 'status is Arabic');
            $this->assertNotSame('', trim((string) $ar[$label]), "ar.json's `$label` is empty");
        }
    }

    /**
     * `Comfirmed` is the host's own misspelling and `tests/Feature/ApiContract/OrderStatusTextTest`
     * pins it for the API. It asserts the API and not this view, so a view-only rename would NOT
     * fail it -- which is worse than failing: the web would read "Confirmed" on the grid, the board
     * and the list while the API still said "Comfirmed". Left alone, deliberately.
     */
    public function test_the_hosts_own_misspelling_is_left_alone(): void
    {
        $this->assertSame('Comfirmed', $this->labelFor(4),
            'status 4 was re-spelled in this view only, so the web and the API now disagree');
    }

    // =============================================================================================
    //  TONE — neutral must stay a BARE .ss-badge, because no .ss-badge--neutral rule exists
    // =============================================================================================

    public function test_the_payment_and_order_type_statuses_render_the_bare_neutral_badge(): void
    {
        $ds = (string) file_get_contents($this->path('public/css/store-syria-ds.css'));
        $this->assertStringNotContainsString('.ss-badge--neutral', $ds,
            'premise: there is still no `.ss-badge--neutral` rule');

        foreach ([7, 8] as $status) {
            $this->assertSame('neutral', OrderStatusTone::for($status));
            $this->assertStringNotContainsString('ss-badge--neutral',
                OrderStatusTone::badge($status, 'x'),
                'an unstyled `ss-badge--neutral` class is emitted; the base `.ss-badge` already '
                . 'paints the neutral tone');
        }
    }

    // =============================================================================================
    //  NEGATIVE CONTROLS — what this repair must NOT have taken away
    // =============================================================================================

    public function test_the_return_request_controls_are_untouched_and_still_functional(): void
    {
        $grid = $this->grid();

        foreach (['class="btn btn-sm btn-primary return_request me-2"',
                  'class="btn btn-sm btn-danger return_request me-2"',
                  'data-status="2"', 'data-status="3"', "route('order.return.request')",
                  "show_toastr", 'xhr.status === 403', 'xhr.status === 419'] as $contract) {
            $this->assertStringContainsString($contract, $grid,
                "`$contract` is gone. The return controls ARE bound (unlike the status anchor), "
                . 'and the error handler is the P1-INV-15 repair.');
        }

        $this->assertSame(2, substr_count($grid, 'href="#" class="btn btn-sm btn-'),
            'the two return controls lost their href="#"; those are functional, only the inert '
            . 'status anchor was meant to go');
    }

    public function test_the_row_actions_from_the_previous_slice_are_still_named(): void
    {
        $grid = $this->grid();

        $this->assertSame(5, preg_match_all('/\saria-label="\{\{ __\(\'(?:Approve the return|'
            . 'Reject the return|Show order|Edit order|Delete order)/', $grid, $m),
            'the five row-action aria-labels added by the previous slice in this chain are no '
            . 'longer all present');
    }

    /**
     * `.order-card .user-image` and its two descendant rules now match nothing. They are LEFT in
     * place: deleting them is a stylesheet change, and a byte-asymmetric deletion across the
     * LTR/RTL pair is a failure mode this project has already paid for. If they are ever removed,
     * both sheets must lose them together -- which this asserts.
     */
    public function test_the_two_stylesheets_still_agree_about_the_now_dead_rules(): void
    {
        $blocks = [];

        foreach (self::SHEETS as $sheet) {
            $css = (string) file_get_contents($this->path($sheet));

            preg_match_all('/\.order-card \.user-image[^{]*\{[^}]*\}/s', $css, $m);
            $blocks[$sheet] = $m[0];
        }

        $this->assertSame($blocks[self::SHEETS[0]], $blocks[self::SHEETS[1]],
            'custom.css and rtl-custom.css have drifted on the `.order-card .user-image` rules. '
            . 'rtl-custom.css is loaded INSTEAD of custom.css for an Arabic merchant, so a '
            . 'one-sheet deletion reaches only half of them.');
    }

    public function test_the_dark_icon_block_from_the_first_slice_survives(): void
    {
        foreach (self::SHEETS as $sheet) {
            $this->assertStringContainsString('P2.5-GRID-ICONS',
                (string) file_get_contents($this->path($sheet)),
                "$sheet lost the P2.5-GRID-ICONS block, which keeps the Show and Edit icons off "
                . '1.47:1 in dark mode');
        }
    }
}
