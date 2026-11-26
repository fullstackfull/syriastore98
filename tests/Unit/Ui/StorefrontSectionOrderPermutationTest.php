<?php

namespace Tests\Unit\Ui;

use App\Support\Theme\StorefrontSectionOrder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * GAP-099 / P03-B-06 — THE ORDERING RULE, AND THE THREE THEMES' DECLARATIONS OF IT.
 * =================================================================================================
 *
 * Two different things are pinned here and they fail for different reasons.
 *
 * 1. `StorefrontSectionOrder::permute()` — the rule itself, driven directly so that each property
 *    has a named test instead of being inferred from a rendered page.
 *
 * 2. THE WIRING IN EACH THEME'S `main_file.blade.php`, read from the real file. The mechanism is
 *    one service and one `@stack` loop per theme, so the way it breaks is a theme whose declared
 *    block list and whose pushed blocks disagree — a section that exists, is pushed, and is never
 *    emitted, which renders as a section silently vanishing from the shop. Nothing else catches
 *    that: the page still answers 200 and every other storefront test still passes.
 *
 * EVERY SOURCE ASSERTION STRIPS COMMENTS FIRST, and in this file that is not a precaution. The
 * notes added beside this change name `$ssOrderable`, `$ssBlocks`, `ss-home:` and most of the
 * section keys in prose, in all three templates — so a census that read comments could be satisfied
 * entirely by the explanation of the code and would pass with the code deleted. That has happened
 * five times in this repository, which is why `StripsSourceComments` exists.
 *
 * THE SCAN IS SCOPED TO THE DECLARATION AND TO THE PUSH DIRECTIVES, not to the whole template. An
 * assertion over a 460-line file answers "does this string exist anywhere"; these read the
 * `$ssBlocks`/`$ssOrderable` array literals and the `@push('ss-home:...')` directives specifically.
 */
class StorefrontSectionOrderPermutationTest extends TestCase
{
    use StripsSourceComments;

    /** The three shipped themes and the home-page block sequence each template declares. */
    private const THEMES = ['stylique', 'techzonix', 'greentic'];

    /* ============================================================== 1. the rule */

    /** Nothing saved: the theme's own order, untouched. */
    #[Test]
    public function test_no_saved_ordering_leaves_the_default_sequence_alone(): void
    {
        $blocks = ['a', 'b', 'hook', 'c'];

        $this->assertSame($blocks, StorefrontSectionOrder::permute($blocks, ['a', 'b', 'c'], null));
        $this->assertSame($blocks, StorefrontSectionOrder::permute($blocks, ['a', 'b', 'c'], ''));
        $this->assertSame($blocks, StorefrontSectionOrder::permute($blocks, ['a', 'b', 'c'], '   '));
    }

    /**
     * SAVING THE ORDER A PAGE ALREADY RENDERS IN IS THE IDENTITY. This is the property that makes
     * it safe to switch consumption on for every existing store at once: a merchant who had saved
     * something equivalent to the default sees no change at all.
     */
    #[Test]
    public function test_saving_the_default_order_is_the_identity(): void
    {
        $blocks = ['slider', 'logo', 'product', 'hook', 'blog'];

        $this->assertSame(
            $blocks,
            StorefrontSectionOrder::permute($blocks, ['slider', 'logo', 'product', 'blog'], 'slider,logo,product,blog')
        );
    }

    /** A full reversal of every orderable block. */
    #[Test]
    public function test_a_full_reversal_reverses_the_orderable_blocks(): void
    {
        $this->assertSame(
            ['c', 'b', 'a'],
            StorefrontSectionOrder::permute(['a', 'b', 'c'], ['a', 'b', 'c'], 'c,b,a')
        );
    }

    /**
     * A BLOCK OUTSIDE `$orderable` KEEPS ITS SLOT. This is what holds the module hook anchor in
     * place between bestseller and testimonial on stylique, and the newsletter strip at the foot
     * of techzonix and greentic, however the merchant reorders the sections around them.
     */
    #[Test]
    public function test_a_block_that_is_not_orderable_never_moves(): void
    {
        $out = StorefrontSectionOrder::permute(
            ['a', 'b', 'hook', 'c', 'tail'],
            ['a', 'b', 'c'],
            'c,b,a'
        );

        $this->assertSame(['c', 'b', 'hook', 'a', 'tail'], $out);
        $this->assertSame('hook', $out[2], 'the non-orderable block left slot 2.');
        $this->assertSame('tail', $out[4], 'the non-orderable block left slot 4.');
    }

