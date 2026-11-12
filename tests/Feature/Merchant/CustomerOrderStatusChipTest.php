<?php

namespace Tests\Feature\Merchant;

use App\Models\User;
use App\Support\OrderStatusTone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5 customer-orders-status-chip-contrast — the status column on a customer's order history.
 * =================================================================================================
 *
 * WHAT WAS WRONG, measured with the WCAG 2.x relative-luminance formula and alpha compositing over
 * the vendored card background (`--bs-card-bg` `#ffffff` light, `#292A33` dark). The cell rendered
 * eight `<button type="button" class="btn btn-sm btn-* btn-icon badge-same">` branches whose colour
 * came entirely from the vendored theme:
 *
 *   | chip                       | host declaration                               |  light |   dark |
 *   |----------------------------|------------------------------------------------|--------|--------|
 *   | Pending    `btn-info`      | #35abb6 on #e8f8ff (style.css:3568-3570)       | 2.52:1 | 2.52:1 |
 *   | Delivered  `btn-success`   | #5eb839 on var(--third-color), UNDEFINED under |        |        |
 *   |                            | body.custom-color -> transparent (:3552-3553)  | 2.50:1 | 5.70:1 |
 *   | Confirmed  `btn-warning`   | #f4b41a on rgba(244,180,26,.15) (:3585-3587)   | 1.68:1 | 5.64:1 |
 *   | Picked Up  `btn-secondary` | #6c757d on rgba(108,117,125,.25) (:3534-3536)  | 3.41:1 | 2.31:1 |
 *   | Shipped    `btn-soft-dark` | NO rule exists -> .btn's #293240 (:3441)       |  12.9  | 1.10:1 |
 *   | Cancel/Return `bg-danger`  | #d80027 !important on rgba(255,174,189,.41)    |        |        |
 *   |                            | !important (:9514-9517)                        | 4.26:1 | 1.03:1 |
 *   | Pre Order  `btn-dark`      | #1c232f on rgba(28,35,47,.15) (:3636-3638)     |  11.7  | 1.09:1 |
 *
 * Every one of the eight is below the 4.5:1 AA minimum (WCAG 1.4.3) in at least one theme; none
 * passes both. Those figures are recomputed from the stylesheets by
 * `test_the_host_classes_this_repair_removed_really_did_fail_aa` below, so this file does not
 * assert a ratio it has not calculated.
 *
 * SECOND DEFECT, INDEPENDENT OF COLOUR. None of the eight carried `type="submit"`, an `onclick`, a
 * `form` attribute, a `data-bs-*` action or a wrapping `<form>`; `.btn-icon` is declared in no
 * stylesheet and `badge-same` (width:200px) is bound by no script. So each row offered eight dead
 * keyboard stops and a screen reader announced "Pending : 04 Nov 2026, button" for something that
 * does nothing when activated — a false role under WCAG 4.1.2, which no stylesheet can repair at
 * any specificity.
 *
 * WHAT THE REPAIR IS. The eight buttons become one `<x-ss.status-badge>` — a `<span>`, so the dead
 * tab stops and the false role go with them — wearing the tone `App\Support\OrderStatusTone::for()`
 * already gives the order list, the kanban and the order detail page. Its tones are opaque hexes,
 * not alpha tints, so the card colour cannot enter the calculation; measured 7.10–9.81:1 across all
 * ten tone×theme pairs. Every `@if`/`@elseif` condition, every label and every date column is
 * unchanged, so `delivered_status` 7 and any unknown value still render an EMPTY cell exactly as
 * before — closing that gap is a separate, merchant-visible change.
 *
 * THE ROW'S TWO REAL CONTROLS GO WITH THEM, because the defect is scoped to the row and not to the
 * cell: the View link three lines below carried `btn-info` (2.52:1 in both themes on an icon-only
 * control, against SC 1.4.11's 3:1) and the Delete button carried `btn-danger`, declared with the
 * same two values as `.bg-danger` in all four host sheets — 1.03:1 on the dark card, on the
 * destructive control. Two shipped tests already declare those families out of bounds for row
 * controls (`CategoryAttributeRowActionsTest:119-123`, `StaffAndRoleRowActionsTest:55`), and
 * `category/action.blade.php:39-62` is the shipped shape this copies.
 *
 * WHAT IS DELIBERATELY NOT TOUCHED, and asserted below:
 *
 *   - `.badge-same` stays declared in BOTH `public/css/custom.css` and `public/css/rtl-custom.css`.
 *     Three vendored DataTables still emit the class (AuctionProductOrderData, PreOrderDataTable,
 *     LotteryOrderData), and deleting it from one sheet only would reach only the Arabic or only
 *     the English merchants.
 *   - no stylesheet at all, so there is no LTR/RTL twin edit to get half-right.
 *   - the column count and the first cell, which `PaymentTypeDisplayTest` and
 *     `CustomerGuestOrderTenancyTest` read BY INDEX after locating the table by its `Payment Type`
 *     header. They break as a payment-label test and a tenancy test, not as a styling test.
 *   - the `Picked Up` date, which reads `$order->pickedup_date` — a column that does not exist
 *     (the column is `picked_date`), so `Utility::dateFormat(null)` renders the epoch. Preserved
 *     verbatim and asserted as a prefix below: it is a pre-existing data defect with its own
 *     ledger row, and fixing it here would make this diff something other than the finding.
 */
class CustomerOrderStatusChipTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** 4.5:1 — WCAG 2.2 SC 1.4.3 for text below 18.66px bold / 24px (.ss-badge is 0.75rem/500). */
    private const AA_TEXT = 4.5;

    /** 3:1 — SC 1.4.11, the minimum for an icon that carries the control's meaning. */
    private const AA_NON_TEXT = 3.0;

    /** status => [label, the date column the branch reads] */
    private const BRANCHES = [
        0 => ['Pending', 'order_date'],
        1 => ['Delivered', 'delivery_date'],
        4 => ['Confirmed', 'confirmed_date'],
        5 => ['Picked Up', 'pickedup_date'],
        6 => ['Shipped', 'shipped_date'],
        2 => ['Cancel', 'cancel_date'],
        3 => ['Return', 'return_date'],
        8 => ['Pre Order', 'order_date'],
    ];

    /** The host classes that must not survive anywhere on this page. */
    private const HOST_BUTTON_CLASSES = [
        'btn-info', 'btn-warning', 'btn-secondary', 'btn-success',
        'bg-danger', 'btn-danger', 'btn-dark', 'btn-soft-dark', 'badge-same',
    ];

    // =============================================================================================
    // Contrast — computed here, not quoted
    // =============================================================================================

    private function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = [];
        foreach ([0, 2, 4] as $offset) {
            $c = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private function ratio(string $foreground, string $background): float
    {
        $a = $this->luminance($foreground);
        $b = $this->luminance($background);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /** `rgba(r,g,b,a)` painted over an opaque hex, the way a browser composites it. */
    private function over(array $rgba, string $background): string
    {
        [$r, $g, $b, $alpha] = $rgba;
        $background = ltrim($background, '#');

        $out = '';
        foreach ([[$r, 0], [$g, 2], [$b, 4]] as [$channel, $offset]) {
            $under = hexdec(substr($background, $offset, 2));
            $out .= sprintf('%02x', (int) round($alpha * $channel + (1 - $alpha) * $under));
        }

        return '#' . $out;
    }

    /**
     * PREMISE AND NEGATIVE CONTROL — the calculator can still see the defect.
     *
     * Every number in this file's docblock is recomputed here from the host declarations, and each
     * is required to FAIL the threshold it failed. A contrast test whose maths has drifted would
     * otherwise report the repair as sound no matter what colours it shipped.
     */
    public function test_the_host_classes_this_repair_removed_really_did_fail_aa(): void
    {
        $lightCard = '#ffffff';   // --bs-card-bg, public/assets/css/style.css:5000
        $darkCard = '#292A33';    // --bs-card-bg, public/assets/css/style-dark.css:5006

        $measured = [
            // chip                       foreground  background                         expected
            'btn-info / Pending, both themes (opaque bg)' => ['#35abb6', '#e8f8ff', 2.52],
            'btn-warning / Confirmed, light' => ['#f4b41a', $this->over([244, 180, 26, 0.15], $lightCard), 1.68],
            // 3.44, not the 3.41 the finding's first pass reported: the difference is entirely
            // the direction the composite's channels are rounded (0.25*117 + 0.75*255 = 220.5 and
            // 0.25*125 + 0.75*255 = 222.5, both exact halves). Rounding half up gives
            // rgb(218,221,223) and 3.44:1; half down gives rgb(218,220,222) and 3.41:1. Either
            // way it is below AA, and the second independent reading of this finding also got
            // 3.44 — the number is recorded here as computed, not as first quoted.
            'btn-secondary / Picked Up, light' => ['#6c757d', $this->over([108, 117, 125, 0.25], $lightCard), 3.44],
            'btn-secondary / Picked Up, dark' => ['#6c757d', $this->over([108, 117, 125, 0.25], $darkCard), 2.31],
            'bg-danger / Cancel + Return, light' => ['#d80027', $this->over([255, 174, 189, 0.41], $lightCard), 4.26],
            'bg-danger / Cancel + Return, dark' => ['#d80027', $this->over([255, 174, 189, 0.41], $darkCard), 1.03],
            // --third-color is declared only under :root .theme-1…theme-10, and the layout's
            // default body class is `custom-color`, so --bs-btn-bg is invalid and the fill is
            // transparent: the green sits on the card itself.
            'btn-success / Delivered, light' => ['#5eb839', $lightCard, 2.50],
            // No `.btn-soft-dark` rule exists outside :hover, so it inherits .btn's own colour.
            'btn-soft-dark / Shipped, dark' => ['#293240', $darkCard, 1.10],
            'btn-dark / Pre Order, dark' => ['#1c232f', $this->over([28, 35, 47, 0.15], $darkCard), 1.09],
        ];

        foreach ($measured as $what => [$fg, $bg, $expected]) {
            $actual = $this->ratio($fg, $bg);

            $this->assertSame($expected, round($actual, 2),
                "premise: the recomputed ratio for {$what} is " . round($actual, 2)
                . ":1, not the {$expected}:1 this finding was raised on. Either a stylesheet "
                . 'changed or this calculator has drifted — in both cases the assertions below '
                . 'prove nothing until it is resolved.');

            $this->assertLessThan(self::AA_TEXT, $actual, $what . ' was never the defect');
        }
    }

    /**
     * The replacement, both themes, every tone the eight branches can produce — read out of
     * `store-syria-ds.css` rather than hard-coded, so a token change cannot silently drop below AA.
     *
     * MEASURED (and asserted): warning 8.83 light / 9.81 dark, info 8.28 / 8.25, danger 8.09 /
     * 9.06, success 7.10 / 8.79, neutral 8.12 / 8.98. Opaque fills, so no card colour is involved.
     */
    public function test_every_tone_the_replacement_can_wear_passes_aa_in_both_themes(): void
    {
        $ds = (string) file_get_contents(base_path('public/css/store-syria-ds.css'));

        $pairs = [
            'warning light' => ['#5d3f04', '#fdf4e3', 8.83],
            'info light' => ['#3f3a8f', '#f0eefb', 8.28],
            'danger light' => ['#8c1d17', '#fdeeee', 8.09],
            'success light' => ['#165e34', '#eaf7ef', 7.10],
            'neutral light' => ['#43474d', '#eeeff1', 8.12],
            'warning dark' => ['#f0c674', '#2c2109', 9.81],
            'info dark' => ['#b6b1ef', '#1d1b3d', 8.25],
            'danger dark' => ['#f3a9a4', '#2c1413', 9.06],
            'success dark' => ['#7fd6a0', '#102a1b', 8.79],
            'neutral dark' => ['#c3c6cc', '#21252c', 8.98],
        ];

        foreach ($pairs as $what => [$fg, $bg, $expected]) {
            foreach ([$fg, $bg] as $hex) {
                $this->assertStringContainsString($hex, $ds,
                    "premise: {$hex} ({$what}) is no longer declared in store-syria-ds.css, so "
                    . 'this reading is about a colour the page does not use.');
            }

            $actual = $this->ratio($fg, $bg);
            $this->assertSame($expected, round($actual, 2),
                "the {$what} badge tone now measures " . round($actual, 2) . ":1, not {$expected}:1");
            $this->assertGreaterThanOrEqual(self::AA_TEXT, $actual, $what . ' is below AA');
        }

        // The two row controls the repair also migrates. Transparent fills, so these DO sit on
        // the card: ghost --ss-fg-muted #5b5f66 on #ffffff = 6.42:1 and #a7abb2 on #292A33 =
        // 6.18:1; danger-quiet --ss-danger #b3261e on #ffffff = 6.54:1 and #f3a9a4 on #292A33 =
        // 7.48:1. All four clear the 3:1 non-text minimum and the 4.5:1 text minimum.
        foreach ([
            'ss-btn--ghost light' => ['#5b5f66', '#ffffff', 6.42],
            'ss-btn--ghost dark' => ['#a7abb2', '#292A33', 6.18],
            'ss-btn--danger-quiet light' => ['#b3261e', '#ffffff', 6.54],
            'ss-btn--danger-quiet dark' => ['#f3a9a4', '#292A33', 7.48],
        ] as $what => [$fg, $bg, $expected]) {
            $actual = $this->ratio($fg, $bg);
            $this->assertSame($expected, round($actual, 2),
                "{$what} measures " . round($actual, 2) . ":1, not {$expected}:1");
            $this->assertGreaterThanOrEqual(self::AA_NON_TEXT, $actual, $what . ' is below 1.4.11');
        }

        // And the icon-only View link's OLD colour, which the cell-scoped version of this repair
        // would have left three lines from the fixed chip: #35abb6 on the opaque #e8f8ff, in both
        // themes, on a control whose meaning is carried entirely by its glyph.
        $this->assertLessThan(self::AA_NON_TEXT, $this->ratio('#35abb6', '#e8f8ff'),
            'premise: btn-info on an icon-only control was never below 3:1');
    }

    // =============================================================================================
    // The rendered page
    // =============================================================================================

    /** @return array{0:User,1:int} the merchant and the customer whose orders page is rendered */
    private function merchantAndCustomer(): array
    {
        [$merchant, $store] = $this->makeTenant();

        // `Show Customer`, not `Manage Customer`: CustomerController::show() gates on
        // `type === UserPolicy::PLATFORM_ADMIN || isAbleTo('Show Customer')` — operator first,
        // because Laratrust has no super-admin bypass. The fixture merchant's type is `admin`.
        $this->grantPermission($merchant, 'Show Customer');
        $this->grantPermission($merchant, 'Manage Order');

        // first_name/last_name: `customers` has no `name` column.
        $customerId = (int) \DB::table('customers')->insertGetId([
            'first_name' => 'Rania',
            'last_name' => 'Khoury',
            'email' => 'rania' . uniqid() . '@example.test',
            'password' => bcrypt('x'),
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$merchant, $customerId, $store];
    }

    /**
     * The status cell of the first order row, as [outer HTML, trimmed text], located the way
     * `PaymentTypeDisplayTest::paymentCell()` locates its own column: by the literal `Payment Type`
     * header, then by index.
     *
     * @return array{0:string,1:string,2:int,3:string}  [status HTML, status text, td count, row HTML]
     */
    private function statusCell(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        foreach ($dom->getElementsByTagName('table') as $table) {
            $headers = [];
            foreach ($table->getElementsByTagName('th') as $th) {
                $headers[] = trim($th->textContent);
            }

            $payment = array_search('Payment Type', $headers, true);
            if ($payment === false) {
                continue;
            }

            $status = array_search('Status', $headers, true);
            $this->assertNotFalse($status, 'the orders table has no Status column');
            $this->assertSame($payment + 1, $status,
                'the Status column is no longer immediately after Payment Type, so the two tests '
                . 'that read this table by index are reading different cells than they were');

            foreach ($table->getElementsByTagName('tr') as $row) {
                $cells = [];
                foreach ($row->getElementsByTagName('td') as $td) {
                    $cells[] = $td;
                }

                if ($cells === [] || ! array_key_exists($status, $cells)) {
                    continue;
                }

                return [
                    (string) $dom->saveHTML($cells[$status]),
                    trim($cells[$status]->textContent),
                    count($cells),
                    (string) $dom->saveHTML($row),
                ];
            }
        }

        $this->fail('the orders table has no Payment Type column, so this test can prove nothing');
    }

    public function test_every_status_renders_a_design_system_chip_with_the_tone_the_rest_of_the_app_uses(): void
    {
        foreach (self::BRANCHES as $status => [$label, $column]) {
            [$merchant, $customerId, $store] = $this->merchantAndCustomer();

            $order = $this->makeOrder($store, [
                'customer_id' => $customerId,
                'is_guest' => 0,
                'delivered_status' => $status,
                'order_date' => '2026-10-25',
                'delivery_date' => '2026-10-28',
                'confirmed_date' => '2026-10-26',
                'shipped_date' => '2026-10-27',
                'cancel_date' => '2026-10-29',
            ]);

            $response = $this->actingAs($merchant)->get('/customer/' . $customerId);
            $response->assertOk();

            [$cellHtml, $cellText] = $this->statusCell((string) $response->getContent());

            $tone = OrderStatusTone::for($status);
            $toneClass = $tone === 'neutral' ? 'ss-badge' : 'ss-badge--' . $tone;

            $this->assertStringContainsString('class="ss-badge', $cellHtml,
                "status {$status} ({$label}) does not render a design-system badge: " . $cellHtml);
            $this->assertStringContainsString($toneClass, $cellHtml,
                "status {$status} ({$label}) does not wear OrderStatusTone::for({$status}) = "
                . "{$tone}. The tone must come from that class, not from a second inline map — "
                . 'two private maps are how the dashboard and the order list came to disagree '
                . 'about four of the nine statuses.');

            // WCAG 4.1.2: a chip is not a control. Eight `<button>`s per row that do nothing were
            // announced as buttons and took eight keyboard stops.
            $this->assertStringNotContainsString('<button', $cellHtml,
                "status {$status} still renders a <button> in the status cell");

            foreach (self::HOST_BUTTON_CLASSES as $class) {
                $this->assertStringNotContainsString($class, $cellHtml,
                    "status {$status} still carries the vendored `{$class}`. Supplementing rather "
                    . "than replacing is how this repair looks done and is not: `.bg-danger`'s "
                    . 'colour is !important and beats .ss-badge--danger at any source order.');
            }

            // The label and its date, unchanged — this cell's textContent is what a CSV/clipboard
            // copy of the table carries, so a repair that moved the date into a title= or an
            // aria-label would empty the Status column while the screen looked right.
            $this->assertStringStartsWith($label . ' : ', $cellText,
                "status {$status} no longer reads '{$label} : <date>' but '{$cellText}'");
            $this->assertSame(1, substr_count($cellText, $label),
                "status {$status} names '{$label}' more than once — a duplicated accessible name");

            if ($column === 'order_date') {
                $this->assertSame(
                    $label . ' : ' . \App\Models\Utility::dateFormat($order->order_date),
                    $cellText,
                    "status {$status} no longer renders the order date verbatim"
                );
            }
        }
    }

    /**
     * NEGATIVE CONTROL — status 7 and an unknown code still render an EMPTY cell.
     *
     * The chain has no `@else` and the repair deliberately does not add one: labelling
     * `Partially Paid` here would absorb an unclaimed defect and make the diff something other
     * than this finding.
     */
    public function test_an_unlabelled_status_still_renders_an_empty_cell(): void
    {
        foreach ([7, 42] as $status) {
            [$merchant, $customerId, $store] = $this->merchantAndCustomer();
            $this->makeOrder($store, [
                'customer_id' => $customerId,
                'is_guest' => 0,
                'delivered_status' => $status,
            ]);

            $response = $this->actingAs($merchant)->get('/customer/' . $customerId);
            $response->assertOk();

            [$cellHtml, $cellText] = $this->statusCell((string) $response->getContent());

            $this->assertSame('', $cellText,
                "status {$status} now renders '{$cellText}'. That gap is real but it is a separate, "
                . 'merchant-visible change; closing it here means the diff no longer matches the '
                . 'finding.');
            $this->assertStringNotContainsString('ss-badge', $cellHtml);
        }
    }

    /**
     * NEGATIVE CONTROL — the repair did not take away what the row's callers rely on.
     *
     * An over-eager sweep of `<button type="button">` in this file would disarm the delete
     * confirmation; a sweep of the class attribute would drop `show_confirm`, which is what
     * `public/js/custom.js` binds, and the four `data-*` strings `ConfirmationDialogContractTest`
     * requires. `ActionPartialContractTest` additionally requires the control to stay inside a form.
     */
    public function test_the_rows_two_real_controls_survive_and_leave_the_host_families(): void
    {
        [$merchant, $customerId, $store] = $this->merchantAndCustomer();
        $order = $this->makeOrder($store, [
            'customer_id' => $customerId,
            'is_guest' => 0,
            'delivered_status' => 2,
        ]);

        $response = $this->actingAs($merchant)->get('/customer/' . $customerId);
        $response->assertOk();
        $html = (string) $response->getContent();

        [, , $cellCount, $rowHtml] = $this->statusCell($html);

        $this->assertSame(7, $cellCount,
            'the row no longer has exactly 7 cells. PaymentTypeDisplayTest and '
            . 'CustomerGuestOrderTenancyTest both read this table by index.');

        /*
         * The View link, still pointing at the same route. The href cannot be compared literally:
         * `Crypt::encrypt()` is non-deterministic (a fresh IV per call), so re-encrypting the id
         * here produces a different ciphertext for the same order. The route's own path is the
         * stable part, and the id's forgeability under an unrotated APP_KEY is a separate finding.
         */
        $this->assertStringContainsString(
            rtrim(route('order.view', 'x'), 'x'),
            $rowHtml,
            'the View link no longer targets order.view'
        );
        $this->assertMatchesRegularExpression('~<i[^>]*ti-eye[^>]*aria-hidden="true"~', $rowHtml,
            'the View control is gone, or its icon is not hidden from the accessible name — '
            . "Chromium includes the icon font's generated glyph in the name computation, so an "
            . 'un-hidden icon names the control with a private-use codepoint');

        // The Delete control: still a form-scoped submit with its confirmation intact.
        $this->assertStringContainsString('show_confirm', $rowHtml,
            'the delete control lost `show_confirm`, which is the class the confirmation binds to');
        foreach (['data-confirm', 'data-text', 'data-text-yes', 'data-text-no'] as $attribute) {
            $this->assertStringContainsString($attribute, $rowHtml,
                "the delete control lost {$attribute}, so it would delete without confirming");
        }
        $this->assertMatchesRegularExpression('~<form\b[^>]*>.*show_confirm.*</form>~s', $rowHtml,
            'the delete control is no longer inside its form, so it submits nothing');
        $this->assertMatchesRegularExpression('~<i[^>]*ti-trash[^>]*aria-hidden="true"~', $rowHtml,
            'the delete control is gone, or its icon is not hidden from the accessible name');

        // And the row controls left the families two shipped tests already declare out of bounds.
        foreach (['btn-info', 'btn-danger', 'bg-danger', 'badge-same'] as $class) {
            $this->assertStringNotContainsString($class, $html,
                "the page still renders `{$class}`. The finding's own headline pair — #d80027 on "
                . 'rgba(255,174,189,.41) — is `.btn-danger` as well as `.bg-danger`, and it is on '
                . 'the delete control in the same <tr>: a cell-scoped repair leaves the worst '
                . 'reading in the file 1.03:1 on the dark card.');
        }

        // Three hidden decorations in the row and no more: the badge's status dot and the two
        // row-control icons. A fourth would mean something carrying meaning was hidden.
        $this->assertSame(3, substr_count($rowHtml, 'aria-hidden="true"'),
            "the row's hidden decorations are no longer exactly the badge dot and the two "
            . 'control icons');
    }

    /**
     * NEGATIVE CONTROL — the twin-stylesheet obligation, and the three vendored consumers.
     *
     * This repair touches NO stylesheet, which is the reason to prefer it: `custom.css` serves LTR
     * and `rtl-custom.css` serves Arabic, so a rule added to one reaches half the merchants.
     * `.badge-same` must therefore still be declared in BOTH — AuctionProductOrderData,
     * PreOrderDataTable and LotteryOrderData all still emit the class from `packages/workdo/`,
     * which is out of bounds.
     */
    public function test_no_stylesheet_was_touched_and_badge_same_survives_in_both_directions(): void
    {
        foreach (['public/css/custom.css', 'public/css/rtl-custom.css'] as $sheet) {
            $css = (string) file_get_contents(base_path($sheet));

            $this->assertMatchesRegularExpression('~\.badge-same\s*\{~', $css,
                $sheet . ': `.badge-same` was deleted. Three vendored DataTables still emit the '
                . "class, so removing the rule changes those modules' chip widths — and removing "
                . 'it from one sheet only reaches one language.');

            $this->assertStringNotContainsString('ss-badge', $css,
                $sheet . ': a badge rule was added here. The design system is '
                . 'store-syria-ds.css, and a rule in one of these two sheets serves one direction '
                . 'only.');
        }

        // The badge's own box is written in logical properties, so there is nothing for an RTL
        // sheet to mirror.
        $ds = (string) file_get_contents(base_path('public/css/store-syria-ds.css'));
        $badge = (string) strstr($ds, '.ss-badge {');
        $badge = substr($badge, 0, (int) strpos($badge, '}'));
        $this->assertStringContainsString('padding-inline', $badge);
        $this->assertStringNotContainsString('padding-left', $badge);
        $this->assertStringNotContainsString('padding-right', $badge);
    }

    /**
     * NEGATIVE CONTROL — no new user-visible string.
     *
     * `ArabicUiCoverageTest` globs `resources/views/customer/*.blade.php` and asserts every `__()`
     * literal has an `ar.json` key. The eight labels are also a shipped API contract
     * (`ApiContract\OrderStatusTextTest`), so they must stay verbatim — not the order detail
     * page's `Picked up`/`Cancelled`/`Returned`.
     */
    public function test_the_eight_labels_are_unchanged_and_translated(): void
    {
        $ar = json_decode((string) file_get_contents(base_path('resources/lang/ar.json')), true);
        $this->assertIsArray($ar);

        $source = (string) file_get_contents(base_path('resources/views/customer/view.blade.php'));

        foreach (self::BRANCHES as $status => [$label]) {
            $this->assertStringContainsString("__('" . $label . "')", $source,
                "the label for status {$status} is no longer the literal `{$label}`, which is a "
                . 'shipped API and order-list string');
            $this->assertArrayHasKey($label, $ar,
                "`{$label}` has no Arabic translation, so it renders in English for an Arabic "
                . 'merchant');
        }
    }
}
