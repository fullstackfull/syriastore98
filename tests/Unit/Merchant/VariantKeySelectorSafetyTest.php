<?php

namespace Tests\Unit\Merchant;

use PHPUnit\Framework\TestCase;

/**
 * F72 — a variant key reaches the DOM as a SELECTOR, so it must be escaped or sanitised.
 * ======================================================================================
 *
 * WHAT THE KEY IS. `ProductController` builds a product variant's key from the merchant's own
 * attribute terms: `$str .= '-' . str_replace(' ', '', $item)`. Only SPACES are stripped.
 * `product_attribute_options.terms` is validated `required` and nothing else, so `M/L`,
 * `Black/White` or `2.5"` is an ordinary thing for a merchant to type, and the key then carries a
 * character that is special in a CSS selector.
 *
 * TWO DIFFERENT FAILURES CAME OUT OF THAT, and they need two different fixes. Both were measured
 * through the real editor (docs/development/phase-2/tools/check-variant-key-escaping-live.cjs,
 * 8/8, each half bitten independently):
 *
 *   1. A selector THIS CODE builds -- `$('.down_product_' + key)` and three siblings -- threw
 *      `Syntax error, unrecognized expression: .down_product_M/L-Natural`, so the handler aborted
 *      and the downloadable / virtual / manage-stock toggles stopped working for EVERY variant on
 *      the page. Fixed by escaping at the call site with `ssSelectorKey()` (CSS.escape, with a
 *      regex fallback). Escaping, not sanitising, because it cannot collide.
 *
 *   2. `data-bs-target="#collapseone_<key>"` is read by BOOTSTRAP's collapse plugin, which passes
 *      it to `document.querySelector()`. That threw inside Bootstrap --
 *      `'#collapseone_M/L' is not a valid selector` -- and the merchant could not OPEN that
 *      variant's panel at all, so its price, SKU and stock were unreachable. A call-site escape
 *      cannot fix this: the call site is Bootstrap's. The IDENTIFIER has to be CSS-safe, so the
 *      `$safe_str` already used by the sibling create view is used here too.
 *
 * WHY A RATCHET. The create view (`attribute_combinations.blade.php`) ALREADY carried `$safe_str`
 * before this change: somebody met this defect, fixed it in one of three sibling views, and left
 * the other two. That is the same shape as F46 and F50, and it is why this is pinned rather than
 * just fixed. The ordinary thing a person does next is paste one of these blocks into a fourth
 * view.
 *
 * WHAT IS DELIBERATELY NOT ASSERTED. `$str` stays the raw key everywhere it is a VALUE -- request
 * field names, `data-id`, and the label the merchant reads -- because sanitising a value would
 * change what is submitted and what the merchant sees. Only selectors and identifiers are in
 * scope here.
 */
class VariantKeySelectorSafetyTest extends TestCase
{
    /** The three sibling views that render a variant key into the DOM. */
    private const VIEWS = [
        'resources/views/product/attribute_combinations.blade.php',
        'resources/views/product/attribute_combinations_data.blade.php',
        'resources/views/product/attribute_combinations_edit.blade.php',
    ];

    private function read(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        $this->assertFileExists($path, $rel . ' is ratcheted by this test but no longer exists');

        return (string) file_get_contents($path);
    }

    public function test_every_sibling_view_still_exists_and_still_renders_the_key(): void
    {
        // A premise, so nothing below can pass by matching an empty file.
        foreach (self::VIEWS as $rel) {
            $this->assertStringContainsString('collapseone_', $this->read($rel),
                $rel . ' no longer renders a collapse identifier; re-read it and update this test.');
        }
    }

    public function test_no_collapse_identifier_is_built_from_the_raw_key(): void
    {
        $offenders = [];

        foreach (self::VIEWS as $rel) {
            $src = $this->read($rel);
            // `id=`, `data-bs-target=` and `aria-controls=` all end up as, or inside, a selector
            // Bootstrap resolves. Any of them built from `$str` is the defect.
            if (preg_match_all('/collapseone_\{\{\s*\$str\s*\}\}/', $src, $m)) {
                $offenders[] = $rel . ' (' . count($m[0]) . ')';
            }
        }

        $this->assertSame([], $offenders,
            "A collapse identifier is built from the RAW variant key:\n  " . implode("\n  ", $offenders)
            . "\n\nBootstrap hands `data-bs-target` to document.querySelector(), so a key like"
            . "\n`M/L` throws INSIDE Bootstrap and the merchant cannot open that variant's panel."
            . "\nUse \$safe_str (preg_replace('/[^a-zA-Z0-9_-]/', '_', \$str)). See F72.");
    }

