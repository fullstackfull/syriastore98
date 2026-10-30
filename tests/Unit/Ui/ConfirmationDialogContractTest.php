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

    /**
     * F204 -- THE CONFIRMATION MUST CANCEL THE CLICK IT INTERCEPTS, OR IT IS DECORATION.
     *
     * The handler is delegated on `document` and opens a SweetAlert, then calls `form.submit()`
     * from the `.then()`. SweetAlert is ASYNCHRONOUS, so the click's own default action runs to
     * completion first. For a `type="button"` trigger there is no default action and nothing goes
     * wrong, which is why this survived: 46 of the 57 rendered triggers are exactly that.
     *
     * But two triggers are `type="submit"` INSIDE the form -- `setting/webhook_setting.blade.php`
     * and `setting/pixel_field_setting.blade.php` -- and there the browser submits the form on the
     * same click. The row is deleted whether the merchant presses Yes or No; the dialog is a
     * picture of a question that has already been answered. That is the same defect class F184
     * exists to prevent ("do not treat the delete button itself as confirmation"), reached through
     * a different door.
     *
     * THE CENSUS IS RE-MEASURED, AND MY FIRST ONE WAS WRONG. I reported it as "7 anchors, 2 submits,
     * 21 other buttons" from the 30 triggers whose whole opening tag sits on one line -- a partial
     * sample described as if it were the population, and the three figures therefore do not even
     * add up to the 65 occurrences named in the same sentence. The full count, taken over complete
     * opening tags rather than single lines: **65 raw occurrences, 8 of them inside Blade comments,
     * so 57 rendered triggers -- 9 anchors (every one `href="#"` or `#!`), 46 `type="button"`, 2
     * `type="submit"`, and zero with no `type` at all.**
     *
     * That last figure is the one that mattered and I had not measured it. A `<button>` with no
     * `type` DEFAULTS to `submit`, so a type-less trigger inside a form would be a third live
     * instance of this defect; there are none. The population of F204-affected triggers is exactly
     * the two declared submits. So `preventDefault()` is a no-op for the 46 buttons, an improvement
     * for the 9 anchors (it suppresses the `#` jump), and the actual fix for the two submits --
     * whose confirm branch already calls `form.submit()` itself. The conclusion survived the
     * correction; the numbers supporting it did not, and stating a sample as a census is how a
     * reviewer gets talked out of checking.
     *
     * This asserts the call is present AND that it precedes the dialog, because a `preventDefault`
     * placed inside the `.then()` would run after the submit had already happened and would read
     * as a fix without being one.
     */
    public function test_the_confirmation_handler_cancels_the_click_it_intercepts(): void
    {
        $js = $this->withoutJsComments(
            (string) file_get_contents(dirname(__DIR__, 3) . '/public/js/custom.js')
        );

        $start = strpos($js, "$(document).on('click', '.show_confirm'");
        $this->assertNotFalse($start, 'the delegated confirmation handler is no longer where this contract can read it');

        $swal = strpos($js, 'swalWithBootstrapButtons.fire', $start);
        $this->assertNotFalse($swal, 'the handler no longer opens the dialog');

        $head = substr($js, $start, $swal - $start);

        $this->assertMatchesRegularExpression('/\be\.preventDefault\(\)/', $head,
            'the confirmation handler does not cancel the click it intercepts, so on a '
            . '`type="submit"` trigger the browser submits the form on the same click and the '
            . 'dialog is decorative -- the destructive action happens whether the merchant '
            . 'confirms or cancels (F204)');
    }
    /**
     * F204 -- STRIP JAVASCRIPT COMMENTS, BECAUSE A FIX REPLACED BY A COMMENT IS NOT A FIX.
     *
     * THIS FILE HAD THE HOLE IT WAS WRITTEN TO CLOSE. Reverting `e.preventDefault();` the way a
     * careless maintainer would -- leaving `// TODO restore: e.preventDefault();` behind -- left
     * ALL FIVE cases in this class green, because the assertion was a plain `preg_match` over raw
     * source. The commented-out call satisfies the needle and does nothing in a browser. A ratchet
     * that accepts a comment as its own subject is not a ratchet, and this is exactly the shape
     * CLAUDE.md names: a test that passes for the wrong reason.
     *
     * `PaymeraCapturedWithoutOrderTest::executableSource()` solves the same problem on the PHP side
     * with `token_get_all()` and records why a lazy comment regex is not good enough -- it strips
     * inside STRING LITERALS and turned `'https://egate-t.paymera.cc'` into `'https:`. PHP has no
     * JavaScript tokeniser, so this is a hand-written scanner rather than a regex, and it tracks the
     * four contexts a `/` can legally sit in: a single-quoted string, a double-quoted string, a
     * template literal, and a regex literal. `custom.js` contains all of the first three and URLs
     * with `//` in them, so a regex-based strip would silently remove live code here too.
     */
    private function withoutJsComments(string $js): string
    {
        $out = '';
        $n = strlen($js);
        $i = 0;
        $prev = '';            // last significant code character emitted

        while ($i < $n) {
            $c = $js[$i];
            $d = $i + 1 < $n ? $js[$i + 1] : '';

            if ($c === '/' && $d === '/') {
                while ($i < $n && $js[$i] !== "\n") {
                    $i++;
                }

                continue;      // the newline itself is emitted by the next iteration
            }

            if ($c === '/' && $d === '*') {
                $end = strpos($js, '*/', $i + 2);
                $i = $end === false ? $n : $end + 2;
                /* A block comment can span lines, and swallowing them would move every offset this
                   contract reports. Keep the line count stable. */
                $out .= str_repeat("\n", substr_count(substr($js, 0, $i), "\n") - substr_count($out, "\n"));

                continue;
            }

            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $out .= $c;
                $i++;
                while ($i < $n) {
                    if ($js[$i] === '\\') {
                        $out .= substr($js, $i, 2);
                        $i += 2;

                        continue;
                    }
                    $out .= $js[$i];
                    if ($js[$i] === $quote) {
                        $i++;

                        break;
                    }
                    $i++;
                }
                $prev = $quote;

                continue;
            }

            /* A regex literal, distinguished from division by what precedes it. Anything other than
               a value-ending token means a regex can start here. */
            if ($c === '/' && ($prev === '' || strpos('(,=:[!&|?{};+-*%~^', $prev) !== false)) {
                $out .= $c;
                $i++;
                $inClass = false;
                while ($i < $n) {
                    if ($js[$i] === '\\') {
                        $out .= substr($js, $i, 2);
                        $i += 2;

                        continue;
                    }
                    if ($js[$i] === '[') {
                        $inClass = true;
                    } elseif ($js[$i] === ']') {
                        $inClass = false;
                    }
                    $out .= $js[$i];
                    if ($js[$i] === '/' && ! $inClass) {
                        $i++;

                        break;
                    }
                    if ($js[$i] === "\n") {   // an unterminated regex: it was division after all
                        $i++;

                        break;
                    }
                    $i++;
                }
                $prev = '/';

                continue;
            }

            $out .= $c;
            if (trim($c) !== '') {
                $prev = $c;
            }
            $i++;
        }

        return $out;
    }

    /**
     * THE PREMISE OF THE RATCHET ABOVE: the stripper really strips, and really does not strip code.
     *
     * Without this, `withoutJsComments()` could degrade into an identity function -- or into one
     * that eats live code -- and the F204 assertion would go back to being satisfiable by a comment,
     * or start failing for a reason that has nothing to do with the handler.
     */
    public function test_the_comment_stripper_removes_comments_and_keeps_code(): void
    {
        $this->assertStringNotContainsString('preventDefault',
            $this->withoutJsComments("x();\n// TODO restore: e.preventDefault();\ny();"),
            'a `//` comment survived the strip, so a commented-out fix would satisfy the F204 '
            . 'ratchet exactly as it did before this was written');

        $this->assertStringNotContainsString('preventDefault',
            $this->withoutJsComments("x();\n/* e.preventDefault(); */\ny();"),
            'a block comment survived the strip');

        $this->assertStringContainsString('e.preventDefault()',
            $this->withoutJsComments("$(document).on('click', function (e) { e.preventDefault(); });"),
            'the stripper removed live code');

        /* The two hazards that make this a scanner rather than a regex. */
        $this->assertStringContainsString('https://egate-t.paymera.cc',
            $this->withoutJsComments("var u = 'https://egate-t.paymera.cc';"),
            'a `//` inside a string literal was treated as a comment opener -- the measured failure '
            . 'mode that `PaymeraCapturedWithoutOrderTest` records for the PHP side');

        $this->assertStringContainsString('keep',
            $this->withoutJsComments("var r = /a\\/\\/b/; var k = 'keep';"),
            'a `//` inside a regex literal was treated as a comment opener, so everything after it '
            . 'on that line was removed');
    }
}
