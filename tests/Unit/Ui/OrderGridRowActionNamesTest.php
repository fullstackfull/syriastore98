<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-GRID-NAMES — the order grid card's icon-only controls had no accessible name (WCAG 4.1.2, A)
 * =================================================================================================
 *
 * `resources/views/order/grid.blade.php` renders five icon-only row actions per card -- return
 * Approve, return Cancel, Show, Edit, Delete -- plus a sixth icon-only focusable control in the
 * card header carrying the order's status. Every one of them was named ONLY by `title`, and every
 * one of them carries `data-bs-toggle="tooltip"`.
 *
 * `resources/views/layouts/app.blade.php` includes `partision.footerlink`, which loads `dash.js`
 * or `side-dash.js`, and both map `new bootstrap.Tooltip` over every `[data-bs-toggle="tooltip"]`
 * on window load. The Bootstrap 5.1.3 constructor reaches `_fixTitle()`, which is:
 *
 *     (t||"string"!==e)&&(this._element.setAttribute("data-bs-original-title",t||""),
 *       !t||this._element.getAttribute("aria-label")||this._element.textContent
 *         ||this._element.setAttribute("aria-label",t),
 *       this._element.setAttribute("title",""))
 *
 * The `aria-label` fallback is gated on `textContent` being FALSY. Every control here has a
 * newline plus indentation between its open tag and its `<i>`, so `textContent` is a non-empty
 * whitespace string -- truthy in JS -- the fallback never fires, and the final
 * `setAttribute("title","")` runs unconditionally. The name is then "" before any interaction.
 * At `paginate(16)` that is 48 nameless controls per page guaranteed, and up to 80 when every
 * order is a pending return.
 *
 * CSS CANNOT SUPPLY A NAME, so markup is the smallest honest level. `aria-label` is added and
 * `title` + the first `data-bs-toggle="tooltip"` are KEPT: the tooltip is the only name a sighted
 * mouse user gets, and where both exist assistive tech announces `aria-label`.
 * `resources/views/order/action.blade.php` already decided exactly this policy for the list.
 *
 * THE DELETE BUTTON'S ATTRIBUTE ORDER IS LOAD-BEARING. `aria-label` must stay AFTER `data-confirm`
 * -- see test_the_delete_buttons_aria_label_stays_after_data_confirm for why, and for the lying
 * failure message a reorder produces in another test.
 *
 * THE SIXTH CONTROL IS ASSERTED AS A PROPERTY, NOT A SHAPE. `test_the_status_indicator_is_not_a
 * _nameless_icon` accepts EITHER a named `<a class="dropdown-item">` or a labelled `.ss-badge`
 * with visible text, because the next slice in this chain (grid-status-icon-colour-only) replaces
 * that inert anchor with a text badge -- which removes the nameless control rather than naming it,
 * and is the stronger fix. The test has a premise so it cannot be satisfied by deleting the status
 * altogether.
 */
class OrderGridRowActionNamesTest extends TestCase
{
    private const GRID = 'resources/views/order/grid.blade.php';

    /** The five row actions, each identified by a token unique to it. */
    private const ROW_ACTIONS = [
        'return Approve' => 'data-status="2"',
        'return Cancel' => 'data-status="3"',
        'Show' => 'data-ajax-popup="true"',
        'Edit' => "route('order.view'",
        'Delete' => 'show_confirm',
    ];

    /** Composed keys the repair uses. All five already existed in both catalogues. */
    private const COMPOSED_KEYS = [
        'Approve the return of order :id',
        'Reject the return of order :id',
        'Show order :id',
        'Edit order :id',
        'Delete order :id',
    ];

    private function path(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }

    private function grid(): string
    {
        return (string) file_get_contents($this->path(self::GRID));
    }

