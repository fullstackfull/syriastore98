<?php

namespace Tests\Unit\Ui;

use Tests\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * THE OPERATOR'S LYNOMIA SCREENS MUST NOT PAINT WITH THE HOST'S PRIMARY FILL — AND THIS IS A
 * SOURCE CENSUS BECAUSE A SCREENSHOT CANNOT SEE THESE.
 * =================================================================================================
 *
 * WHAT THE INSTRUMENT MISSED, which is worth more than the fills it missed. GAP-179 enumerated the
 * 24-screen operator surface, drove all 96 theme x direction cells, and closed the `.btn-primary`
 * defect (2.58:1 dark) on six surfaces by moving them onto `.ss-btn--primary` (7.92:1). It declared
 * the surface measured. It could not see three fills on
 * `lynomia-chat::superadmin.integration`, and the reason is not that the capture was careless:
 *
 * A CONTRAST WALK OVER A RENDERED PAGE MEASURES THE DATA-DEPENDENT SUBSET OF THE MARKUP, NOT THE
 * MARKUP. All three live behind data conditions, measured by reading the template:
 *
 *     :113  `<span class="badge bg-primary">`         inside `@forelse ($planSeats as $plan)`
 *     :129  `<button class="btn btn-sm btn-primary">` inside the same `@forelse`
 *     :495  `<button class="btn btn-sm btn-primary">` inside
 *           `@if ($row->state === LinkState::PENDING_VERIFICATION)`
 *
 * `$planSeats` is `Plan::query()->orderBy('name')->get(...)`
 * (`SuperAdmin\IntegrationController`). With no `plans` rows the `@empty` branch renders
 * "No plans yet." and NEITHER the badge NOR the button exists in the DOM at all — there is nothing
 * for `getComputedStyle` to be called on, so the walk reports zero findings and the screen reads as
 * clean. The third needs a link row in exactly `PENDING_VERIFICATION`; any other state renders a
 * different branch.
 *
 * So these are not hidden elements that a capture could have found by expanding an accordion or
 * hovering. THEY ARE ABSENT. The only instrument that sees them is one that reads the template, and
 * that is why this file is a source census and lives in `tests/Unit/`. The reusable rule: a
 * rendered-page audit can only ever establish a LOWER BOUND on a design-system violation, and the
 * gap between that bound and the truth is exactly the template's conditional branches.
 *
 * -------------------------------------------------------------------------------------------------
 * THE REPLACEMENT, AND WHY THE HOST CLASS IS REMOVED RATHER THAN JOINED. GAP-179 measured that the
 * host utility carries `!important`, so a DS class placed BESIDE it changes nothing on screen — a
 * fix that reads as applied and paints as before. The host classes are therefore deleted:
 *
 *     badge bg-primary          -> ss-badge ss-badge--brand
 *     btn btn-sm btn-primary    -> ss-btn ss-btn--primary ss-btn--sm
 *
 * Measured from the tokens those classes resolve to, both themes:
 *
 *     .ss-badge--brand   light  #335da8 on #f4f5f8   5.89:1
 *                        dark   #2ac3e5 on #0e2a33   7.18:1
 *     .ss-btn--primary   light  #ffffff on #335da8   6.42:1
 *                        dark   #04222a on #2ac3e5   7.92:1   (matches GAP-179's figure exactly,
 *                                                              which is how the class was confirmed
 *                                                              to be the one that row closed)
 *
 * There is no `.ss-badge--primary`; `--brand` is the primary-fill badge, and the premise case below
 * asserts every `ss-` class this view uses is really DEFINED — because a class name that no rule
 * matches is the trap this session hit once already with a `ss-stack-mb-4` that did not exist.
 */
class LynomiaOperatorViewsUseDesignSystemFillsTest extends TestCase
{
    use StripsSourceComments;

    /** The operator-facing LynomiaChat views. `LynomiaChat` is the one first-party package. */
    private const VIEW_DIR = 'packages/workdo/LynomiaChat/src/resources/views/superadmin';

