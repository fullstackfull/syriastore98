<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * P2.5-WAVE-25 — the merchant modals, measured by OPENING them rather than by reading views.
 * ================================================================================================
 *
 * Most create/edit surfaces in this admin are not pages. They are `[data-ajax-popup]` modals, and
 * a sweep that follows `a[href]` finds none of them -- the first attempt at this discovered ZERO
 * destinations for exactly that reason. The second clicked every trigger on eleven list screens at
 * 320x568 and measured the modal that opened: 24 surfaces.
 *
 * WHAT WAS ALREADY RIGHT, and is worth stating because it was not assumed: every dialog measured
 * 304px wide at x=8 inside a 320px window, zero of the 24 produced page overflow, and zero had a
 * control past the viewport edge. The modal shell is sound.
 *
 * TWO DEFECT CLASSES CAME OUT OF IT.
 *
 * 1. TARGET SIZE on the role-permission grid. /roles/1/edit carries 123 checkboxes, MEASURED at
 *    122x 16x16 and 1x 18x18. WCAG 2.2 SC 2.5.8 (AA) wants 24x24 CSS px, or centres at least 24px
 *    apart under the spacing exception -- and the closest pair of centres measured 23.0px, with
 *    55 pairs under 24 at a 320px viewport and 28 under 24 at 1440px. So the exception does NOT
 *    rescue them and it fails at BOTH widths, which is why the repair is not inside a media query.
 *    It missed by one pixel; relying on 23-vs-24 would break on the next font or zoom change.
 *    After: 123 at 24x24, closest centres 27.6px, zero pairs under 24.
 *
 * 2. LABEL ASSOCIATION. Three controls had no programmatic name, each for a different reason:
 *      coupon/create       the coupon_code field had a PLACEHOLDER and nothing else
 *      taxes/create        `Form::label('')` renders `for=""`, which associates with nothing
 *      testimonial/create  a bare `<label>` sitting beside the select, with no `for`
 *
 *    A FOURTH READING WAS MY DETECTOR'S FAULT and is recorded so the next census excludes it:
 *    every modal reported two more "unlabelled" controls, and both are `<input type="submit">`
 *    and `<input type="button">` -- which ARE named, by their `value`. A census without that
 *    exclusion over-reports by two per modal, a third of the total.
 *
 * AND THE CENSUS THE THREE REPAIRS CAME OUT OF, pinned below: it was 127 real `Form::label('')`
 * call sites across 25 merchant views. WAVE 26 TOOK IT TO 21.
 *
 * MEASURED on /product/create across that repair, which is the only way the figure means
 * anything -- a count of source edits proves nothing about a rendered page:
 *
 *                         before   after
 *     labels                  57      57
 *     resolving to a control  41      53
 *     for="" (naming nothing) 15       3
 *     dangling for=           1        1   (pre-existing: `sub_images` has no control at all)
 *
 * AND THE FIRST RUN OF THAT REPAIR WAS WRONG, which the same measurement caught: dangling went
 * 1 -> 4. The analyser read `'id' => ...` only on the control's FIRST line, so three multi-line
 * selects whose explicit ids differ from their names (brand_id -> `brand-dropdown`, label_id ->
 * `label-dropdown`, tax_status -> `tax_id`) were given a `for` pointing at nothing. Widening the
 * window to the whole call found 32 such sites rather than 13 -- so 19 of 106 edits had the
 * wrong target. All 106 were reverted and re-applied from a clean base.
 *
 * RECORDED, NOT FIXED: /product/create and /product/edit carry SIX duplicate element ids
 * (`tax_id` twice, `preview_type` twice, `backColorPicker` three times). Measured identical
 * before and after this work, so it is pre-existing -- but a duplicate id makes `for` ambiguous
 * and belongs in whatever slice owns those screens.
 */
class ModalSurfaceAccessibilityTest extends TestCase
{
    /**
     * LOWERED 127 -> 21 BY WAVE 26, which is what the ratchet asked for. 106 of the 127 sites now
     * name the control they describe; the 21 that remain are the ones an analyser must not guess
     * at -- a label with no `Form::` control before the next label, which means either raw HTML
     * or a control GROUP that needs a fieldset or an aria-label rather than a `for`.
     *
     * It may fall further. It must never rise.
     */
    private const EMPTY_LABEL_CEILING = 21;

