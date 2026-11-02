<?php

namespace Tests\Unit\Ui;

use Tests\TestCase;

/**
 * P2-06-6 — column order is a saved preference, and the columns that must not move cannot.
 * =================================================================================================
 *
 * THE VERSION IS NOT A DETAIL. The installed bundle is DataTables **1.10.20**, and ColReorder's
 * dependency on DataTables changes mid-line: 1.5.0–1.5.4 declare `datatables.net ^1.10.15`,
 * while 1.5.5 and later require `>= 1.11.3`. Taking "the latest" would have paired an extension
 * that expects 1.11 with a core that is 1.10 — the kind of mismatch that works on the page you
 * tested and fails on the one you did not. 1.5.4 was chosen by reading the registry metadata for
 * every 1.5.x release, and this test holds the pairing so an upgrade of either side has to be a
 * deliberate edit here.
 *
 * THE ACTION COLUMN IS THE HAZARD THE WHOLE FEATURE TURNS ON. It carries `ss-col-pinned`, which
 * is `position: sticky; inset-inline-end: 0` — it is pinned to the TRAILING EDGE. Dragged
 * anywhere else it would still stick to that edge while sitting in the middle of the table,
 * overlapping whatever scrolled beneath it, and the Edit and Delete controls that P2-03-1
 * measured as physically unreachable without the pin (848 of 1168 Arabic pixels off-screen at
 * 390px; a real click on Edit navigating nowhere in all eight configurations) would be
 * unreachable again. `iFixedColumnsRight` is not a refinement; it is the condition that makes
 * reordering safe to offer.
 */
class ColumnReorderContractTest extends TestCase
{
    /**
     * Block comments and whole-line `//` comments blanked, so an assertion about the CODE
     * cannot be satisfied by prose ABOUT the code. Line comments are anchored to the start of
     * a line so a `//` inside a string literal is left alone.
     */
    private function withoutComments(string $source): string
    {
        $source = (string) preg_replace('~/\*.*?\*/~s', '', $source);

        return (string) preg_replace('~^[ \t]*//.*$~m', '', $source);
    }

    private function vendorPath(string $file): string
    {
        return base_path('public/vendor/datatables/' . $file);
    }

    public function test_the_extension_is_vendored_locally_and_not_fetched_from_a_cdn(): void
    {
        $this->assertFileExists($this->vendorPath('dataTables.colReorder.min.js'),
            'ColReorder is not vendored. It must be served from this repository, never a CDN.');

        $html = (string) file_get_contents(
            base_path('resources/views/layouts/includes/datatable-js.blade.php'));

        $this->assertStringContainsString("asset('vendor/datatables/dataTables.colReorder.min.js')", $html,
            'the layout does not load the vendored file');

        foreach (['cdn.datatables.net', 'cdnjs', 'unpkg', 'jsdelivr', '//cdn.'] as $cdn) {
            $this->assertStringNotContainsString($cdn, $html,
                "the DataTables includes reference `{$cdn}`, and the owner's decision is that "
                . 'nothing here may depend on an external CDN');
        }
    }

    /** The licence must travel with the code, both as the banner and as the full text. */
    public function test_the_licence_notice_is_kept(): void
    {
        $js = (string) file_get_contents($this->vendorPath('dataTables.colReorder.min.js'));

        $this->assertStringContainsString('ColReorder 1.5.4', $js,
            'the minified file lost its version banner');
        $this->assertStringContainsString('SpryMedia Ltd', $js,
            'the minified file lost its copyright line');

        $licence = $this->vendorPath('dataTables.colReorder.LICENSE.txt');
        $this->assertFileExists($licence, 'the full licence text is not vendored beside the code');
        $this->assertStringContainsString('MIT License', (string) file_get_contents($licence));
    }

    /**
     * THE PAIRING, asserted in both directions. A DataTables upgrade without a ColReorder one —
     * or the reverse — has to come through this test.
     */
    public function test_the_extension_version_matches_the_installed_datatables(): void
    {
        $core = (string) file_get_contents($this->vendorPath('datatables.min.js'));
        $this->assertStringContainsString('DataTables 1.10.20', $core,
            'the DataTables core is no longer 1.10.20. ColReorder 1.5.4 is pinned to it because '
            . '1.5.5+ require datatables.net >= 1.11.3 — re-check the pairing before changing this.');

        $ext = (string) file_get_contents($this->vendorPath('dataTables.colReorder.min.js'));
        $this->assertStringContainsString('ColReorder 1.5.4', $ext,
            'ColReorder is no longer 1.5.4. Versions from 1.5.5 require DataTables >= 1.11.3, '
            . 'which this installation is not.');
    }

