<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * BOOTSTRAP'S TOOLTIP DESTROYS THE ACCESSIBLE NAME IT LOOKS LIKE IT PROVIDES.  P2.5-DESIGN-J
 * =================================================================================================
 *
 * An icon-only control labelled by `title` reads correctly in the markup and is announced as
 * NOTHING in the browser. Measured on the running admin, after the plugin has initialised:
 *
 *     class="btn btn-sm btn-primary btn-icon"   title=""   data-bs-original-title="Add Customer"
 *                                               aria-label=null   textContent=""
 *
 * `Tooltip` moves `title` into `data-bs-original-title` and leaves `title` EMPTY, so the one
 * attribute carrying the name is gone by the time anything reads it. The icon is an icon font —
 * `i class="ti ti-plus"` — which contributes no text. Some sites skip the first step and write
 * `data-bs-original-title` directly, so they never had a name at all.
 *
 * THE PATTERN IS ALREADY IN THIS CODEBASE, which is why this is a ratchet and not a proposal:
 * `partision/header.blade.php:258` carries `title=X aria-label=X` together, and 21 controls
 * follow it. 51 do not. `aria-label` survives the plugin because the plugin does not touch it.
 *
 * MEASURED COUNTS, in the running application across /customer /order /product-list /dashboard
 * /finance (docs/development/phase-2.5/tools/census-nameless-controls.cjs): 29 unnamed controls in
 * 11 components.
 *
 * WHY THE CENSUS SAID 196 FIRST. Its first draft asked Playwright for each control's accessible
 * name through `accessibility.snapshot` and counted buttons whose name is their own visible text —
 * "Request Withdrawal" among them. The instrument was wrong, not the pages. It now computes the
 * name the way the audit tool does, and treats `placeholder` as the name it really is, which took
 * 32 to 29.
 */
class IconOnlyControlNameTest extends TestCase
{
    private const VIEWS = 'resources/views';

    /**
     * Every anchor or button that opens a Bootstrap tooltip and contains no text of its own.
     *
     * @return array<int, array{file: string, line: int, opening: string}>
     */
    private function iconOnlyTooltipControls(): array
    {
        $root = dirname(__DIR__, 3) . '/' . self::VIEWS;
        $this->assertDirectoryExists($root);

        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());

            if (! preg_match_all(
                '/<(a|button)\b[^>]*data-bs-toggle=["\']tooltip["\'][^>]*>(.*?)<\/\1>/si',
                $src,
                $m,
                PREG_OFFSET_CAPTURE | PREG_SET_ORDER
            )) {
                continue;
            }

            foreach ($m as $hit) {
                $whole = $hit[0][0];
                $inner = $hit[2][0];
                $opening = substr($whole, 0, (int) strpos($whole, '>') + 1);

                // A BLADE ECHO IS TEXT, NOT NOTHING -- and the first version of this scan
                // DELETED it, which made every `<i></i> {{ __('Edit') }}` control look icon-only
                // and reported 110 where there are 51. Tags go; an echo becomes a marker; what
                // survives is what the control really says. A control that says something is not
                // this test's business.
                $text = preg_replace('/<[^>]+>/s', '', $inner) ?? '';
                $text = trim(preg_replace(['/\{\{.*?\}\}/s', '/\{!!.*?!!\}/s'], 'X', $text) ?? '');
                if ($text !== '') {
                    continue;
                }

                $found[] = [
                    'file' => str_replace(dirname(__DIR__, 3) . '/', '', $file->getPathname()),
                    'line' => substr_count(substr($src, 0, $hit[0][1]), "\n") + 1,
                    'opening' => preg_replace('/\s+/', ' ', $opening) ?? $opening,
                ];
            }
        }

        return $found;
    }

    /**
     * THE PREMISE IS NOT CEREMONY. Without it this file passes when the scan matches nothing —
     * a changed attribute spelling, a renamed directory, a regex that stops compiling — and a
     * guard that passes because it found nothing is the failure mode this project keeps recording.
     */
    public function test_the_scan_reaches_the_merchant_views(): void
    {
        $controls = $this->iconOnlyTooltipControls();

        $this->assertGreaterThan(
            40,
            count($controls),
            'the scan found almost no icon-only tooltip controls. It measured 72 when written; if '
            . 'that has collapsed, the regex or the directory has changed and this file is '
            . 'guarding nothing.'
        );
    }

    /**
     * Every one of them carries `aria-label`, because `title` does not survive the plugin.
     */
    public function test_every_icon_only_tooltip_control_carries_an_aria_label(): void
    {
        $missing = array_values(array_filter(
            $this->iconOnlyTooltipControls(),
            static fn (array $c) => ! str_contains($c['opening'], 'aria-label')
        ));

        $this->assertSame(
            [],
            array_map(static fn (array $c) => "{$c['file']}:{$c['line']}  {$c['opening']}", $missing),
            count($missing) . ' icon-only control(s) rely on `title` for their name. Bootstrap '
            . 'moves `title` into `data-bs-original-title` and leaves `title` EMPTY, so the '
            . 'control is announced as nothing. Add `aria-label` beside the title, as '
            . 'partision/header.blade.php:258 already does.'
        );
    }

    /**
     * AND THE LABEL MUST BE TRANSLATED. A bare English `aria-label` on a bilingual admin names
     * the control in one language for every merchant, which is a different defect wearing this
     * one's fix. The views reach their strings through the translator, so the label does too.
     */
    public function test_each_aria_label_goes_through_the_translator(): void
    {
        $untranslated = [];
        foreach ($this->iconOnlyTooltipControls() as $c) {
            // THE STOREFRONT IS PHASE 03 AND IS NOT ABSORBED INTO THIS ONE. One pre-existing
            // control under `front_end/` carries `title="Print"` and `aria-label="Print"`, both
            // bare English, so an Arabic shopper gets an English tooltip AND an English
            // screen-reader name -- and `"Print": "طباعة"` is already in `resources/lang/ar.json`,
            // so the fix is two translator calls. It is RECORDED rather than taken, because the
            // owner's scope precedence puts the storefront in a later phase. The merchant twin of
            // the same defect, in `support/edit.blade.php`, WAS fixed here.
            if (str_starts_with($c['file'], 'resources/views/front_end/')) {
                continue;
            }
            if (! preg_match('/aria-label=(["\'])(.*?)\1/s', $c['opening'], $m)) {
                continue;
            }
            $value = trim($m[2]);
            if ($value === '' || ! str_contains($value, '__(')) {
                $untranslated[] = "{$c['file']}:{$c['line']}  aria-label=\"{$value}\"";
            }
        }

        $this->assertSame(
            [],
            $untranslated,
            'an `aria-label` on a bilingual admin must go through the translator, like the '
            . '`title` beside it.'
        );
    }

    /**
     * AND THE STOREFRONT EXEMPTION STAYS ONE SCREEN WIDE. An exemption nobody measures becomes a
     * place to put things; this asserts it covers what it was written for and no more.
     */
    public function test_the_storefront_exemption_covers_one_known_control(): void
    {
        $exempt = array_values(array_filter(
            $this->iconOnlyTooltipControls(),
            static fn (array $c) => str_starts_with($c['file'], 'resources/views/front_end/')
                && preg_match('/aria-label=(["\'])(?!.*__\().*?\1/s', $c['opening']) === 1
        ));

        $this->assertLessThanOrEqual(
            1,
            count($exempt),
            'the storefront exemption now covers ' . count($exempt) . ' controls. It was written '
            . 'for ONE pre-existing `Print` control and a later phase owns that surface; it is '
            . 'not a place to put new untranslated labels.'
        );
    }
}