    public function test_the_permission_grid_meets_the_target_minimum(): void
    {
        $ds = $this->read('public/css/store-syria-ds.css');

        $this->assertMatchesRegularExpression(
            '/\.ss-permission-matrix input\[type="checkbox"\]\s*\{[^}]*inline-size:\s*1\.5rem[^}]*block-size:\s*1\.5rem/s',
            $this->declarations($ds),
            'The 24px target rule for the permission grid is gone. MEASURED without it: 123 '
            . 'checkboxes at 16x16 with the closest pair of centres 23.0px apart — 55 pairs under '
            . 'the 24px the SC 2.5.8 spacing exception needs, at a 320px viewport, and 28 under it '
            . 'at 1440px.');
    }

    /** It fails at both widths, so the rule must not be hidden behind a phone media query. */
    public function test_the_target_rule_is_not_scoped_to_phone_width(): void
    {
        $ds = $this->declarations($this->read('public/css/store-syria-ds.css'));

        $rule = strpos($ds, '.ss-permission-matrix input[type="checkbox"]');
        $this->assertNotFalse($rule, 'the rule is gone');

        // Everything after the last media query opening before the rule must have closed it.
        $before = substr($ds, 0, $rule);
        $this->assertSame(
            substr_count($before, '{'), substr_count($before, '}'),
            'The permission-grid target rule now sits inside an unclosed block — almost certainly '
            . 'a media query. It must apply at every width: the criterion was MEASURED as failing '
            . 'at 1440px as well as at 320px (28 pairs under 24px there).');
    }

    /** Both roles views carry the scope, and the name is not one the design system already owns. */
    public function test_both_roles_views_carry_the_scope(): void
    {
        foreach (['resources/views/roles/create.blade.php', 'resources/views/roles/edit.blade.php'] as $view) {
            $this->assertStringContainsString('ss-permission-matrix', $this->read($view),
                $view . ' has lost the permission-grid scope, so its checkboxes fall back to 16px.');
        }
    }

    /** The three repaired labels, each by the shape of its own defect. */
    public function test_the_three_repaired_controls_are_labelled(): void
    {
        $this->assertStringContainsString(
            "Form::label('auto-code', __('Coupon Code')",
            $this->read('resources/views/coupon/create.blade.php'),
            'The coupon code field is back to a placeholder with no label. A placeholder is not a '
            . 'label: it disappears on input and is not the field\'s accessible name.');

        $this->assertStringContainsString(
            "Form::label('name', __('Name')",
            $this->read('resources/views/taxes/create.blade.php'),
            "taxes/create is back to `Form::label('')`, which renders `for=\"\"` and names nothing.");

        $this->assertMatchesRegularExpression(
            '/<label class="form-label" for="category_id">/',
            $this->read('resources/views/testimonial/create.blade.php'),
            'The testimonial category label is unassociated again — it sits beside the select '
            . 'rather than naming it.');
    }

    /**
     * The census, as a one-way ratchet. Counting CODE, not comments: several docblocks in this
     * repository quote `Form::label('')` while explaining it, and a raw grep counts those too.
     */
    public function test_the_empty_label_census_does_not_grow(): void
    {
        $found = 0;
        $files = [];

        foreach ($this->bladeFiles() as $path) {
            $code = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));
            $n = preg_match_all('/\{\{!?!?\s*Form::label\(\s*\'\'\s*,|\{!!\s*Form::label\(\s*\'\'\s*,/', $code);

            if ($n > 0) {
                $found += $n;
                $files[] = basename(dirname($path)) . '/' . basename($path) . " ({$n})";
            }
        }

        $this->assertLessThanOrEqual(self::EMPTY_LABEL_CEILING, $found,
            "`Form::label('')` sites rose to {$found} from a measured ceiling of "
            . self::EMPTY_LABEL_CEILING . ". Each one renders `<label for=\"\">`, which associates "
            . "the label with no control at all.\n  " . implode("\n  ", $files));

        if ($found < self::EMPTY_LABEL_CEILING) {
            $this->addWarning(
                "The census has fallen to {$found}. Lower EMPTY_LABEL_CEILING to match so the "
                . 'ratchet keeps holding.');
        }
    }

    private function declarations(string $css): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    /** @return iterable<string> */
    private function bladeFiles(): iterable
    {
        $root = dirname(__DIR__, 3) . '/resources/views';

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            // The storefront is Phase 03's; this phase owns the merchant admin.
            if (str_contains($file->getPathname(), '/front_end/')) {
                continue;
            }

            yield $file->getPathname();
        }
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
