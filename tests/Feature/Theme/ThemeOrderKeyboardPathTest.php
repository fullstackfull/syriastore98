<?php

namespace Tests\Feature\Theme;

use App\Models\Addon;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GAP-100 / P03-B-07 — THE SECTION-ORDER SCREEN MUST BE OPERABLE WITHOUT A MOUSE.
 * =================================================================================================
 *
 * WHAT THE PROJECT MEASURED ABOUT ITSELF, and then shipped. `PHASE-03-M4-THEME-EDITOR-PLAN.md:298`:
 *
 *     "neither first-party sortable has any keyboard path (`keydown` count 0 in both), a WCAG
 *      2.1.1 and 2.5.7 failure for any reorder UI shipped as-is."
 *
 * `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` then names the file: implementation
 * `resources/views/theme_customize/order.blade.php:88-116`, route `theme.customize.order`, and the
 * failure absent from `PHASE-03-FINAL-REPORT.md` §9. Re-measured at HEAD before this file was
 * written: `keydown`, `keyup`, `keypress` and `tabindex` all count **0** in that view, and the one
 * `aria-` hit is unrelated. The rows were a jQuery UI `.sortable()` plus `disableSelection()` and
 * nothing else, so the only way to reorder a page's sections was to drag them.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THIS IS AN HTTP TEST AND NOT A SOURCE CENSUS. The markup only exists after the permission
 * gate, the `ThemeEditorGate` ownership check, the theme catalogue lookup and `pages.json` have all
 * run; `$orders` is read out of the theme's own JSON. A census over the Blade file would assert
 * that a `@foreach` body contains a string, which is true of a view that 403s. This drives the real
 * route as a merchant holding `Manage Themes` and asserts the DOM the browser receives.
 *
 * FOUR WAYS THIS FILE COULD PASS WITHOUT PROVING ANYTHING, all closed below:
 *
 *   1. EMPTY CHROME AT 200. `layouts/app.blade.php` `@yield`s, so a view can render perfect
 *      furniture and no content at HTTP 200 (`SlotLayoutViewsRenderTest` exists for that). Every
 *      assertion here is on the section rows, and `test_the_screen_renders_the_themes_own_sections`
 *      pins their count and their identities against `themes/stylique/theme_json/pages.json`.
 *   2. ZERO ROWS. `explode(',', '')` yields one empty row, so a store with no saved ordering still
 *      renders something. The premise asserts NINE rows named after the nine tokens stylique
 *      declares, so a collapsed list fails by name instead of passing vacuously.
 *   3. THE ARABIC RENDER THAT IS NOT ARABIC. `$setting['currantLang']` is read from
 *      `auth()->user()->language` (`layouts/app.blade.php:59-67`), NOT from the session, and that
 *      column is also the only thing that turns `SITE_RTL` on. MEASURED while writing this:
 *      `withSession(['LANGUAGE' => 'ar'])` alone renders `dir="ltr"` — so an RTL assertion written
 *      that way checks an LTR page. This is the inverse of the P2-06-6 defect, where a live harness
 *      measured an RTL page in all four configurations. `users.language` is set, and the premise
 *      asserts `dir="rtl"` arrived.
 *   4. A CONTROL THAT LOOKS REACHABLE AND IS NOT. `aria-disabled` and `disabled` paint the same
 *      under the design system (`store-syria-ds.css:891`), and `disabled` takes the control OUT of
 *      the tab order — so pressing "down" on the second-to-last row would move it to the end and
 *      then destroy the focus that pressed it. The end-stop assertion therefore checks the
 *      spelling, not just that something is inert.
 */
class ThemeOrderKeyboardPathTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/theme-customize/stylique/pages/order/home';

    /** What themes/stylique/theme_json/pages.json declares for the orderable `home` page. */
    private const STYLIQUE_HOME = [
        'slider', 'category', 'product', 'top_category', 'bestseller',
        'more_offer', 'article', 'testimonial', 'logos',
    ];

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::forceCreate([
            'name' => 'Order Merchant', 'email' => 'order-kb-m@example.test',
            'password' => bcrypt('x'), 'type' => 'admin', 'created_by' => 1,
            'email_verified_at' => now(), 'language' => 'en',
        ]);

        $this->store = Store::create([
            'name' => 'Order Store', 'email' => 'order-kb-s@example.test', 'slug' => 'order-kb-store',
            'theme_id' => 'stylique', 'created_by' => $this->merchant->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);

        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        /* Without an active Addon row the theme screens render chrome and no theme at all. */
        foreach (['stylique', 'greentic', 'techzonix'] as $theme) {
            Addon::create(['theme_id' => $theme, 'status' => '1']);
        }

        $this->grantManageThemes();
    }

    /** Mirrors ThemeEditorReadRoutesTest::grantManageThemes(); forceCreate because create() drops store_id. */
    private function grantManageThemes(): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'Manage Themes'], ['display_name' => 'Manage Themes']
        );

        $role = Role::forceCreate([
            'name' => 'order-kb-role', 'display_name' => 'Order KB Role', 'guard_name' => 'web',
            'created_by' => $this->merchant->creatorId(), 'store_id' => $this->merchant->current_store,
        ]);

        $role->givePermission($permission);
        $this->merchant->addRole($role);

        /* User is Cachable; without this the request sees the role-less row. */
        $this->merchant->flushCache();
        $this->merchant = $this->merchant->fresh();
    }

    private function render(string $language = 'en'): string
    {
        $this->merchant->language = $language;
        $this->merchant->save();
        $this->merchant->flushCache();

        $response = $this->withSession(['LANGUAGE' => $language])
            ->actingAs($this->merchant->fresh())
            ->get(self::URL);

        $response->assertOk();

        $html = (string) $response->getContent();

        /*
         * THE BROWSER HARNESS NEEDS THE REAL RENDERING, AND THIS IS THE ONLY PLACE THAT MAKES ONE.
         *
         * `tests/Support/bin/check-theme-order-keyboard-live.cjs` drives this screen's keyboard
         * path in Chromium, which is the only instrument that can answer whether Tab reaches the
         * control, whether the focus ring paints, and whether Enter actually moves a row. The
         * other live harnesses in this repository point a browser at a dev server backed by
         * `database/p2dev.sqlite` — that route cannot reach THIS screen: the file is zero bytes in
         * a fresh checkout, and M4-7 measured that even when seeded the fixture does not hold
         * `Manage Themes`, which all four theme GETs require (it is why the four
         * `_theme_customize__*.png` captures in the Phase 2.5 evidence folder are pictures of the
         * dashboard). So the harness asks this test for the bytes instead.
         *
         * Off unless asked for, so a normal suite run writes nothing.
         */
        $dir = getenv('SS_ORDER_HTML_DIR');

        if (is_string($dir) && $dir !== '' && is_dir($dir)) {
            file_put_contents(rtrim($dir, '/') . '/order-' . $language . '.html', $html);
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
     * The section rows, in document order.
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
        $hit = $xpath->query('.//*[@data-ss-order-move="' . $direction . '"]', $row);

        return ($hit && $hit->length === 1) ? $hit->item(0) : null;
    }

    /**
     * EVERY TEST BELOW THAT ITERATES THE CONTROLS NEEDS THIS, and two of them were written without
     * it and PASSED against the unfixed screen — the loop body simply never ran, so "no control
     * is unnamed" and "no control is wrongly inert" were both true of a screen with no controls at
     * all. Measured, not anticipated: the first run of this file reported 8 failed, 5 passed, and
     * two of those five were these.
     *
     * @param  list<\DOMElement>  $rows
     */
    private function assertEveryRowHasBothControls(\DOMXPath $xpath, array $rows): void
    {
        $this->assertNotSame([], $rows, 'PREMISE: no section rows were rendered.');

        $found = 0;

        foreach ($rows as $row) {
            foreach ($xpath->query('.//*[@data-ss-order-move]', $row) ?: [] as $ignored) {
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
     * WITHOUT THIS EVERY ASSERTION BELOW IS A STATEMENT ABOUT AN EMPTY LIST. The screen answers 200
     * with the layout's chrome whatever happens inside the card, and `explode(',', '')` renders one
     * blank row, so "there are no rows missing a move button" is true of a screen with no rows.
     */
    #[Test]
    public function test_the_screen_renders_the_themes_own_sections(): void
    {
        $xpath = $this->dom($this->render());
        $rows = $this->rows($xpath);

        $this->assertCount(count(self::STYLIQUE_HOME), $rows,
            'The order screen did not render stylique/home\'s nine declared sections. Every '
            . 'assertion in this file is about these rows, so a collapsed list must fail here '
            . 'rather than turn the rest green.');

        $this->assertSame(
            self::STYLIQUE_HOME,
            array_map(static fn (\DOMElement $li) => $li->getAttribute('data-id'), $rows),
            'The rendered rows are not the nine section tokens pages.json declares for '
            . 'stylique/home, in order. The hidden input the form posts is built from exactly '
            . 'these values, so they are the subject of the whole screen.'
        );
    }

    /* ======================================================= WCAG 2.1.1 — operable by keyboard */

    /**
     * REACHABLE BY TAB, which for this control means being a real `button`. A `div` with a click
     * handler, a `span[role=button]` without a tabindex, or an anchor with no `href` are all
     * clickable and none of them is in the tab order — and all three read as a working control in
     * a screenshot.
     */
    #[Test]
    public function test_every_section_row_carries_a_native_move_up_and_move_down_button(): void
    {
        $xpath = $this->dom($this->render());
        $rows = $this->rows($xpath);
        $problems = [];

        foreach ($rows as $i => $row) {
            foreach (['up', 'down'] as $direction) {
                $control = $this->moveControl($xpath, $row, $direction);

                if (! $control) {
                    $problems[] = "row {$i} ({$row->getAttribute('data-id')}): no single "
                        . "data-ss-order-move=\"{$direction}\" control";

                    continue;
                }

                if (strtolower($control->nodeName) !== 'button') {
                    $problems[] = "row {$i}: the {$direction} control is a <{$control->nodeName}>, "
                        . 'which is not in the tab order unless it is given one';
                }

                if ($control->getAttribute('type') !== 'button') {
                    $problems[] = "row {$i}: the {$direction} control has no type=\"button\", so "
                        . 'activating it submits the surrounding Save Changes form';
                }

                $tabindex = $control->getAttribute('tabindex');

                if ($tabindex !== '' && (int) $tabindex < 0) {
                    $problems[] = "row {$i}: the {$direction} control has tabindex=\"{$tabindex}\", "
                        . 'which removes it from the tab order';
                }
            }
        }

        $this->assertSame([], $problems,
            "The section rows cannot all be reordered from the keyboard:\n  "
            . implode("\n  ", $problems)
            . "\n\nThis is the WCAG 2.1.1 failure GAP-100 names. The screen's only reorder "
            . 'affordance was a jQuery UI drag.');
    }

    /**
     * AN ACCESSIBLE NAME THAT NAMES THE SECTION, not just the direction. Nine rows of "Move up"
     * is a list of nine identically-named buttons: a screen-reader user tabbing through it is
     * told the verb and never the subject.
     */
    #[Test]
    public function test_each_move_control_names_its_direction_and_its_section(): void
    {
        $xpath = $this->dom($this->render());
        $rows = $this->rows($xpath);
        $this->assertEveryRowHasBothControls($xpath, $rows);
        $problems = [];

        foreach ($rows as $i => $row) {
            $label = ucfirst(str_replace('_', ' ', $row->getAttribute('data-id')));
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
                        . "section \"{$label}\"";
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
     * so the name stays stable while the position changes under it.
     */
    #[Test]
    public function test_each_row_states_its_position_and_both_controls_point_at_it(): void
    {
        $xpath = $this->dom($this->render());
        $rows = $this->rows($xpath);
        $this->assertEveryRowHasBothControls($xpath, $rows);
        $total = count($rows);
        $problems = [];
        $ids = [];

        foreach ($rows as $i => $row) {
            $hit = $xpath->query('.//*[@data-ss-order-position]', $row);

            if (! $hit || $hit->length !== 1) {
                $problems[] = "row {$i}: no single data-ss-order-position element";

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
     * `aria-disabled` keeps it focusable and inert, which is why the handler also has to refuse it.
     */
    #[Test]
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
     * THE MOVE HAS TO BE AUDIBLE. A reorder changes nothing a screen reader is told about on its
     * own: the row moves silently and focus follows it, so without an announcement the user has no
     * evidence anything happened.
     */
    #[Test]
    public function test_the_screen_carries_a_polite_live_region_for_the_move_announcement(): void
    {
        $xpath = $this->dom($this->render());

        $region = $xpath->query('//*[@data-ss-order-announce]');

        $this->assertNotFalse($region);
        $this->assertSame(1, $region->length,
            'The order screen has no single live region for announcing a move.');

        $node = $region->item(0);

        $this->assertSame('polite', $node->getAttribute('aria-live'),
            'The announcement region is not aria-live="polite", so a move is either not announced '
            . 'or interrupts whatever the user was reading.');

        $this->assertSame('status', $node->getAttribute('role'),
            'The announcement region has no role="status".');

        $this->assertStringContainsString('ss-visually-hidden', $node->getAttribute('class'),
            'The announcement region is not visually hidden, so it prints on the page.');
    }

    /* ================================================ both directions: Arabic, RTL, unmirrored */

    /**
     * RTL MIRRORS THE INLINE AXIS AND LEAVES THE BLOCK AXIS ALONE, so "up" is up in both
     * directions and the control must NOT swap. The repository's other keyboard reorder
     * (`store-syria-ds.js:2037-2044`, column headers) does invert its keys — because Left and
     * Right are inline-axis keys and the first column paints on the right in Arabic. Carrying that
     * inversion over to a vertical list would be the same mistake in reverse.
     *
     * What is asserted is the property rather than the absence of a line of code: the Arabic
     * render is really RTL, and the up control still precedes the down control in document order
     * on every row, with the same tokens.
     */
    #[Test]
    public function test_the_move_semantics_are_not_mirrored_in_arabic(): void
    {
        $html = $this->render('ar');

        $this->assertStringContainsString('dir="rtl"', $html,
            'PREMISE: the Arabic render is not right-to-left, so this test is measuring an LTR '
            . 'page. $setting[\'currantLang\'] is read from users.language, not from the session.');

        $xpath = $this->dom($html);
        $rows = $this->rows($xpath);

        $this->assertCount(count(self::STYLIQUE_HOME), $rows,
            'PREMISE: the Arabic render has no section rows.');

        $this->assertEveryRowHasBothControls($xpath, $rows);

        $problems = [];

        foreach ($rows as $i => $row) {
            $order = [];

            foreach ($xpath->query('.//*[@data-ss-order-move]', $row) ?: [] as $control) {
                $order[] = $control->getAttribute('data-ss-order-move');
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

    /* ================================================== persisted the same way the drag persists */

    /**
     * THE KEYBOARD MOVE MUST REACH THE SAME HIDDEN INPUT THE DRAG WRITES, because that input is
     * the whole persistence path: the form posts `orders` to `theme.customize.update.order`, which
     * validates the tokens against the theme and upserts `theme_customize_orders`. A keyboard path
     * that reorders the DOM and not the input is a control the merchant watches work and then save
     * nothing — the exact shape of defect P03-B-06 already records for this screen's output.
     *
     * The ids are read out of the RENDERED page rather than out of the view, so this is the
     * contract the browser gets.
     */
    #[Test]
    public function test_the_hidden_input_the_form_posts_is_present_and_holds_the_rendered_order(): void
    {
        $xpath = $this->dom($this->render());

        $hidden = $xpath->query('//input[@id="hidden_order"][@name="orders"]');

        $this->assertNotFalse($hidden);
        $this->assertSame(1, $hidden->length,
            'The order screen no longer renders the hidden `orders` input, so there is nothing for '
            . 'either the drag or the keyboard path to write.');

        $this->assertSame(
            implode(',', self::STYLIQUE_HOME),
            $hidden->item(0)->getAttribute('value'),
            'The hidden input does not carry the rendered order, so the first Save Changes after '
            . 'a page load would post something other than what is on screen.'
        );

        $slug = $xpath->query('//input[@name="page_slug"]');

        $this->assertSame(1, $slug->length);
        $this->assertSame('home', $slug->item(0)->getAttribute('value'),
            'The posted page_slug is not the page being edited.');
    }
}
