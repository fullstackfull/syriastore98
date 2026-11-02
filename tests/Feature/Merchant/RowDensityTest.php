<?php

namespace Tests\Feature\Merchant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-03-10 -- row density on the merchant lists.
 * =============================================================================================
 *
 * THE HALF OF P2-03-1 THAT WAS NOT DONE. That slice made the row actions reachable and left the
 * rows at 55px with no control, measured identically at 1440 / 1280 / 992 / 768 / 390.
 *
 * WHAT THE HEIGHT IS MADE OF, measured in the running application rather than reasoned:
 *
 *     comfortable   32 + 2 * 11.2 + 1  =  55px
 *     compact       32 + 2 * 4    + 1  =  41px
 *
 * The 32 is the ROW-ACTION control group in the last cell -- nothing in a product or order row
 * is taller -- and the +1 is the cell's own bottom border, which counts because the `td` is
 * `content-box`. So the only honest way to compact the row is to cut the padding and leave the
 * control alone, which also keeps the 24px touch target the acceptance asks for, with 8px spare.
 *
 * WHAT THIS FILE CAN AND CANNOT PROVE. It proves the WIRING: that the control exists on every
 * table the shared helper builds, that its action and both state hooks reach the page as real
 * JavaScript rather than quoted strings, and that the stylesheet and behaviour module carry the
 * rule. It cannot prove the rendered height, the persistence across a reload or the touch
 * target -- those are browser facts, and they are driven by
 * `docs/development/phase-2/tools/check-row-density-live.cjs` (35 cases, bitten three ways).
 * A test that asserted a pixel height from PHP would be asserting its own arithmetic.
 */
class RowDensityTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function table()
    {
        return buildDataTable(
            'fixture-table',
            app(\Yajra\DataTables\Html\Builder::class),
            buildDataTableColumn(['name' => ['title' => 'Name']], false)
        );
    }

    public function test_the_shared_helper_puts_a_density_toggle_on_every_table(): void
    {
        $options = $this->table()->getOptions();

        $buttons = $options['buttons'] ?? [];
        $this->assertNotEmpty($buttons, 'the helper emitted no buttons at all');

        $density = null;
        foreach ($buttons as $button) {
            if (is_array($button) && str_contains((string) ($button['className'] ?? ''), 'ss-density')) {
                $density = $button;
                break;
            }
        }

        $this->assertNotNull(
            $density,
            'no button carries the `ss-density` class, so no merchant table has a density control'
        );

        // The class is the hook the stylesheet and the behaviour module both look for.
        $this->assertStringContainsString('ss-density', (string) $density['className']);

        /*
         * A NAME, AND A TRANSLATED ONE. Buttons 1.3.1 accepts an `attr` config and silently
         * drops it -- measured in P2-03-1 -- so the accessible name is set from `initComplete`
         * off this `titleAttr`. If the title were absent the control would reach a screen
         * reader as an unnamed icon, which is the F38/F39 defect again.
         */
        $this->assertArrayHasKey('titleAttr', $density);
        $this->assertNotSame('', trim((string) $density['titleAttr']));

        // The icon must exist in the bundled font; IconNamesExistTest holds the whole file to
        // that, this pins the one this slice added.
        $this->assertStringContainsString('ti-line-height', (string) $density['text']);
        $this->assertStringContainsString('aria-hidden="true"', (string) $density['text']);
    }

    public function test_the_toggle_action_reaches_the_page_as_real_javascript(): void
    {
        $script = (string) $this->table()->generateScripts();

        /*
         * ONE REGEX, NOT TWO SUBSTRINGS, and that is a correction. This asserted
         * `"action":function` and `tableDensity` separately, and review showed both survive the
         * failure they exist to catch: `bulkDeleteForm()` also emits an `action` function, so the
         * first stops being about THIS button the moment a test base activates BulkDelete; and if
         * the density action were emitted as a quoted string, the second still passes, because
         * the quoted literal contains the word `tableDensity`. Tying them together is what makes
         * it a test of this button being real JavaScript.
         *
         * yajra decides string-vs-JS by `Helper::isJavascript()`, which only treats a value as
         * raw JS when it starts with `function` / `$` / `$.`. A quoted action would appear as
         * `"action":"function …"` and DataTables would ignore it -- the button present and inert.
         */
        $this->assertMatchesRegularExpression(
            '/"action":function \(e, dt\) \{\s*if \(window\.StoreSyriaDS/',
            $script,
            'the density action is not an unquoted function reaching StoreSyriaDS -- either it was '
            . 'emitted as a string (DataTables will ignore it) or it no longer calls the module'
        );

        $this->assertStringContainsString('tableDensity.toggle', $script,
            'the action does not reach StoreSyriaDS.tableDensity.toggle');
    }

    public function test_density_is_saved_with_the_state_and_restored_only_from_an_accepted_one(): void
    {
        $options = $this->table()->getOptions();

        $save = (string) ($options['stateSaveParams'] ?? '');
        $load = (string) ($options['stateLoadParams'] ?? '');
        $loaded = (string) ($options['stateLoaded'] ?? '');

        // Saving: the sanitiser AND the density, on the object DataTables is about to store.
        $this->assertStringContainsString('sanitizeTableState', $save);
        $this->assertStringContainsString('tableDensity.save', $save);

        // The sanitiser still runs on the way IN, unchanged from P2-06-4.
        $this->assertStringContainsString('sanitizeTableState', $load);

        /*
         * THE RESTORE MUST BE ON `stateLoaded`, NOT `stateLoadParams`, and this case exists
         * because it was on the wrong one. Read out of the shipped datatables.min.js,
         * `stateLoadParams` fires BEFORE DataTables checks the state's column count and age, so a
         * restore there resurrects a density out of state DataTables has decided to discard --
         * the column set and the page length would expire and the density would not.
         * `stateLoaded` runs only on the accepted branch.
         */
        $this->assertNotSame('', $loaded, 'there is no stateLoaded hook, so the restore is elsewhere');
        $this->assertStringContainsString('tableDensity.load', $loaded);
        $this->assertStringNotContainsString('tableDensity.load', $load,
            'the density restore is back on stateLoadParams, which fires before DataTables has '
            . 'decided whether the stored state is acceptable at all');
    }

    public function test_the_stylesheet_and_the_behaviour_module_carry_the_rule(): void
    {
        /*
         * COMMENTS STRIPPED FIRST, and that is a correction rather than tidiness. These
         * assertions read both files whole, and review pointed out that every one of them is
         * satisfiable by a COMMENT that mentions the symbol -- and the comments in these files do
         * mention `tableDensity`, `densityState` and `applyDensity`. Comment the rule out, keep
         * the commentary, and the test stayed green. `DesignSystemStylesheetScopeTest` strips
         * `/* ... *` . `/` before parsing for exactly this reason.
         */
        $strip = static fn (string $source): string
            => (string) preg_replace('#/\*.*?\*/#s', '', $source);

        $css = $strip((string) file_get_contents(base_path('public/css/store-syria-ds.css')));
        $js = $strip((string) file_get_contents(base_path('public/js/store-syria-ds.js')));

        /*
         * COMPACT IS THE ONLY RULE. "Comfortable" must stay the absence of the attribute, so a
         * table nobody has touched -- and every page where the behaviour script does not run --
         * renders exactly as it did before this slice. A `[data-ss-density="comfortable"]` rule
         * appearing here would mean the stylesheet had started painting the default state, and
         * with it the vendored theme's own padding hard-coded into this file.
         */
        $this->assertStringContainsString('[data-ss-density="compact"]', $css);
        $this->assertStringNotContainsString('[data-ss-density="comfortable"]', $css,
            'a comfortable rule would hard-code the vendored padding and stop this being additive');

        $this->assertStringContainsString('padding-block: 4px', $css);

        // The four hooks the helper calls by name.
        foreach (['tableDensity', 'saveDensityState', 'loadDensityState', 'initTableDensity', 'toggleTableDensity'] as $symbol) {
            $this->assertStringContainsString($symbol, $js, "the behaviour module has no {$symbol}");
        }
    }
}