    /**
     * A SECTION THE SAVED ORDER DOES NOT NAME KEEPS ITS SLOT TOO, and this is the whole reason the
     * rule is a slot permutation rather than "named first, then the rest".
     *
     * The shipped `orders` strings do not name every section: stylique declares nine tokens of
     * which five name a top-level block, and `logo`, `collection`, `blog` and `service` are not
     * declared at all. Under "named first" every store that had ever saved an ordering would have
     * found those four shunted to the bottom of its home page the day consumption shipped.
     */
    #[Test]
    public function test_an_orderable_section_the_saved_order_does_not_name_keeps_its_slot(): void
    {
        $blocks = ['slider', 'logo', 'collection', 'product', 'category', 'bestseller'];
        $orderable = $blocks;

        $out = StorefrontSectionOrder::permute($blocks, $orderable, 'category,product,slider');

        $this->assertSame(['category', 'logo', 'collection', 'product', 'slider', 'bestseller'], $out);
        $this->assertSame('logo', $out[1], 'an unnamed section moved out of slot 1.');
        $this->assertSame('collection', $out[2], 'an unnamed section moved out of slot 2.');
        $this->assertSame('bestseller', $out[5], 'an unnamed section moved out of slot 5.');
    }

    /**
     * A TOKEN NAMING NO BLOCK OF THIS THEME IS DROPPED, NOT REFUSED.
     *
     * `updateOrder()` accepts "what the theme declares" UNION "the sections that really exist", so
     * a stored row can legitimately hold a token this page has no block for — all four shipped
     * `orders` strings do. Refusing the whole row over one such token would discard an ordering the
     * merchant really chose.
     */
    #[Test]
    public function test_a_token_naming_no_block_is_dropped(): void
    {
        $this->assertSame(
            ['c', 'b', 'a'],
            StorefrontSectionOrder::permute(['a', 'b', 'c'], ['a', 'b', 'c'], 'c,not_a_block,b,another,a')
        );

        $this->assertSame(
            ['a', 'b', 'c'],
            StorefrontSectionOrder::permute(['a', 'b', 'c'], ['a', 'b', 'c'], 'phantom,other'),
            'a row holding nothing but inert tokens must leave the page exactly as it was.'
        );
    }

    /** A duplicate is a client bug, not an intent to render a section twice. */
    #[Test]
    public function test_duplicate_tokens_collapse_to_the_first_occurrence(): void
    {
        $this->assertSame(
            ['c', 'a', 'b'],
            StorefrontSectionOrder::permute(['a', 'b', 'c'], ['a', 'b', 'c'], 'c,a,c,b,a')
        );
    }

    /** Whitespace around a token is tolerated; the stored strings are hand-editable. */
    #[Test]
    public function test_whitespace_around_tokens_is_tolerated(): void
    {
        $this->assertSame(
            ['c', 'b', 'a'],
            StorefrontSectionOrder::permute(['a', 'b', 'c'], ['a', 'b', 'c'], ' c , b ,, a ')
        );
    }

    /**
     * THE PERMUTATION IS TOTAL AND INJECTIVE FOR EVERY THEME'S REAL BLOCK LIST.
     *
     * A rule that dropped or duplicated a block would delete a section from a live shop or render
     * it twice, and neither shows up as an error. Driven over every single-transposition and
     * every full-reversal ordering of each theme's declared orderable set, against that theme's
     * real block sequence read out of its template.
     */
    #[Test]
    #[DataProvider('themes')]
    public function test_every_block_is_emitted_exactly_once_for_every_ordering(string $theme): void
    {
        [$blocks, $orderable] = $this->declaredSequences($theme);

        $orderings = [implode(',', array_reverse($orderable))];

        foreach ($orderable as $i => $_) {
            foreach ($orderable as $j => $__) {
                if ($i >= $j) {
                    continue;
                }
                $swapped = $orderable;
                [$swapped[$i], $swapped[$j]] = [$swapped[$j], $swapped[$i]];
                $orderings[] = implode(',', $swapped);
            }
        }

        $this->assertGreaterThan(30, count($orderings),
            'PREMISE: only ' . count($orderings) . ' orderings were generated for ' . $theme
            . ', so this proves almost nothing.');

        foreach ($orderings as $saved) {
            $out = StorefrontSectionOrder::permute($blocks, $orderable, $saved);

            $sorted = $out;
            sort($sorted);
            $expected = $blocks;
            sort($expected);

            $this->assertSame($expected, $sorted,
                "{$theme}: the ordering `{$saved}` did not emit every block exactly once. Got: "
                . implode(',', $out));
        }
    }