    /**
     * Host utilities that paint the primary fill. `bg-light`/`text-dark` are NOT here: they are
     * neutral surface utilities the storefront appearance layer maps, and GAP-179 closed brand
     * fills rather than every Bootstrap class.
     */
    private const HOST_PRIMARY_FILLS = ['btn-primary', 'bg-primary'];

    private static function viewFiles(): array
    {
        $base = dirname(__DIR__, 3) . '/' . self::VIEW_DIR;
        $out = [];

        if (! is_dir($base)) {
            return $out;
        }

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                $out[] = self::VIEW_DIR . '/' . substr($f->getPathname(), strlen($base) + 1);
            }
        }

        sort($out);

        return $out;
    }

    public function test_no_operator_lynomia_view_paints_with_a_host_primary_fill(): void
    {
        $files = self::viewFiles();

        $this->assertNotEmpty($files, 'premise: no LynomiaChat operator views were found at all');

        $offences = [];

        foreach ($files as $relative) {
            /* COMMENTS STRIPPED: the docblock above quotes `btn btn-sm btn-primary` verbatim, and
               so does the view's own history in places. A plain grep would find the prose. */
            $source = $this->sourceWithoutComments($relative);

            foreach (self::HOST_PRIMARY_FILLS as $class) {
                if (preg_match_all('/class\s*=\s*"[^"]*\b' . preg_quote($class, '/') . '\b/', $source, $m)) {
                    $offences[] = $relative . '  ->  ' . $class . ' x' . count($m[0]);
                }
            }
        }

        $this->assertSame([], $offences,
            "An operator Lynomia screen paints with the host's primary fill:\n  "
            . implode("\n  ", $offences) . "\n\n"
            . "Use `ss-btn ss-btn--primary` / `ss-badge ss-badge--brand` and REMOVE the host class "
            . 'rather than adding the DS one beside it: GAP-179 measured that the host utility '
            . "carries `!important`, so a DS class next to it changes nothing on screen. And note "
            . 'that a rendered-page contrast capture CANNOT catch these -- every occurrence so far '
            . 'has been inside an `@forelse` or an `@if` on a data state, so with the wrong fixture '
            . 'the element is absent from the DOM and the walk reports a clean screen.');
    }

    /**
     * EVERY `ss-` CLASS THESE VIEWS USE MUST REALLY BE DEFINED.
     *
     * A replacement class that no rule matches is a fix that reads as applied and paints as before
     * — the same shape as adding a DS class beside an `!important` host one, and a mistake made in
     * this repository with an `ss-stack-mb-4` that has 0 occurrences in the stylesheet.
     */
    public function test_every_ss_class_those_views_use_is_defined_in_the_design_system(): void
    {
        $ds = (string) file_get_contents(dirname(__DIR__, 3) . '/public/css/store-syria-ds.css');
        $ds = (string) preg_replace('{/\*.*?\*/}s', '', $ds);

        $used = [];

        foreach (self::viewFiles() as $relative) {
            $source = $this->sourceWithoutComments($relative);

            if (preg_match_all('/class\s*=\s*"([^"]*)"/', $source, $m)) {
                foreach ($m[1] as $attr) {
                    foreach (preg_split('/\s+/', trim($attr)) ?: [] as $cls) {
                        if ($cls !== '' && str_starts_with($cls, 'ss-') && ! str_contains($cls, '{')) {
                            $used[$cls] = true;
                        }
                    }
                }
            }
        }

        $this->assertNotEmpty($used,
            'premise: these views use no `ss-` class at all, so this case measures nothing -- which '
            . 'would also mean the main assertion above passed without any DS class being adopted');

        $undefined = [];

        foreach (array_keys($used) as $cls) {
            if (! str_contains($ds, '.' . $cls)) {
                $undefined[] = $cls;
            }
        }

        sort($undefined);

        $this->assertSame([], $undefined,
            'These views use `ss-` classes that `public/css/store-syria-ds.css` does not define, so '
            . 'they paint nothing: ' . implode(', ', $undefined));
    }
}