    /**
     * Every `<a>` / `<button>` open tag in $html, as raw source.
     *
     * A naive `/<[^>]*>/` is WRONG on this file and the repo has already paid for that twice:
     * `$order->product_order_id` and `['id' => ...]` both contain a `>`, so a character class
     * terminates the "tag" mid-attribute. This scanner tracks Blade `{{ }}` / `{!! !!}` spans and
     * skips `->`, so it sees the real tag boundary.
     *
     * @return list<string>
     */
    private function openTags(string $html): array
    {
        $out = [];
        $len = strlen($html);

        for ($i = 0; $i < $len; $i++) {
            if ($html[$i] !== '<') {
                continue;
            }

            $isTag = false;
            foreach (['a', 'button', 'i', 'span'] as $name) {
                $next = substr($html, $i + 1, strlen($name) + 1);
                if ($next === $name . ' ' || $next === $name . "\n" || $next === $name . '>') {
                    $isTag = true;
                    break;
                }
            }
            if (! $isTag) {
                continue;
            }

            $blade = false;
            for ($j = $i + 1; $j < $len; $j++) {
                if (! $blade && (substr($html, $j, 2) === '{{' || substr($html, $j, 3) === '{!!')) {
                    $blade = true;
                    continue;
                }
                if ($blade && (substr($html, $j, 2) === '}}' || substr($html, $j, 3) === '!!}')) {
                    $blade = false;
                    $j++;
                    continue;
                }
                if (! $blade && $html[$j] === '>' && $html[$j - 1] !== '-') {
                    $out[] = substr($html, $i, ($j + 1) - $i);
                    $i = $j;
                    break;
                }
            }
        }

        return $out;
    }

    private function region(string $from, string $to): string
    {
        $html = $this->grid();

        $start = strpos($html, $from);
        $this->assertNotFalse($start, 'premise: the grid card still contains ' . $from);

        $end = strpos($html, $to, $start);
        $this->assertNotFalse($end, 'premise: the grid card still contains ' . $to . ' after ' . $from);

        return substr($html, $start, $end - $start);
    }

    /** The row-action strip. */
    private function actions(): string
    {
        return $this->region('<div class="bottom-icons', '{!! Form::close()');
    }

    /**
     * $html with every tag, Blade echo and Blade directive removed, so what is left is the text a
     * sighted user actually reads. `strip_tags()` is no good here: the `>` in `$order->x` and in
     * `['id' => ...]` makes it cut tags in the wrong place.
     */
    private function visibleText(string $html): string
    {
        $html = (string) preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}|\{\{--.*?--\}\}/s', '', $html);
        $html = (string) preg_replace('/@\w+\s*(\((?:[^()]|\([^()]*\))*\))?/s', '', $html);

        $out = '';
        $len = strlen($html);
        for ($i = 0; $i < $len; $i++) {
            if ($html[$i] !== '<') {
                $out .= $html[$i];
                continue;
            }
            for ($j = $i + 1; $j < $len; $j++) {
                if ($html[$j] === '>' && $html[$j - 1] !== '-') {
                    $i = $j;
                    break;
                }
            }
        }