    /* ============================================================== 2. the wiring */

    /** @return array<string, array{0: string}> */
    public static function themes(): array
    {
        $out = [];
        foreach (self::THEMES as $theme) {
            $out[$theme] = [$theme];
        }

        return $out;
    }

    /**
     * Each theme's `$ssBlocks` and `$ssOrderable`, read out of its own template with comments
     * stripped. The declaration is the only source: a second copy in this test would be the drift
     * the declaration exists to prevent.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function declaredSequences(string $theme): array
    {
        $code = $this->sourceWithoutComments("themes/{$theme}/views/main_file.blade.php");

        $read = function (string $name) use ($code, $theme): array {
            $this->assertSame(1, preg_match('/\$' . $name . '\s*=\s*\[(.*?)\];/s', $code, $m),
                "themes/{$theme}/views/main_file.blade.php does not declare \${$name} exactly once "
                . 'in code (comments stripped). The storefront no longer consumes saved ordering, '
                . 'or the declaration was renamed.');

            preg_match_all("/'([a-z0-9_]+)'/", $m[1], $tokens);

            $this->assertNotEmpty($tokens[1], "\${$name} is empty in {$theme}.");

            return $tokens[1];
        };

        return [$read('ssBlocks'), $read('ssOrderable')];
    }

    /**
     * EVERY DECLARED BLOCK IS PUSHED, AND EVERY PUSH IS DECLARED.
     *
     * The failure this catches is a block that is wrapped in a `@push` and never named in
     * `$ssBlocks`: the loop never yields it, so the section disappears from the shop while the page
     * still answers 200. The reverse — a declared block with no push — yields the empty string and
     * silently drops the section too.
     */
    #[Test]
    #[DataProvider('themes')]
    public function test_every_declared_block_is_pushed_and_every_push_is_declared(string $theme): void
    {
        $code = $this->sourceWithoutComments("themes/{$theme}/views/main_file.blade.php");
        [$blocks] = $this->declaredSequences($theme);

        preg_match_all("/@push\('ss-home:([a-z0-9_]+)'\)/", $code, $pushed);

        $this->assertNotEmpty($pushed[1], "{$theme} pushes no home section at all.");

        $this->assertSame(
            array_values(array_unique($pushed[1])), $pushed[1],
            "{$theme} pushes the same section key twice. Blade MERGES repeated pushes, so the two "
            . 'blocks would be emitted together at one position.'
        );

        $declared = $blocks;
        sort($declared);
        $actual = $pushed[1];
        sort($actual);

        $this->assertSame($declared, $actual,
            "{$theme}: the blocks pushed and the blocks declared in \$ssBlocks are not the same set. "
            . 'A pushed-but-undeclared block is never emitted and vanishes from the shop; a '
            . "declared-but-unpushed block yields ''.\n  declared: " . implode(',', $declared)
            . "\n  pushed:   " . implode(',', $actual));

        $this->assertSame(count($pushed[1]), preg_match_all('/@endpush/', $code),
            "{$theme} has a different number of @push and @endpush directives in code.");
    }

