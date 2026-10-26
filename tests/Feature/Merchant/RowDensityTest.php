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
         * yajra decides string-vs-JS by `Helper::isJavascript()`, which only treats a value as
         * raw JS when it starts with `function` / `$` / `$.`. A quoted action would appear as
         * `"action":"function …"` and DataTables would ignore it -- the button present and
         * inert, which is exactly the failure mode this assertion exists for.
         */
        $this->assertStringContainsString('"action":function', $script,
            'the density action was emitted as a STRING, so the button will do nothing');

        $this->assertStringContainsString('tableDensity', $script,
            'the action does not reach StoreSyriaDS.tableDensity');
    }

    public function test_the_two_state_hooks_are_distinct_and_both_carry_density(): void
    {
        $options = $this->table()->getOptions();

        $save = (string) ($options['stateSaveParams'] ?? '');
        $load = (string) ($options['stateLoadParams'] ?? '');

        $this->assertNotSame('', $save);
        $this->assertNotSame('', $load);

        /*
         * THEY USED TO BE THE SAME FUNCTION, and that is why this case is here. P2-06-4 gave
         * both legs one shared sanitiser, which is right for the search term -- it is stripped
         * in both directions -- but wrong for density, where saving WRITES the live value and
         * loading READS a stored one. Sharing one hook stored no density at all.
         */
        $this->assertNotSame($save, $load,
            'both state hooks are the same function again, so one of the two directions is wrong');

        $this->assertStringContainsString('tableDensity.save', $save);
        $this->assertStringContainsString('tableDensity.load', $load);

        // The sanitiser must survive on BOTH legs: density was added beside it, not instead.
        $this->assertStringContainsString('sanitizeTableState', $save);
        $this->assertStringContainsString('sanitizeTableState', $load);
    }

    public function test_the_stylesheet_and_the_behaviour_module_carry_the_rule(): void
    {
        $css = (string) file_get_contents(base_path('public/css/store-syria-ds.css'));
        $js = (string) file_get_contents(base_path('public/js/store-syria-ds.js'));

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
