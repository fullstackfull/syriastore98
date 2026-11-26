<?php

namespace Tests\Feature\DesignSystem;

use App\Models\ProductAttribute;
use App\Models\ProductAttributeOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-100 / P03-B-07 — THE SECOND FIRST-PARTY SORTABLE MUST BE OPERABLE WITHOUT A MOUSE.
 * =================================================================================================
 *
 * WHAT THE PROJECT MEASURED ABOUT ITSELF, and then shipped. `PHASE-03-M4-THEME-EDITOR-PLAN.md:298`:
 *
 *     "neither first-party sortable has any keyboard path (`keydown` count 0 in both), a WCAG
 *      2.1.1 and 2.5.7 failure for any reorder UI shipped as-is."
 *
 * `5608bd54` answered the first one, `resources/views/theme_customize/order.blade.php`. This file
 * is the second, `resources/views/attribute_option/index.blade.php`. RE-MEASURED at HEAD before a
 * line of it was written: `keydown` 0, `keyup` 0, `keypress` 0, `tabindex` 0, and the view's only
 * two `<button>`s are Edit and Delete. The rows were a jQuery UI `.sortable()` plus
 * `disableSelection()` and nothing else, so the only way to reorder a customer-facing option list
 * was to drag it.
 *
 * -------------------------------------------------------------------------------------------------
 * THE TWO WAYS THIS SCREEN DIFFERS FROM THE ONE `5608bd54` FIXED, both of which change the fix:
 *
 *   1. IT PERSISTS BY AJAX, not by a hidden input and an explicit Save. The drag's `stop` handler
 *      POSTs the new id order to `route('attribute-option')`, which is gated on
 *      `Edit Attributes Option` and refuses the whole payload if any id is another tenant's. So
 *      "the keyboard writes the same thing the drag writes" cannot be asserted by reading a hidden
 *      input's value out of the DOM, as the sibling test does — there is no such input. What can
 *      be asserted here is the contract the POST is built from: the row ids, their order, and the
 *      route the script posts to. The POST itself is driven in a real browser by
 *      `tests/Support/bin/check-attribute-option-keyboard-live.cjs`, which intercepts it.
 *   2. IT ALREADY HAD THE LIVE REGION. `#ss-sort-status` (role=status, aria-live=polite,
 *      visually hidden) shipped with the design-system pilot for the drag's "Order saved."
 *      announcement. A second region would mean two places a screen reader is watching and a
 *      race between them, so this asserts there is exactly ONE and that it is still that element.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THIS IS AN HTTP TEST AND NOT A SOURCE CENSUS. The markup only exists after
 * `Manage Attributes Option`, `ownedAttribute()`'s tenant predicate and the `order`-sorted query
 * have all run. A census over the Blade file would assert that a `@foreach` body contains a
 * string, which is equally true of a view that redirects with "Permission denied."
 *
 * FOUR WAYS THIS FILE COULD PASS WITHOUT PROVING ANYTHING, all closed below:
 *
 *   1. EMPTY CHROME AT 200. `layouts/app.blade.php` `@yield`s, so a view can render perfect
 *      furniture and no content at HTTP 200 (`SlotLayoutViewsRenderTest` exists for that). The
 *      premise pins the row count and the row identities against the fixture's own ids.
 *   2. ZERO ROWS. The view renders an `ss-empty` block and NO list at all when the attribute has
 *      no options, so every "no row is missing a control" assertion is true of that page.
 *      `assertEveryRowHasBothControls()` fails loudly on an empty set — the sibling test measured
 *      two assertions passing that way against the unfixed screen.
 *   3. THE ARABIC RENDER THAT IS NOT ARABIC. `$setting['currantLang']` is read from
 *      `auth()->user()->language` (`layouts/app.blade.php:59-63`), NOT from the session, and that
 *      column is the only thing that turns `SITE_RTL` on. `withSession(['LANGUAGE' => 'ar'])`
 *      alone renders `dir="ltr"`. `users.language` is set, and the premise asserts `dir="rtl"`
 *      arrived before anything else is measured.
 *   4. A CONTROL THAT LOOKS REACHABLE AND IS NOT. `aria-disabled` and `disabled` paint
 *      identically under the design system (`store-syria-ds.css:891`) and `disabled` takes the
 *      control OUT of the tab order, so moving a row to the last position would destroy the focus
 *      that pressed it. The end-stop assertion checks the spelling, not just that something is
 *      inert.
 */
class AttributeOptionKeyboardPathTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const TERMS = ['Small', 'Medium', 'Large', 'Extra Large'];

    private ProductAttribute $attribute;

    /** @var list<ProductAttributeOption> */
    private array $options = [];

    private \App\Models\User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        [$owner, $store] = $this->makeTenant();

        foreach ([
            'Manage Attributes Option',
            'Create Attributes Option',
            'Edit Attributes Option',
            'Delete Attributes Option',
        ] as $permission) {
            $this->grantPermission($owner, $permission);
        }

        $name = 'kb-attr-' . $this->uniqueFixtureRef();

        $this->attribute = ProductAttribute::forceCreate([
            'name' => $name,
            // product_attributes.slug is string() with neither nullable() nor a default.
            'slug' => $name,
            'store_id' => $store->id,
        ]);

        foreach (self::TERMS as $i => $term) {
            $this->options[] = ProductAttributeOption::forceCreate([
                'attribute_id' => $this->attribute->id,
                'terms' => $term,
                'order' => $i,
                'store_id' => $store->id,
            ]);
        }

        $this->merchant = $owner->fresh();
    }

    private function render(string $language = 'en'): string
    {
        $this->merchant->language = $language;
        $this->merchant->save();
        $this->merchant->flushCache();

        $response = $this->withSession(['LANGUAGE' => $language])
            ->actingAs($this->merchant->fresh())
            ->get('/attribute-option/' . $this->attribute->id);

        $response->assertStatus(200);

        $html = (string) $response->getContent();

        /*
         * THE BROWSER HARNESS NEEDS THE REAL RENDERING, AND THIS IS THE ONLY PLACE THAT MAKES ONE.
         *
         * `tests/Support/bin/check-attribute-option-keyboard-live.cjs` drives this screen's
         * keyboard path in Chromium, which is the only instrument that can answer whether Tab
         * reaches a control, whether the focus ring paints, whether Enter moves a row and whether
         * the POST the drag makes is the POST the keyboard makes. The other live harnesses in this
         * repository point a browser at a dev server backed by `database/p2dev.sqlite`;
         * that file is ZERO BYTES in this checkout (measured), so there is no server to point at.
         * The harness asks this test for the bytes instead — the real route, the real permission
         * gate, the real tenant predicate, the real `order`-sorted query.
         *
         * Off unless asked for, so a normal suite run writes nothing.
         */
        $dir = getenv('SS_ATTR_OPTION_HTML_DIR');

        if (is_string($dir) && $dir !== '' && is_dir($dir)) {
            file_put_contents(rtrim($dir, '/') . '/attribute-option-' . $language . '.html', $html);
        }

        return $html;
    }

    private function dom(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($doc);
    }

    /**
     * The option rows, in document order.
     *
     * @return list<\DOMElement>
     */
    private function rows(\DOMXPath $xpath): array
    {
        $nodes = $xpath->query(
            "//ul[contains(concat(' ', normalize-space(@class), ' '), ' sortable ')]/li"
        );

        $rows = [];

        foreach ($nodes ?: [] as $node) {
            $rows[] = $node;
        }

        return $rows;
    }

    private function moveControl(\DOMXPath $xpath, \DOMElement $row, string $direction): ?\DOMElement
    {
        $hit = $xpath->query('.//*[@data-ss-option-move="' . $direction . '"]', $row);

        return ($hit && $hit->length === 1) ? $hit->item(0) : null;
    }

    /**
     * EVERY TEST BELOW THAT ITERATES THE CONTROLS NEEDS THIS. The sibling screen's test measured
     * two assertions passing against the unfixed view because the loop body never ran: "no control
     * is unnamed" and "no control is wrongly inert" are both true of a screen with no controls.
     * This screen has a second way to reach that state — an attribute with no options renders the
     * `ss-empty` block and no `<ul>` at all.
     *
     * @param  list<\DOMElement>  $rows
     */
    private function assertEveryRowHasBothControls(\DOMXPath $xpath, array $rows): void
    {
        $this->assertNotSame([], $rows, 'PREMISE: no option rows were rendered.');

        $found = 0;

        foreach ($rows as $row) {
            foreach ($xpath->query('.//*[@data-ss-option-move]', $row) ?: [] as $ignored) {
                $found++;
            }
        }

        $this->assertSame(2 * count($rows), $found,
            'PREMISE: expected an up and a down control on each of the ' . count($rows)
            . ' rows and found ' . $found . ' control(s) in total. Without this the assertions '
            . 'that follow iterate an empty set and pass.');
    }

    /** The accessible name of a control whose name comes from its own contents. */
    private function accessibleName(\DOMElement $control): string
    {
        if ($control->hasAttribute('aria-label')) {
            return trim($control->getAttribute('aria-label'));
        }

        return trim((string) preg_replace('/\s+/u', ' ', $control->textContent));
    }

    /* ============================================================== the premise, asserted first */

    /**
     * WITHOUT THIS EVERY ASSERTION BELOW IS A STATEMENT ABOUT AN EMPTY LIST.
     */
    public function test_the_screen_renders_one_row_per_option_in_saved_order(): void
    {
        $xpath = $this->dom($this->render());
        $rows = $this->rows($xpath);

        $this->assertCount(count(self::TERMS), $rows,
            'The attribute-option screen did not render one sortable row per option. Every '
            . 'assertion in this file is about these rows, so a collapsed list must fail here '
            . 'rather than turn the rest green.');

        $this->assertSame(
            array_map(static fn (ProductAttributeOption $o) => (string) $o->id, $this->options),
            array_map(static fn (\DOMElement $li) => $li->getAttribute('data-id'), $rows),
            'The rendered rows are not the fixture\'s option ids in `order` order. Those ids are '
            . 'exactly what the reorder POST is built from, so they are the subject of the screen.'
        );
    }

    /* ======================================================= WCAG 2.1.1 — operable by keyboard */

    /**
     * REACHABLE BY TAB, which for this control means being a real `button`. A `div` with a click
     * handler, a `span[role=button]` without a tabindex and an anchor with no `href` are all
     * clickable and none of them is in the tab order — and all three read as a working control in
     * a screenshot.
     *
     * `type="button"` matters more here than on the sibling screen: each row already contains an
     * open `Form::open(['method' => 'DELETE'])`, so a `<button>` with no type inside it is a
     * SUBMIT button for the delete form. A move control that deletes the row is not a theoretical
     * risk on this view.
     */
    public function test_every_option_row_carries_a_native_move_up_and_move_down_button(): void
    {
        $xpath = $this->dom($this->render());
        $rows = $this->rows($xpath);
        $problems = [];

        foreach ($rows as $i => $row) {
            foreach (['up', 'down'] as $direction) {
                $control = $this->moveControl($xpath, $row, $direction);

                if (! $control) {
                    $problems[] = "row {$i} ({$row->getAttribute('data-id')}): no single "
                        . "data-ss-option-move=\"{$direction}\" control";

                    continue;
                }

                if (strtolower($control->nodeName) !== 'button') {
                    $problems[] = "row {$i}: the {$direction} control is a <{$control->nodeName}>, "
                        . 'which is not in the tab order unless it is given one';
                }

                if ($control->getAttribute('type') !== 'button') {
                    $problems[] = "row {$i}: the {$direction} control has no type=\"button\", so "
                        . 'activating it submits the DELETE form in the same row';
                }

                $tabindex = $control->getAttribute('tabindex');

                if ($tabindex !== '' && (int) $tabindex < 0) {
                    $problems[] = "row {$i}: the {$direction} control has tabindex=\"{$tabindex}\", "
                        . 'which removes it from the tab order';
                }
            }
        }

        $this->assertSame([], $problems,
            "The option rows cannot all be reordered from the keyboard:\n  "
            . implode("\n  ", $problems)
            . "\n\nThis is the WCAG 2.1.1 failure GAP-100 names. The screen's only reorder "
            . 'affordance was a jQuery UI drag.');
    }

    /**
     * AN ACCESSIBLE NAME THAT NAMES THE OPTION, not just the direction. Four rows of "Move up" is
     * a list of four identically-named buttons: a screen-reader user tabbing through it is told
     * the verb and never the subject.
     */
    public function test_each_move_control_names_its_direction_and_its_option(): void
    {
        $xpath = $this->dom($this->render());
        $rows = $this->rows($xpath);
        $this->assertEveryRowHasBothControls($xpath, $rows);
        $problems = [];

        foreach ($rows as $i => $row) {
            $label = self::TERMS[$i];
            $names = [];

            foreach (['up', 'down'] as $direction) {
                $control = $this->moveControl($xpath, $row, $direction);

                if (! $control) {
                    continue;
                }

                $name = $this->accessibleName($control);
                $names[$direction] = $name;

                if ($name === '') {
                    $problems[] = "row {$i} {$direction}: the control has no accessible name at all";

                    continue;
                }

                if (! str_contains($name, $label)) {
                    $problems[] = "row {$i} {$direction}: the name \"{$name}\" does not name the "
                        . "option \"{$label}\"";
                }
            }

            if (count($names) === 2 && $names['up'] === $names['down']) {
                $problems[] = "row {$i}: both controls are announced as \"{$names['up']}\", so the "
                    . 'two directions are indistinguishable';
            }
        }

        $this->assertSame([], $problems,
            "A move control is not announced usefully:\n  " . implode("\n  ", $problems));
    }

    /**
     * AND THE CURRENT POSITION, which is the thing a sighted user reads off the list and a
     * screen-reader user cannot. It is attached as a DESCRIPTION rather than folded into the name,
     * so the name stays stable while the position changes under it: a control whose accessible
     * NAME changes under the user is re-announced as a different control.
     */
    public function test_each_row_states_its_position_and_both_controls_point_at_it(): void
    {
        $xpath = $this->dom($this->render());
        $rows = $this->rows($xpath);
        $this->assertEveryRowHasBothControls($xpath, $rows);
        $total = count($rows);
        $problems = [];
        $ids = [];

        foreach ($rows as $i => $row) {
            $hit = $xpath->query('.//*[@data-ss-option-position]', $row);

            if (! $hit || $hit->length !== 1) {
                $problems[] = "row {$i}: no single data-ss-option-position element";

                continue;
            }

            $position = $hit->item(0);
            $id = $position->getAttribute('id');
            $text = trim((string) preg_replace('/\s+/u', ' ', $position->textContent));

            if ($id === '') {
                $problems[] = "row {$i}: the position element has no id, so nothing can reference it";
            } else {
                $ids[] = $id;
            }

            $expected = 'Position ' . ($i + 1) . ' of ' . $total;

            if ($text !== $expected) {
                $problems[] = "row {$i}: position reads \"{$text}\", expected \"{$expected}\"";
            }

            foreach (['up', 'down'] as $direction) {
                $control = $this->moveControl($xpath, $row, $direction);

                if ($control && $control->getAttribute('aria-describedby') !== $id) {
                    $problems[] = "row {$i} {$direction}: aria-describedby is "
                        . "\"{$control->getAttribute('aria-describedby')}\", not \"{$id}\"";
                }
            }
        }

        $this->assertSame(array_unique($ids), $ids,
            'Two rows share a position element id, so aria-describedby resolves to the wrong row.');

        $this->assertSame([], $problems,
            "A row does not state where it is in the order:\n  " . implode("\n  ", $problems));
    }

    /**
     * THE END STOPS ARE `aria-disabled`, NOT `disabled`, AND THE SPELLING IS THE POINT. `disabled`
     * removes the control from the tab order, so moving a row to the last position with the
     * keyboard would disable the very button that was pressed and drop focus to the document.
     * `aria-disabled` keeps it focusable and inert — which is why the script also has to refuse it.
     */
    public function test_only_the_first_up_and_the_last_down_are_inert_and_neither_leaves_the_tab_order(): void
    {
        $xpath = $this->dom($this->render());
        $rows = $this->rows($xpath);
        $this->assertEveryRowHasBothControls($xpath, $rows);
        $last = count($rows) - 1;
        $problems = [];

        foreach ($rows as $i => $row) {
            foreach (['up', 'down'] as $direction) {
                $control = $this->moveControl($xpath, $row, $direction);

                if (! $control) {
                    continue;
                }

                if ($control->hasAttribute('disabled')) {
                    $problems[] = "row {$i} {$direction}: uses the disabled attribute, which takes "
                        . 'the control out of the tab order and loses the focus that pressed it';
                }

                $inert = $control->getAttribute('aria-disabled') === 'true';
                $shouldBeInert = ($direction === 'up' && $i === 0) || ($direction === 'down' && $i === $last);

                if ($inert !== $shouldBeInert) {
                    $problems[] = "row {$i} {$direction}: aria-disabled is "
                        . ($inert ? 'set' : 'absent') . ' and should be '
                        . ($shouldBeInert ? 'set' : 'absent');
                }
            }
        }

        $this->assertSame([], $problems,
            "The end stops are wrong:\n  " . implode("\n  ", $problems));
    }

    /**
     * EXACTLY ONE LIVE REGION, AND IT IS THE ONE THAT WAS ALREADY THERE.
     *
     * This screen differs from the sibling sortable here: `#ss-sort-status` shipped with the
     * design-system pilot, for the drag's "Order saved." announcement. The keyboard path must
     * reuse it. Two regions would mean two elements a screen reader is watching, a race between
     * the move announcement and the save announcement, and — because the second would have to be
     * created somewhere — a plausible place to put it inside the list, where a reorder re-creates
     * the node and the pending announcement is lost.
     *
     * SCOPED TO THE ID, NOT COUNTED OVER THE PAGE, and the first draft of this test got that
     * wrong and FAILED before a line of the fix was written. A page-wide `//*[@aria-live]` count
     * answers 3 on this screen: the command palette's `#ss-command-status`
     * (`partision/command-palette.blade.php:47`) and the sidebar's search-result region
     * (`partision/sidebar.blade.php:182`) are rendered by the chrome on every admin page. That is
     * the same trap `PilotScreenContractTest` records for `data-id` and `data-ajax-popup`, where
     * the host chrome's own attributes made a count of 5 rows read as 3 and 7 as 10. "The VIEW
     * declares exactly one region" is a question about the view's source, so it is asserted in
     * `AttributeOptionKeyboardContractTest`, where the subject is the file.
     */
    public function test_the_screen_carries_exactly_one_polite_live_region_and_it_is_the_existing_one(): void
    {
        $xpath = $this->dom($this->render());

        $regions = $xpath->query('//*[@id="ss-sort-status"]');

        $this->assertNotFalse($regions);
        $this->assertSame(1, $regions->length,
            'The attribute-option screen no longer renders exactly one #ss-sort-status element ('
            . $regions->length . ' found). That is the region the drag\'s save announcement '
            . 'already writes to, and the keyboard path reuses it rather than adding a second '
            . 'place a screen reader is watching.');

        $node = $regions->item(0);

        $this->assertSame('polite', $node->getAttribute('aria-live'),
            'The announcement region is not aria-live="polite", so a move is either not announced '
            . 'or interrupts whatever the user was reading.');

        $this->assertSame('status', $node->getAttribute('role'),
            'The announcement region has no role="status".');

        $this->assertStringContainsString('ss-visually-hidden', $node->getAttribute('class'),
            'The announcement region is not visually hidden, so it prints on the page.');

        /*
         * AND IT IS OUTSIDE THE LIST. A reorder reparents a row; a live region inside one is
         * removed and re-inserted, and a pending announcement in a node that was just re-created
         * is never read.
         */
        $inside = $xpath->query(
            "//ul[contains(concat(' ', normalize-space(@class), ' '), ' sortable ')]"
            . '//*[@id="ss-sort-status"]'
        );

        $this->assertSame(0, $inside->length,
            'The announcement region is inside the sortable list, so reordering a row re-creates '
            . 'it and the announcement is lost.');
    }

    /**
     * THE LIST ITSELF HAS TO BE NAMED. Nine rows of "Position 1 of 4" are only meaningful inside
     * something; `role="list"` was already there (it is not redundant beside `list-unstyled`,
     * which drops list semantics in Safari) and the name is what says what the list holds.
     */
    public function test_the_sortable_list_is_named(): void
    {
        $xpath = $this->dom($this->render());

        $lists = $xpath->query("//ul[contains(concat(' ', normalize-space(@class), ' '), ' sortable ')]");

        $this->assertSame(1, $lists->length, 'PREMISE: there is not exactly one sortable list.');

        $list = $lists->item(0);

        $this->assertSame('list', $list->getAttribute('role'),
            'The sortable list lost role="list". `list-unstyled` sets list-style:none, which drops '
            . 'list semantics from the accessibility tree in Safari.');

        $this->assertNotSame('', trim($list->getAttribute('aria-label')),
            'The sortable list has no accessible name, so the position descriptions on its rows '
            . 'describe a position in nothing.');
    }

    /* ================================================ both directions: Arabic, RTL, unmirrored */

    /**
     * RTL MIRRORS THE INLINE AXIS AND LEAVES THE BLOCK AXIS ALONE, so "up" is up in both
     * directions and the controls must NOT swap. The repository's other keyboard reorder
     * (`store-syria-ds.js`, column headers) does invert its keys — because Left and Right are
     * inline-axis keys and the first column paints on the right in Arabic. Carrying that inversion
     * onto a vertical list is the same bug with the sign flipped: invisible in English, reported
     * only by an Arabic merchant.
     *
     * What is asserted is the property rather than the absence of a line of code: the Arabic
     * render really is RTL, the controls still appear up-then-down in document order on every row,
     * and the names really are Arabic rather than an English fallback.
     */
    public function test_the_move_semantics_are_not_mirrored_in_arabic(): void
    {
        $html = $this->render('ar');

        $this->assertStringContainsString('dir="rtl"', $html,
            'PREMISE: the Arabic render is not right-to-left, so this test is measuring an LTR '
            . 'page. $setting[\'currantLang\'] is read from users.language, not from the session.');

        $xpath = $this->dom($html);
        $rows = $this->rows($xpath);

        $this->assertCount(count(self::TERMS), $rows, 'PREMISE: the Arabic render has no rows.');
        $this->assertEveryRowHasBothControls($xpath, $rows);

        $problems = [];

        foreach ($rows as $i => $row) {
            $order = [];

            foreach ($xpath->query('.//*[@data-ss-option-move]', $row) ?: [] as $control) {
                $order[] = $control->getAttribute('data-ss-option-move');
            }

            if ($order !== ['up', 'down']) {
                $problems[] = "row {$i}: controls appear as [" . implode(', ', $order)
                    . '] in document order, not [up, down]';
            }
        }

        $this->assertSame([], $problems,
            "The move controls are reordered or relabelled under RTL:\n  "
            . implode("\n  ", $problems)
            . "\n\nUp is up in Arabic. Only the inline axis mirrors.");
    }

    /**
     * AND THE ARABIC NAMES ARE ARABIC. `__()` falls back to the English key when a translation is
     * missing, silently — which is the whole of Part 2 of this work. An RTL page whose controls
     * read "Move Small up" is an untranslated screen that every other assertion here passes.
     *
     * Asserted on the RENDERED control, not on `ar.json`: a key present in the file and a key the
     * view actually looks up are different claims, and only the second one reaches the merchant.
     */
    public function test_the_arabic_render_names_the_controls_in_arabic(): void
    {
        $xpath = $this->dom($this->render('ar'));
        $rows = $this->rows($xpath);
        $this->assertEveryRowHasBothControls($xpath, $rows);
        $problems = [];

        foreach ($rows as $i => $row) {
            foreach (['up', 'down'] as $direction) {
                $control = $this->moveControl($xpath, $row, $direction);

                if (! $control) {
                    continue;
                }

                $name = $this->accessibleName($control);

                if (preg_match('/\p{Arabic}/u', $name) !== 1) {
                    $problems[] = "row {$i} {$direction}: the name \"{$name}\" contains no Arabic, "
                        . 'so __() fell back to the English key';
                }
            }

            $position = $xpath->query('.//*[@data-ss-option-position]', $row);

            if ($position && $position->length === 1) {
                $text = trim((string) $position->item(0)->textContent);

                if (preg_match('/\p{Arabic}/u', $text) !== 1) {
                    $problems[] = "row {$i}: the position reads \"{$text}\", which contains no "
                        . 'Arabic';
                }

                if (str_contains($text, ':n') || str_contains($text, ':total')) {
                    $problems[] = "row {$i}: the position reads \"{$text}\" — a placeholder was "
                        . 'never substituted, so the Arabic value carries a token the key does not '
                        . 'declare or drops one it does';
                }
            }
        }

        $this->assertSame([], $problems,
            "The Arabic render falls back to English or leaves a placeholder unsubstituted:\n  "
            . implode("\n  ", $problems));
    }

    /* =============================================== persisted the same way the drag persists */

    /**
     * THE KEYBOARD MOVE MUST DRIVE THE SAME PERSISTENCE THE DRAG DRIVES, and on this screen that
     * is an AJAX POST rather than a hidden input.
     *
     * There is nothing in the DOM holding the order, so what is asserted here is the contract the
     * POST is built from: the route the script posts to, and the `data-id` attributes it collects.
     * Whether the POST actually fires on a keyboard move — and whether it carries the moved order —
     * is driven in Chromium by `check-attribute-option-keyboard-live.cjs`, which intercepts the
     * request. This is the half a DOM test can honestly answer.
     */
    public function test_the_screen_still_posts_to_the_reorder_route(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('product-attribute/option', $html,
            'The screen no longer references the reorder route, so neither the drag nor the '
            . 'keyboard path can persist anything. That route is gated on `Edit Attributes '
            . 'Option` and refuses a payload containing another tenant\'s id.');

        $xpath = $this->dom($html);
        $rows = $this->rows($xpath);

        foreach ($rows as $i => $row) {
            $this->assertNotSame('', $row->getAttribute('data-id'),
                "row {$i} has no data-id, so it cannot appear in the posted order.");
        }
    }
}