    /**
     * THE OPTION REACHES EVERY TABLE, through the one builder, with both guards set.
     *
     * Asserted on the SOURCE of `buildDataTable()` rather than on a rendered table, because that
     * function is what all 68 DataTables in the application are built by — 39 host and 29 inside
     * vendored modules — and the point of putting the option there is that no
     * `packages/workdo/` file has to be touched to reach the vendored twenty-nine.
     */
    public function test_every_table_gets_the_option_with_the_action_column_held(): void
    {
        $helper = (string) file_get_contents(base_path('app/Helper/helper.php'));

        $this->assertStringContainsString("'colReorder' => [", $helper,
            '`buildDataTable()` does not set the colReorder option, so no table can reorder');

        $this->assertStringContainsString("'iFixedColumnsRight' => 1", $helper,
            'the ACTION column is not held. It is `ss-col-pinned` — sticky to the trailing edge '
            . '— so moving it leaves a column pinned to an edge it no longer sits at, over the '
            . 'rows scrolling beneath it, with Edit and Delete unreachable again.');

        $this->assertStringContainsString("'iFixedColumnsLeft' => \$fixedLeft", $helper,
            'the leading locked columns are not held');
    }

    /**
     * THE PREFERENCE RIDES THE EXISTING MECHANISM, which is what the owner's decision asked for
     * and what keeps this a small change: ColReorder writes through DataTables' own
     * `_fnStateSave`, so the order persists with the sort, page length and column visibility
     * P2-06-4 already saves — and inherits that mechanism's discard guard.
     */
    public function test_the_order_is_saved_through_the_existing_state_mechanism(): void
    {
        $ext = (string) file_get_contents($this->vendorPath('dataTables.colReorder.min.js'));

        $this->assertStringContainsString('_fnStateSave', $ext,
            'this build of ColReorder does not hook DataTables state saving, so the column '
            . 'order would not persist and a separate store would be needed');

        $helper = (string) file_get_contents(base_path('app/Helper/helper.php'));
        $this->assertStringContainsString("'stateSave' => true", $helper,
            'state saving is off, so there is nothing for the column order to ride');
    }

    /**
     * THE KEYBOARD PATH SAVES THE STATE ITSELF, because `colReorder.move()` does not. The
     * extension's own drag does it in two steps -- `fnColReorder(from, to, true)` and then
     * `oApi._fnSaveState(...)` (dataTables.colReorder 1.5.4, lines 1109 and 1119) -- and the
     * public `move()` API is only the first of them. Measured on a clean browser profile:
     * `move(f, t)` AND `move(f, t, true)` both reorder the header row and both leave the saved
     * `ColReorder` array at the identity order, so a keyboard move was lost on the next page
     * load while a mouse drag survived.
     *
     * This is asserted on the source because the proof is a browser run and CI has none: the
     * live harness bites (removing `state.save()` fails four of its reload cases), but nothing
     * in CI would notice the line being dropped during a tidy-up.
     */
    public function test_the_keyboard_move_is_marked_final_and_saves_the_state(): void
    {
        $js = (string) file_get_contents(base_path('public/js/store-syria-ds.js'));
        $start = strpos($js, 'function wireColumnReorderKeys');

        $this->assertNotFalse($start, 'premise: the keyboard wiring was renamed or removed');

        /* COMMENTS ARE STRIPPED BEFORE ASSERTING, and this test caught itself needing it. The
           docblock on the implementation explains the fix in prose that contains the very
           string being searched for ("`state.save()` is the public spelling of the second"), so
           the first version of this assertion passed with the call REMOVED -- it was matching my
           own comment. Measured by reverting: 7 passed with the line deleted. Blanking the
           comments is the same discipline the Blade scanners already use here. */
        $body = $this->withoutComments(substr($js, $start, 3000));

        $this->assertStringContainsString('colReorder.move(from, to, true)', $body,
            'the keyboard move is not marked as a final reorder (drop = true), which is how '
            . 'the extension distinguishes a drop from a live drag');
        $this->assertStringContainsString('state.save()', $body,
            'the keyboard move does not save the state, so the column order a merchant sets '
            . 'with the keyboard is lost on the next page load while a drag survives');
    }

    /**
     * COLUMN ORDER IS NOT STRIPPED FROM THE SAVED STATE. `sanitizeTableState` deliberately
     * removes the search term and the page number; adding column order to that list would make
     * the feature silently do nothing, and it is exactly the edit someone would make while
     * tidying. Presentation state, no tenant data — it stays.
     */
    public function test_the_sanitiser_does_not_strip_the_column_order(): void
    {
        $js = (string) file_get_contents(base_path('public/js/store-syria-ds.js'));
        $start = strpos($js, 'function sanitizeTableState');

        $this->assertNotFalse($start, 'premise: the sanitiser was renamed or removed');

        $body = substr($js, $start, 1400);

        $this->assertStringNotContainsString('ColReorder', $body,
            'the state sanitiser strips the column order, so the preference cannot persist');
        $this->assertStringNotContainsString('colReorder', $body,
            'the state sanitiser strips the column order, so the preference cannot persist');
    }
}
