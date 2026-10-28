<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2-01-19 / WS F3 — one confirmation component, and no untranslated fallback behind it.
 * =================================================================================================
 *
 * MEASUREMENT FIRST, AND IT CORRECTED ME TWICE. A first pass reported three or four competing
 * confirmation mechanisms -- 69 `show_confirm`, 65 `data-confirm`, 8 `confirm(`. Two of those are
 * the SAME mechanism: `show_confirm` is a CSS CLASS, not a function, and `data-confirm` is the
 * attribute it reads. `public/js/custom.js:107` binds `.show_confirm` with one delegated handler
 * that opens a SweetAlert. So "one confirmation component" is already substantially true, and the
 * work was never to build one.
 *
 * WHAT IS ACTUALLY WRONG IS THE FALLBACK. That handler reads `data-confirm`, `data-text`,
 * `data-text-yes` and `data-text-no`, and when any is absent it substitutes a HARDCODED ENGLISH
 * STRING -- "Are you sure?", "This action can not be undone. Do you want to continue?", "Yes",
 * "No". `custom.js` is a static file and cannot call `__()`, so those cannot be translated where
 * they are. Four delete buttons carried no `data-confirm` at all, which means an Arabic merchant
 * -- in the platform's PRIMARY language -- got an English confirmation dialog on those screens:
 * `country/filter`, `state/filter`, `setting/pixel_field_setting`, `setting/webhook_setting`.
 *
 * THE RAW BROWSER DIALOGS ARE MEASURED AND FROZEN, NOT CONVERTED. Four `confirm(...)` calls remain
 * in two merchant views: `finance/withdrawals` (x2) and `pos/index` (x2). All four are translated,
 * so they are an inconsistency rather than a defect -- and converting them is not a styling change:
 * `onclick="return confirm(...)"` is SYNCHRONOUS and blocks the submit, while the SweetAlert handler
 * is asynchronous. Two of the four approve a withdrawal and mark it paid, which is a money path, so
 * the conversion needs a scope decision rather than a quiet rewrite. The count is frozen here so no
 * NEW raw dialog can appear while that decision is outstanding.
 *
 * A fifth `confirm(` in `customer/action.blade.php` is inside a Blade COMMENT describing this very
 * defect, fixed on that screen by an earlier slice. Counting it would have been a false reading, so
 * comments are stripped before counting.
 */
class ConfirmationDialogContractTest extends TestCase
{
    /** Measured 2026-10-28: finance/withdrawals x2, pos/index x2. May only go DOWN. */
    private const RAW_DIALOGS = 4;
    private const RAW_DIALOG_FILES = 2;

    /** @return string[] merchant-admin blade files; the storefront is Phase 03's scope */
    private function merchantViews(): array
    {
        $root = dirname(__DIR__, 3) . '/resources/views';
        $out = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $path = $file->getPathname();
            if (preg_match('#/resources/views/(front_end|themes?)/#', $path) === 1) {
                continue;
            }
            $out[] = $path;
        }

        return $out;
    }

    private function withoutBladeComments(string $source): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    }

    public function test_every_confirmation_trigger_carries_its_own_translated_message(): void
    {
        $offenders = [];

        foreach ($this->merchantViews() as $path) {
            $source = $this->withoutBladeComments((string) file_get_contents($path));

            preg_match_all('/<[^>]*\bshow_confirm\b[^>]*>/', $source, $matches);
            foreach ($matches[0] as $element) {
                if (! str_contains($element, 'data-confirm')) {
                    $offenders[] = str_replace(dirname(__DIR__, 3) . '/', '', $path);
                }
            }
        }

        $this->assertSame([], $offenders,
            "these confirmation triggers carry no `data-confirm`, so custom.js substitutes its "
            . "HARDCODED ENGLISH fallback and an Arabic merchant is asked \"Are you sure?\" in the "
            . "wrong language:\n  " . implode("\n  ", array_unique($offenders)));
    }

    public function test_the_scan_sees_the_confirmation_triggers_at_all(): void
    {
        // Without this the rule above passes vacuously if the class is ever renamed.
        $total = 0;
        foreach ($this->merchantViews() as $path) {
            $total += substr_count($this->withoutBladeComments((string) file_get_contents($path)), 'show_confirm');
        }

        $this->assertGreaterThan(50, $total,
            'the scan found almost no confirmation triggers, so this file asserts nothing');
    }

    public function test_no_new_raw_browser_dialog_has_appeared(): void
    {
        $calls = 0;
        $files = [];

        foreach ($this->merchantViews() as $path) {
            $source = $this->withoutBladeComments((string) file_get_contents($path));

            // A real call: not `show_confirm`, not `data-confirm`, not `$.confirm`.
            $hits = preg_match_all('/(?<![\w.$-])confirm\s*\(/', $source);
            if ($hits > 0) {
                $calls += $hits;
                $files[] = str_replace(dirname(__DIR__, 3) . '/', '', $path);
            }
        }

        $this->assertLessThanOrEqual(self::RAW_DIALOGS, $calls,
            "a new raw browser confirm() has been added. The shared component is `.show_confirm`; "
            . "the remaining raw dialogs are frozen pending a scope decision, because two of them "
            . "approve and settle a withdrawal and the conversion changes synchronous submit "
            . "blocking into an asynchronous callback. Current sites:\n  " . implode("\n  ", $files));

        $this->assertLessThanOrEqual(self::RAW_DIALOG_FILES, count($files),
            'a new merchant view has introduced a raw browser dialog');
    }

    public function test_only_one_delegated_confirmation_handler_is_bound(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/public/js/custom.js');

        $this->assertSame(1, substr_count($js, "'.show_confirm'"),
            'the confirmation class is bound more than once, so two dialogs can open for one click');
        $this->assertStringContainsString('Swal.mixin', $js,
            'the confirmation component is no longer SweetAlert, which is what every '
            . '`.show_confirm` trigger in 80-odd views expects');
    }
}
