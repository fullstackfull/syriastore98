<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * THE 17 MERCHANT DATATABLES HAD AN EMPTY STATE IN ENGLISH, UNSTYLED.  P2.5-WAVE-6a
 * =================================================================================================
 *
 * `simple-datatables` renders its own empty row from `setMessage()`:
 *
 *     <tr><td class="dataTables-empty" colspan="{headings}">{label}</td></tr>
 *
 * The colspan is taken from the live heading count, so the ROW was always correct. Two things about
 * it were not, both measured:
 *
 *   * the label was the library's hard-coded ENGLISH default, `noRows: "No Data Found"`. `noRows`
 *     was overridden NOWHERE in this repository, so an Arabic merchant read English on every empty
 *     table in the product.
 *   * `td.dataTables-empty` appeared in NEITHER first-party stylesheet, so the cell inherited the
 *     row treatment: body-size text, start-aligned, body colour. A merchant with no orders saw a
 *     table head and one line of English.
 *
 * WHY THE FIX IS NOT `@forelse`/`@empty` IN THE VIEWS, which is what the WAVE-6 work-list row
 * ("seven headers over an empty body") implies. That row is wrong about the symptom: these four
 * tables are not bodiless, the library writes a message into them. And a hand-written `@empty` row
 * would be actively WRONG here -- `custom.js` initialises every `.dataTable`, and the library
 * REBUILDS the tbody from the rows it parsed, so the injected row would become a DATA row: counted
 * in "Showing 1 to 1 of 1 entries", sortable and searchable. The library already had the mechanism;
 * it needed words and a treatment.
 *
 * WHAT THIS TEST GUARDS, and each assertion names the defect it would catch:
 *
 *   1. the four strings are published by the layout, each through `__()`;
 *   2. EVERY `simpleDatatables.DataTable(` construction site passes the labels -- asserted by
 *      counting sites against labelled sites, so adding a fifth table without labels FAILS rather
 *      than being masked by the four that have them;
 *   3. the label text is escaped before it is injected as HTML;
 *   4. the cell is styled;
 *   5. a PREMISE: the vendored library really does default to `"No Data Found"`. If a library
 *      update changes that, this fails and somebody re-reads instead of trusting this docblock.
 *
 * COMMENTS ARE STRIPPED BEFORE EVERY CODE ASSERTION. This project has shipped, more than once, a
 * guard that passed on the prose explaining it rather than on the code -- including one in the
 * Flutter CI assertions on this same branch. The block comment added to `custom.js` by this change
 * quotes `noRows: "No Data Found"` verbatim, so an unstripped search for it would find the comment
 * and pass with the fix reverted.
 *
 * A UNIT TEST reading source files: no database, no container, no module loader.
 */