        return trim((string) preg_replace('/\s+/', ' ', $out));
    }

    /** The card header, which carries the status indicator. */
    private function header(): string
    {
        return $this->region('<div class="card-header', '<div class="card-body');
    }

    private function tagFor(string $marker): string
    {
        foreach ($this->openTags($this->actions()) as $tag) {
            if (str_contains($tag, $marker) && (str_starts_with($tag, '<a') || str_starts_with($tag, '<button'))) {
                return $tag;
            }
        }

        $this->fail("premise: no row action in the card carries `$marker` any more; the control "
            . 'was renamed or removed rather than given a name');
    }

    // =============================================================================================
    //  PREMISE — the controls are still there, still icon-only
    // =============================================================================================

    public function test_premise_the_card_still_has_five_icon_only_row_actions(): void
    {
        $actions = $this->actions();

        $controls = array_values(array_filter($this->openTags($actions), static fn (string $t): bool
            => str_starts_with($t, '<a') || str_starts_with($t, '<button')));

        $this->assertCount(5, $controls, 'premise: five row actions. If this changed, the floors '
            . 'below are measuring a different screen.');

        foreach (self::ROW_ACTIONS as $label => $marker) {
            $this->assertStringContainsString($marker, $actions,
                "premise: the $label control is still identified by `$marker`");
        }

        // Icon-only: no visible text node anywhere in the strip, so `title`/`aria-label` is the
        // only possible source of a name.
        $text = $this->visibleText($actions);
        $this->assertSame('', $text, 'premise: the row-action strip renders no visible text, so '
            . "every control's name must come from an attribute. Found: `$text`");
    }

    // =============================================================================================
    //  THE REPAIR — six named controls, six hidden icons
    // =============================================================================================

    public function test_every_row_action_carries_a_non_empty_aria_label(): void
    {
        $named = 0;

        foreach (self::ROW_ACTIONS as $label => $marker) {
            $tag = $this->tagFor($marker);

            $this->assertSame(1, preg_match('/\saria-label="([^"]+)"/', $tag, $m),
                "the $label control on the order grid card has no aria-label. Bootstrap 5.1.3's "
                . '_fixTitle() blanks `title` on tooltip init and its aria-label fallback is '
                . 'gated on textContent being falsy, which the newline before the <i> defeats, '
                . "so this control's accessible name is the empty string.");

            $this->assertNotSame('', trim($m[1]), "the $label control's aria-label is empty");
            $named++;
        }

        $this->assertGreaterThanOrEqual(5, $named,
            'floor: five named row actions, so deleting a control cannot satisfy this test');
    }

    public function test_every_row_action_icon_is_hidden_from_assistive_tech(): void
    {
        $icons = array_values(array_filter($this->openTags($this->actions()),
            static fn (string $t): bool => str_starts_with($t, '<i')));

        $this->assertGreaterThanOrEqual(5, count($icons), 'floor: five row-action icons');

        foreach ($icons as $icon) {
            $this->assertStringContainsString('aria-hidden="true"', $icon,
                "`$icon` is not hidden from assistive tech, so the icon font's glyph is offered "
                . 'to the accessible name computation beside the aria-label');
        }
    }

    public function test_the_aria_labels_name_the_order_and_not_just_the_verb(): void
    {
        foreach (self::ROW_ACTIONS as $label => $marker) {
            $tag = $this->tagFor($marker);

            preg_match('/\saria-label="([^"]+)"/', $tag, $m);

            $this->assertStringContainsString('$order->product_order_id', $m[1] ?? '',
                "the $label control's aria-label does not name the order. With sixteen cards on a "
                . 'page, sixteen controls announced "Delete" are indistinguishable in a '
                . "screen reader's control list.");
        }
    }

    /**
     * The next slice replaces this anchor with a text badge, which removes the nameless control
     * instead of naming it. Either outcome satisfies 4.1.2; neither may be absent.
     */
    public function test_the_status_indicator_is_not_a_nameless_icon(): void
    {
        $header = $this->header();

        $anchor = null;
        foreach ($this->openTags($header) as $tag) {
            if (str_starts_with($tag, '<a') && str_contains($tag, 'dropdown-item')) {
                $anchor = $tag;
            }
        }

        $badge = str_contains($header, 'ss-badge') || str_contains($header, 'OrderStatusTone');

        $this->assertTrue($anchor !== null || $badge,
            'premise: the card header carries the order status in SOME form. It must not simply '
            . 'be deleted to satisfy this test.');

        if ($anchor !== null) {
            $this->assertMatchesRegularExpression('/\saria-label="[^"]+"/', $anchor,
                'the status indicator is a focusable icon-only anchor with no aria-label. Its '
                . '`title` is not blanked (it carries no data-bs-toggle), but for an <a> '
                . "name-from-content resolves BEFORE title, and Chromium puts the icon font's "
                . 'generated ::before glyph into that content step -- measured in this repo for '
                . '`<i class="ti ti-file-export">`, which named its control "".');

            foreach ($this->openTags($header) as $tag) {
                if (str_starts_with($tag, '<i')) {
                    $this->assertStringContainsString('aria-hidden="true"', $tag,
                        "the status glyph `$tag` is not hidden from assistive tech");
                }
            }
        } else {
            $this->assertMatchesRegularExpression('/\{\{\s*\$title\s*\}\}|\$title/', $header,
                'the status badge carries no visible label, so the status is still conveyed by '
                . 'colour and shape alone');
        }
    }

    // =============================================================================================
    //  THE ATTRIBUTE ORDER ON DELETE — pinned at its own cause
    // =============================================================================================

    /**
     * Tests\Unit\Ui\ConfirmationDialogContractTest scans every merchant view with
     * `/<[^>]*\bshow_confirm\b[^>]*>/`. The repair adds an `aria-label` containing
     * `['id' => $order->product_order_id]`, i.e. a `>`. If that attribute is placed BEFORE
     * `data-confirm`, the character class stops at the `>` inside `['id' =>` and the matched
     * "element" is truncated mid-attribute -- so that test reports this button as carrying no
     * translated confirmation message, which is false. This assertion fails FIRST, at the cause,
     * with the reason stated.
     */
    public function test_the_delete_buttons_aria_label_stays_after_data_confirm(): void
    {
        $tag = $this->tagFor('show_confirm');

        $confirm = strpos($tag, 'data-confirm');
        $aria = strpos($tag, 'aria-label');

        $this->assertNotFalse($confirm, 'premise: the delete button still carries data-confirm');
        $this->assertNotFalse($aria, 'the delete button has no aria-label');

        $this->assertLessThan($aria, $confirm,
            'aria-label is before data-confirm on the delete button. '
            . 'ConfirmationDialogContractTest\'s /<[^>]*\bshow_confirm\b[^>]*>/ then stops at the '
            . '`>` inside `[\'id\' =>` and reports this button as carrying no data-confirm when '
            . 'it plainly does. Keep aria-label last.');
    }

    // =============================================================================================
    //  TRANSLATION — no new key was needed, and both catalogues really do carry all five
    // =============================================================================================

    public function test_every_composed_key_exists_in_both_catalogues(): void
    {
        $en = json_decode((string) file_get_contents($this->path('resources/lang/en.json')), true);
        $ar = json_decode((string) file_get_contents($this->path('resources/lang/ar.json')), true);

        $this->assertIsArray($en);
        $this->assertIsArray($ar);
        $this->assertGreaterThan(3000, count($en), 'premise: en.json parsed');
        $this->assertGreaterThan(3000, count($ar), 'premise: ar.json parsed');

        foreach (self::COMPOSED_KEYS as $key) {
            $this->assertArrayHasKey($key, $en, "en.json has no `$key`");
            $this->assertArrayHasKey($key, $ar, "ar.json has no `$key`, so an Arabic merchant "
                . 'hears the English key with an Arabic order number interpolated');
            $this->assertNotSame('', trim((string) $ar[$key]), "ar.json's `$key` is empty");
        }

        // And the keys the repair composes are the keys the view actually uses.
        $grid = $this->grid();
        foreach (self::COMPOSED_KEYS as $key) {
            $this->assertStringContainsString("__('" . $key . "'", $grid,
                "the view does not use `$key`, so this test is asserting an unused key");
        }
    }

    // =============================================================================================
    //  NEGATIVE CONTROLS — what the repair must NOT have taken away
    // =============================================================================================

    public function test_title_and_the_first_tooltip_toggle_survive_on_every_row_action(): void
    {
        foreach (self::ROW_ACTIONS as $label => $marker) {
            $tag = $this->tagFor($marker);

            $this->assertMatchesRegularExpression('/\stitle="\{\{\s*__\(/', $tag,
                "the $label control lost its `title`. The tooltip is the only name a sighted "
                . 'mouse user gets; aria-label does not replace it.');

            $this->assertStringContainsString('data-bs-toggle="tooltip"', $tag,
                "the $label control lost its tooltip toggle");
        }
    }

    public function test_the_dead_tokens_are_gone_but_the_live_geometry_classes_stay(): void
    {
        $actions = $this->actions();

        $this->assertStringNotContainsString('x-3 ', $actions,
            '`x-3` matches no rule in any first-party stylesheet and should have been dropped');
        $this->assertStringNotContainsString('data-original-title', $actions,
            'Bootstrap 5 reads data-bs-original-title; the BS4 spelling is read by nothing');

        $show = $this->tagFor('data-ajax-popup="true"');
        $this->assertSame(1, substr_count($show, 'data-bs-toggle="tooltip"'),
            'the Show control still carries a duplicate data-bs-toggle attribute');

        foreach (['btn', 'btn-sm', 'border', 'me-2'] as $class) {
            $this->assertMatchesRegularExpression('/class="[^"]*\b' . preg_quote($class, '/') . '\b[^"]*"/', $show,
                "the Show control lost `$class`; removing the wrong token unstyles it");
        }

        $this->assertStringContainsString("'class' => 'd-inline'", $this->grid(),
            "the delete Form::open lost `d-inline`, so the button drops onto its own line");
    }

    public function test_both_encrypted_route_targets_survive(): void
    {
        $actions = $this->actions();

        $this->assertStringContainsString(
            "route('order.order_view', \\Illuminate\\Support\\Facades\\Crypt::encrypt(\$order->id))",
            $actions, 'the Show control no longer uses order.order_view with an encrypted id. '
            . 'Which route the eye button uses was got wrong once already -- '
            . 'tests/Feature/Security/OrderPanelProductScopeTest.php exists because of it.');

        $this->assertStringContainsString(
            "route('order.view', \\Illuminate\\Support\\Facades\\Crypt::encrypt(\$order->id))",
            $actions, 'the Edit control no longer uses order.view with an encrypted id');
    }

    public function test_every_ajax_and_confirm_contract_attribute_is_intact(): void
    {
        $actions = $this->actions();

        foreach ([
            'data-url=', 'data-size="lg"', 'data-ajax-popup="true"', 'data-title=',
            'show_confirm', 'data-confirm=', 'data-text=', 'data-text-yes=', 'data-text-no=',
            'data-id=', 'data-status="2"', 'data-status="3"', 'return_request',
        ] as $contract) {
            $this->assertStringContainsString($contract, $actions,
                "the `$contract` contract is gone; it is bound by a delegated handler in "
                . 'public/js/custom.js or by this view\'s own script block');
        }
    }

    public function test_the_return_controls_gain_no_wrapper_element(): void
    {
        $actions = $this->actions();

        // Skip the `.bottom-icons` opening tag itself; what matters is what is INSIDE it.
        $inner = substr($actions, (int) strpos($actions, '>') + 1);
        $upToFirstReturn = substr($inner, 0, (int) strpos($inner, 'return_request'));

        $this->assertStringNotContainsString('<span', $upToFirstReturn,
            'a wrapper element was introduced between .bottom-icons and the return controls. '
            . "grid.blade.php's success handler does `$(this).parent().find('.return_request')"
            . ".remove()`, so the clicked link's parent must still hold both of them.");
        $this->assertStringNotContainsString('<div', $upToFirstReturn,
            'a wrapper <div> was introduced between .bottom-icons and the return controls');
    }

    public function test_no_design_system_button_token_is_introduced_in_the_card(): void
    {
        $this->assertStringNotContainsString('ss-btn', $this->actions(),
            'this repair adds names, not geometry. Converting these to `ss-btn ss-btn--icon` '
            . 'changes rendered size and colour on a screen no browser check covers, and drags '
            . 'in a separate, unmeasured icon-contrast question.');
    }
}