    /** The loop that emits them, and the only thing that does. */
    #[Test]
    #[DataProvider('themes')]
    public function test_the_template_emits_the_sequence_through_one_stack_loop(string $theme): void
    {
        $code = $this->sourceWithoutComments("themes/{$theme}/views/main_file.blade.php");

        $this->assertSame(1, preg_match_all('/@foreach \(\$ssSequence as \$ssBlock\)/', $code),
            "themes/{$theme}/views/main_file.blade.php does not iterate \$ssSequence exactly once.");

        $this->assertSame(1, preg_match_all("/@stack\('ss-home:' \. \\\$ssBlock\)/", $code),
            "themes/{$theme}/views/main_file.blade.php does not yield the pushed blocks by key.");

        $this->assertSame(1, preg_match_all(
            '/\\\\App\\\\Support\\\\Theme\\\\StorefrontSectionOrder::sequence\(/', $code),
            "themes/{$theme}/views/main_file.blade.php does not call the ordering service exactly once.");

        $this->assertStringContainsString("'home',", $code,
            "{$theme} does not name the `home` page slug, so it would read another page's ordering.");
    }

    /**
     * `$ssOrderable` IS EXACTLY WHAT THE THEME'S `home.json` DECLARES AS SECTIONS, and the walker
     * is the same one `ThemeCustomizeController::acceptableOrdering()` validates writes with.
     *
     * The two must agree or the product promises an effect it has not got: a section in `home.json`
     * that is not in `$ssOrderable` is one the editor will accept a position for and the storefront
     * will refuse to move.
     *
     * ONE DOCUMENTED EXCEPTION, measured rather than tolerated: stylique's `more_offer` is nested
     * INSIDE its slider block — the offer banner beside the hero — so it has no slot of its own and
     * cannot be positioned independently of the slider.
     *
     * The blocks that are NOT sections need no exception here, because this compares `home.json`
     * against `$ssOrderable` and they are deliberately absent from it: techzonix's `service`
     * include is gated on `service_status`, which belongs to techzonix's `service` PAGE, and
     * `product_list` and `subscribe` are a module hook anchor and a newsletter strip. All three are
     * in `$ssBlocks`, which is what keeps their slots.
     */
    #[Test]
    #[DataProvider('themes')]
    public function test_the_orderable_set_is_the_themes_own_home_sections(string $theme): void
    {
        $json = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . "/themes/{$theme}/theme_json/home.json"),
            true
        );

        $this->assertIsArray($json, "PREMISE: themes/{$theme}/theme_json/home.json did not parse.");

        $keys = [];
        $walk = function (mixed $node) use (&$walk, &$keys): void {
            if (! is_array($node)) {
                return;
            }
            if (isset($node['settings']) && is_array($node['settings'])
                && (array_key_exists('key', $node) || array_key_exists('slug', $node))) {
                $keys[] = (string) ($node['key'] ?? $node['slug']);
            }
            foreach ($node as $value) {
                $walk($value);
            }
        };
        foreach ($json as $node) {
            $walk($node);
        }

        /** stylique's `more_offer` has no slot of its own: it is nested inside the slider block. */
        $nested = ['stylique' => ['more_offer']];

        $expected = array_values(array_diff(
            array_unique($keys),
            $nested[$theme] ?? []
        ));
        sort($expected);

        [, $orderable] = $this->declaredSequences($theme);
        sort($orderable);

        $this->assertSame($expected, $orderable,
            "{$theme}: \$ssOrderable and the sections themes/{$theme}/theme_json/home.json declares "
            . "are not the same set.\n  home.json: " . implode(',', $expected)
            . "\n  template:  " . implode(',', $orderable)
            . "\nA section in one and not the other is a position the editor accepts and the "
            . 'storefront cannot honour, or the reverse.');
    }

    /**
     * EVERY ORDERABLE BLOCK IS ALSO A DECLARED BLOCK. `permute()` intersects the two, so an
     * orderable key absent from `$ssBlocks` is simply unreachable — a reorder control for a section
     * that can never move.
     */
    #[Test]
    #[DataProvider('themes')]
    public function test_the_orderable_set_is_a_subset_of_the_block_sequence(string $theme): void
    {
        [$blocks, $orderable] = $this->declaredSequences($theme);

        $this->assertSame([], array_values(array_diff($orderable, $blocks)),
            "{$theme}: \$ssOrderable names a block that is not in \$ssBlocks, so it can never move.");

        $this->assertSame(array_values(array_unique($blocks)), $blocks,
            "{$theme}: \$ssBlocks repeats a key, so one block would be emitted twice.");
    }
}