class DataTableEmptyStateTest extends TestCase
{
    private const LAYOUT = 'resources/views/layouts/app.blade.php';
    private const SCRIPT = 'public/js/custom.js';
    private const SHEET = 'public/css/store-syria-ds.css';
    private const LIBRARY = 'public/assets/js/plugins/simple-datatables.js';

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path, $relative . ' is missing');

        return (string) file_get_contents($path);
    }

    /** JavaScript with block and line comments removed, so an assertion cannot pass on prose. */
    private function code(string $relative): string
    {
        $source = $this->read($relative);
        $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);

        return (string) preg_replace('#^\s*//.*$#m', '', $source);
    }

    /** CSS with block comments removed. */
    private function styles(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $this->read(self::SHEET));
    }

    // =============================================================================================
    //  THE PREMISE. If this fails, the finding has changed and the rest of the file is stale.
    // =============================================================================================

    public function test_premise_the_vendored_library_still_defaults_to_an_english_label(): void
    {
        $library = $this->read(self::LIBRARY);

        $this->assertStringContainsString('noRows:"No Data Found"', $library,
            'the vendored simple-datatables no longer carries the English default this change '
            . 'exists to override. Re-read setMessage() and the labels block before trusting '
            . 'anything in this file');

        $this->assertStringContainsString('class="dataTables-empty" colspan=', $library,
            'setMessage() no longer renders the cell this change styles, so the CSS selector and '
            . 'the colspan reasoning in the docblock are both stale');
    }

    public function test_premise_the_label_is_injected_as_html_which_is_what_lets_it_carry_markup(): void
    {
        // `setMessage` builds the row with `html:` -- that is the whole reason the label can be
        // the project's own empty-state markup rather than a bare string. If a library update
        // switches it to a text node, the markup would render as visible angle brackets.
        $this->assertMatchesRegularExpression('/setMessage\(t\)\{.{0,400}html:/s', $this->read(self::LIBRARY),
            'setMessage no longer injects its label as HTML, so the markup this change passes '
            . 'would render as literal text on every empty table in the product');
    }

    // =============================================================================================
    //  THE FIX
    // =============================================================================================

    public function test_the_layout_publishes_all_four_strings_through_the_translator(): void
    {
        $layout = $this->read(self::LAYOUT);

        foreach (['dt-no-rows', 'dt-no-rows-body', 'dt-no-results', 'dt-no-results-body'] as $name) {
            $this->assertMatchesRegularExpression(
                '/<meta name="' . preg_quote($name, '/') . '" content="\{\{ __\(/',
                $layout,
                "the {$name} meta is absent, or its content does not go through __() -- which is "
                . 'the whole point: an untranslated string here is the defect this change repaired'
            );
        }
    }

    public function test_every_datatable_construction_site_passes_the_labels(): void
    {
        $script = $this->code(self::SCRIPT);

        $sites = preg_match_all('/new\s+simpleDatatables\.DataTable\(/', $script);
        $labelled = preg_match_all('/labels:\s*ssDataTableLabels\(\)/', $script);

        $this->assertGreaterThan(0, $sites, 'no DataTable is constructed in custom.js any more');
        $this->assertSame($sites, $labelled,
            "custom.js constructs {$sites} DataTables but only {$labelled} of them are given the "
            . 'translated labels. A table initialised without them falls back to the library\'s '
            . 'English "No Data Found", which is exactly the defect P2.5-WAVE-6a repaired — and '
            . 'the count is asserted rather than the presence, so a NEW init site added without '
            . 'labels fails here instead of hiding behind the ones that have them');
    }

    public function test_the_builder_escapes_the_label_before_injecting_it_as_html(): void
    {
        $script = $this->code(self::SCRIPT);

        $this->assertStringContainsString('function ssDataTableEscape(', $script,
            'the escape helper is gone, so a translation containing markup would be injected as '
            . 'HTML into every empty table');

        // Both paragraphs must go through it, not just the title.
        $this->assertSame(2, preg_match_all('/ssDataTableEscape\((?:title|body)\)/', $script),
            'the title and the body must BOTH be escaped before injection. Finding fewer than two '
            . 'calls means one of them is interpolated raw');
    }

    public function test_an_absent_meta_leaves_the_library_default_rather_than_an_empty_state_with_no_words(): void
    {
        $script = $this->code(self::SCRIPT);

        // `ssDataTableEmptyMarkup` must refuse to build anything without a title, and
        // `ssDataTableLabels` must omit the key rather than setting it to null/''. A view reached
        // through another layout (theme_layout loads this same file) would otherwise render an
        // icon and a blank line where the message used to be.
        $this->assertMatchesRegularExpression('/if\s*\(!title\)\s*\{\s*return null;/', $script,
            'ssDataTableEmptyMarkup no longer refuses an empty title, so a layout that does not '
            . 'publish the metas would render an empty state containing no words');

        $this->assertMatchesRegularExpression('/if\s*\(noRows\)\s*\{\s*labels\.noRows\s*=/', $script,
            'ssDataTableLabels sets noRows unconditionally. Passing an empty label to the library '
            . 'OVERRIDES its own default, so the message would disappear entirely rather than '
            . 'falling back');
    }

    public function test_every_icon_the_builder_names_exists_in_the_shipped_font(): void
    {
        // THE DEFECT THIS EXISTS FOR, found by looking at a screenshot and by nothing else: the
        // no-results state first asked for `ti ti-search-off`, which reads perfectly and is NOT in
        // `public/assets/fonts/tabler-icons.min.css`. An icon-font class that matches no `:before`
        // rule renders as an EMPTY CIRCLE -- the element exists, the class is right, and the DOM
        // probe reported the state correct. This is the same failure the Flutter golden work hit
        // from the other direction (MaterialIcons unloaded, icons as empty squares), so it is
        // asserted here rather than trusted twice.
        $font = $this->read('public/assets/fonts/tabler-icons.min.css');
        $script = $this->code(self::SCRIPT);

        $this->assertSame(2, preg_match_all(
            "/ssDataTableEmptyMarkup\([^;]*?,\s*'([a-z0-9-]+)'\s*\)/s", $script, $matches),
            'expected exactly two ssDataTableEmptyMarkup call sites with a literal icon name. If a '
            . 'third was added, or an icon is now passed as a variable, extend this assertion '
            . 'rather than deleting it');

        foreach ($matches[1] as $icon) {
            $this->assertStringContainsString('.ti-' . $icon . ':before', $font,
                "the empty state asks for `ti ti-{$icon}`, which is NOT in the bundled Tabler "
                . 'font. It will render as an empty circle: the <i> is present and the class is '
                . 'spelt right, so nothing but a screenshot would show it. Pick a glyph the '
                . 'shipped font actually carries');
        }
    }

    public function test_the_empty_cell_is_styled_by_the_design_system(): void
    {
        $styles = $this->styles();

        $this->assertMatchesRegularExpression('/td\.dataTables-empty:has\(\.ss-empty\)\s*\{[^}]*text-align:\s*center/s', $styles,
            'td.dataTables-empty is not centred. A td inherits the row\'s start alignment, and '
            . '.ss-empty centres its children without centring their text, so the title and '
            . 'subtitle would sit against the start edge of a full-width cell');

        // THE SELECTOR, NOT JUST THE DECLARATION -- and this assertion exists because the first
        // version of it did not. It asserted `td.dataTables-empty { ... padding: 0 }` and PASSED,
        // while the browser computed `8px 10px`: the plugin's
        // `.dataTable-table > tbody > tr > td` is specificity 0-1-3 and `td.dataTables-empty` is
        // 0-1-1, so the declaration was present and losing. A source-reading test cannot compute
        // a cascade, so it asserts the one thing that makes the rule win -- that the padding is
        // declared through the plugin's own selector prefix. What PROVED it is the live capture in
        // docs/development/phase-2.5/evidence/wave-6a.
        $this->assertMatchesRegularExpression(
            '/\.dataTable-table\s*>\s*tbody\s*>\s*tr\s*>\s*td\.dataTables-empty:has\(\.ss-empty\)\s*\{[^}]*padding:\s*0/s',
            $styles,
            'the padding is not declared through the plugin\'s own selector prefix '
            . '(`.dataTable-table > tbody > tr > td.dataTables-empty`). Without it the rule is '
            . 'specificity 0-1-1 against the plugin\'s 0-1-3 and SILENTLY LOSES — which is '
            . 'exactly what shipped in the first version of this change, with a passing test '
            . 'beside it. Do not satisfy this with !important; satisfy it with the prefix');

        $this->assertDoesNotMatchRegularExpression('/dataTables-empty\s*\{[^}]*!important/s', $styles,
            'the empty cell uses !important. The selector prefix is enough, and an !important '
            . 'here would outrank anything a later surface needs to say about this cell');
    }

    public function test_the_empty_state_reuses_the_existing_component_vocabulary_and_invents_no_colours(): void
    {
        $script = $this->code(self::SCRIPT);

        // The classes must be the ones components/ss/empty.blade.php uses, because that is what
        // makes the state follow the theme with no dark arm of its own.
        foreach (['ss-scope ss-empty', 'ss-empty__icon', 'ss-empty__body', 'ss-empty__title', 'ss-page-header__subtitle'] as $class) {
            $this->assertStringContainsString($class, $script,
                "the empty markup no longer uses `{$class}`. Every colour in this state comes from "
                . 'those classes and the --ss-* tokens they read, which are redefined once under '
                . 'data-ss-theme="dark"; a bespoke class here would need its own dark arm');
        }

        // And no hard-coded colour may appear in the builder.
        $builder = (string) preg_replace('/.*function ssDataTableEmptyMarkup/s', '', $script);
        $builder = (string) preg_replace('/function ssDataTableLabels.*/s', '', $builder);
        $this->assertSame(0, preg_match_all('/#[0-9a-fA-F]{3,6}\b/', $builder),
            'the empty-state builder hard-codes a colour. It must take every colour from the '
            . '.ss-empty classes so both themes are served by one definition');
    }
}