    public function test_every_class_selector_built_from_a_key_is_escaped(): void
    {
        $offenders = [];
        $checked = 0;

        foreach (self::VIEWS as $rel) {
            $src = $this->read($rel);

            /* Every `$('.some_class_' + <var>)`. The variable must hold an ESCAPED value, which
             * means it was assigned from `ssSelectorKey(...)`, from `CSS.escape(...)`, or from a
             * `$safe_str`-bearing `data-id` (the create view's approach). Anything else is a raw
             * key going into a selector. */
            if (! preg_match_all('/\$\(\s*[\'"]\.[a-zA-Z0-9_]+_[\'"]\s*\+\s*([a-zA-Z_$][\w$]*)\s*\)/',
                $src, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }

            /* The create view is safe a different way: its `.delete_option` carries
             * `data-id="{{ $safe_str }}"`, already sanitised in PHP, with the raw key kept in
             * `data-original-id` for an attribute-VALUE selector (quoted, so safe). Accept that
             * only when the file really does sanitise in PHP AND keeps the original. */
            $sanitisedInPhp = str_contains($src, '$safe_str = preg_replace')
                && str_contains($src, 'data-id="{{ $safe_str }}"')
                && str_contains($src, 'data-original-id="{{ $str }}"');

            foreach ($matches as $match) {
                $checked++;
                [$var, ] = $match[1];
                $siteOffset = $match[0][1];
                if ($sanitisedInPhp) {
                    continue;
                }

                /* THE NEAREST PRECEDING DECLARATION IS WHAT JAVASCRIPT ACTUALLY RESOLVES, and two
                 * weaker versions of this check had to be thrown away before that was the rule.
                 *
                 * The FIRST asked whether the NAME was assigned via ssSelectorKey() anywhere in
                 * the file. It could not fail: `downProductId` is declared at three separate
                 * sites, so reverting ONE left the other two to vouch for it and the test stayed
                 * green while the defect was live. The bite is what exposed that.
                 *
                 * The SECOND demanded that EVERY assignment of the name be safe. That is wrong in
                 * the other direction for a name as common as `id`: these are Blade files, so
                 * `\bid\s*=` also matches every HTML `id="..."` attribute and the `var id =
                 * $(this).attr('data-url')` in unrelated handlers, which are not selectors at all.
                 * It flagged nineteen innocent lines.
                 *
                 * So: find the declarations of this name that appear BEFORE this selector, take
                 * the last one, and judge that. A `var` / `let` / `const` keyword is required,
                 * which is what excludes the HTML attributes. */
                $declarations = [];
                if (preg_match_all(
                    '/(?:var|let|const)\s+' . preg_quote($var, '/') . '\s*=\s*([^;\n]+)/',
                    $src, $decls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
                )) {
                    foreach ($decls as $decl) {
                        if ($decl[0][1] < $siteOffset) {
                            $declarations[] = $decl[1][0];
                        }
                    }
                }

                $this->assertNotEmpty($declarations,
                    $rel . ': `' . $var . '` is concatenated into a class selector at offset '
                    . $siteOffset . ' but no `var/let/const ' . $var . ' = ...` precedes it, so '
                    . 'this test cannot judge the value. Re-read the view.');

                $nearest = trim((string) end($declarations));
                if (preg_match('/^(?:ssSelectorKey|CSS\.escape)\s*\(/', $nearest) !== 1) {
                    $offenders[] = $rel . '  $(\'.…\' + ' . $var . ')  <- ' . $nearest;
                }
            }
        }

        $this->assertGreaterThanOrEqual(7, $checked,
            'only ' . $checked . ' concatenated class selectors were found across the three views; '
            . 'it was 7 when F72 was fixed, so either they moved or the regex stopped matching. '
            . 'Re-read the views.');
        $this->assertSame([], array_values(array_unique($offenders)),
            "A class selector is built from an UNESCAPED variant key:\n  "
            . implode("\n  ", array_unique($offenders))
            . "\n\njQuery throws `Syntax error, unrecognized expression` on `.down_product_M/L`,"
            . "\nwhich aborts the handler and breaks the toggles for every variant on the page."
            . "\nWrap the value in ssSelectorKey() before concatenating it. See F72.");
    }
}
