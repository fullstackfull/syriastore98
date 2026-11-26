<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GAP-220, THE REMAINING HALF — THE CONVERSION-ACTION OVERLAY MIRRORS IN ARABIC.
 * =================================================================================================
 *
 * `GAP-220`'s structural half closed on 2026-11-21 and left one thing open in the register's own
 * words: all three themes positioned the product card's conversion-action overlay with `right-4`,
 * a PHYSICAL Tailwind utility, so the overlay did not mirror. greentic's container matched the
 * other two deliberately -- "using `end-4` in one theme out of three would have made greentic the
 * only mirroring theme, which is a different layout rather than a fix" -- so it was always a
 * three-theme change. This file is what stops it drifting back to one theme out of three.
 *
 * MEASURED IN CHROMIUM ON THE REAL STOREFRONT, not inferred from Tailwind's documentation. The
 * storefront loads the RUNTIME JIT CDN (`themes/<t>/assets/js/tailwind.js`), so whether a logical
 * utility produces a rule at all is a property of THAT bundled copy. On a rendered
 * `/{store}/home` with the four conversion modules really loaded, the runtime injects
 * `.end-4 { inset-inline-end: 1rem; }` in all three themes. The overlay's gap from the card's own
 * edges, before and after, in CSS pixels:
 *
 *     theme      container                        dir=ltr            dir=rtl
 *     stylique   .absolute.top-4.<inset>          16px from right    BEFORE 16px from right
 *                                                                    AFTER  16px from LEFT
 *     greentic   .absolute.top-4.<inset>.z-10     17px from right    BEFORE 17px from right
 *                                                                    AFTER  17px from LEFT
 *     techzonix  .pro-btn-wrapper.absolute...     17px from right    BEFORE 17px from right
 *                                                                    AFTER  17px from LEFT
 *
 * WHY techzonix'S ANCHOR IS DIFFERENT, and it is measured rather than copied from the register.
 * The register names `.pro-btn-wrapper` as techzonix's container, and that was true when it was
 * written. The same change that closed the structural half moved the hook OUT of the product
 * anchor, and it is now a SIBLING of `.pro-btn-wrapper`, not a child: in the browser the hook's
 * nearest positioned ancestor on techzonix is `div.relative.h-48`, and the hook itself measures
 * 295x60 at 1px from both card edges -- normal flow, the full width of the card. So on techzonix
 * the module buttons already mirrored, and `.pro-btn-wrapper` -- which carries the wishlist
 * control in the same corner -- is the element that did not. It is still the container this gap
 * is about, and it is still a `right-4` that had to become logical.
 *
 * THIS IS A SOURCE CENSUS AND THE LIMIT IS STATED RATHER THAN HIDDEN, exactly as
 * `ProductActionOverlayTest` states it. The buttons inside the overlay come from
 * `ProductQuickView`, `ProductCompare`, `ProductsPDF` and `ProductCSV`, which per CLAUDE.md load
 * in NO test run unless an `add_on_managers` row is inserted. Nothing here proves a button
 * renders, or that a pixel moved; it proves the container carries a logical inset and that the
 * theme's own bundled Tailwind can turn that into a rule. The pixels are in the browser evidence.
 *
 * TWO SITES ARE DELIBERATELY NOT COVERED, both recorded so the next reader does not think they
 * were missed:
 *
 *   - `resources/views/components/product-card.blade.php`, a FOURTH copy of this component, still
 *     carries the physical inset. Measured: a store with an empty `theme_id` renders STYLIQUE's
 *     card, not this one (`config('theme.active')` is `stylique`), so no fixture reached it. It is
 *     outside the theme directories this change was scoped to.
 *   - greentic's product LABEL badge is `absolute top-4 left-4`. It is a different element with a
 *     different owner and changing it was not in scope, so no assertion below reads it. The scans
 *     are anchored to the overlay container and nothing else, which is also why they cannot be
 *     satisfied by another element in the same file.
 */
final class StorefrontProductActionsMirrorTest extends TestCase
{
    /**
     * The container that positions the conversion-action overlay, per theme, and the anchor that
     * identifies it.
     *
     * `call` means "the nearest enclosing opening <div> that carries `absolute`, searching
     * backwards from the `Product::actionLinks()` call". `class` means "the opening <div> whose
     * class attribute contains this literal". techzonix needs the second form for the reason in
     * the class docblock: its hook is a sibling of the container, not a child.
     */
    private const CONTAINERS = [
        'stylique' => ['by' => 'call', 'anchor' => 'Product::actionLinks'],
        'greentic' => ['by' => 'call', 'anchor' => 'Product::actionLinks'],
        'techzonix' => ['by' => 'class', 'anchor' => 'pro-btn-wrapper'],
    ];

    public static function themeProvider(): array
    {
        return array_map(static fn ($t) => [$t], array_keys(self::CONTAINERS));
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function read(string $relative): string
    {
        $path = self::root() . '/' . $relative;

        $this->assertFileExists($path, $relative . ' is gone; re-point this census rather than deleting it.');

        return (string) file_get_contents($path);
    }

    /**
     * BLADE COMMENTS REMOVED BEFORE ANY SCAN, and this is not tidiness.
     *
     * CLAUDE.md records four separate false readings in one afternoon from censuses that read
     * their own explanatory prose, and `ProductActionOverlayTest` was bitten by exactly this on
     * exactly these files. Every container below is now introduced by a comment that NAMES the
     * physical utility it replaced, so a scan over the raw file would report the defect it just
     * fixed.
     */
    private function cardSource(string $theme): string
    {
        $body = $this->read("themes/{$theme}/views/components/product-card.blade.php");

        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $body);
    }

    /**
     * The one opening `<div ...>` tag that positions this theme's overlay — nothing else.
     *
     * SCOPED ON PURPOSE. CLAUDE.md records a mutation that survived because an assertion ran over
     * a whole stylesheet and was satisfied by another component's identical rule. An assertion
     * over a whole product card has the same hole: greentic's label badge is also
     * `absolute top-4 <inset>`, so "does this file contain a logical inset" is a different
     * question from "does the overlay container use one".
     */
    private function containerTag(string $theme): string
    {
        $card = $this->cardSource($theme);
        $spec = self::CONTAINERS[$theme];

        if ($spec['by'] === 'class') {
            $this->assertSame(1, preg_match(
                '/<div\b[^>]*\bclass="[^"]*' . preg_quote($spec['anchor'], '/') . '[^"]*"[^>]*>/',
                $card,
                $m
            ), sprintf(
                "%s's product card has no opening <div> carrying `%s`. That is the container this "
                . 'gap is about; if it was renamed, re-point this census rather than deleting it.',
                $theme,
                $spec['anchor']
            ));

            return $m[0];
        }

        $at = strpos($card, $spec['anchor']);

        $this->assertNotFalse($at, sprintf(
            "%s's product card does not call %s at all, which is the state GAP-220 closed: that "
            . "theme's shoppers have no quick view and no compare.",
            $theme,
            $spec['anchor']
        ));

        // Walk backwards to the nearest opening <div> that is positioned. That is the element the
        // overlay hangs off; anything further out is the card or the image box.
        $this->assertSame(1, preg_match_all(
            '/<div\b[^>]*\bclass="[^"]*\babsolute\b[^"]*"[^>]*>/',
            substr($card, 0, $at),
            $m
        ), sprintf(
            "%s's card does not have exactly one positioned <div> before the overlay call, so this "
            . 'census can no longer tell which element positions it. Re-point it.',
            $theme
        ));

        return end($m[0]);
    }

    /** The class tokens of that one tag. */
    private function containerClasses(string $theme): array
    {
        $tag = $this->containerTag($theme);

        $this->assertSame(1, preg_match('/\bclass="([^"]*)"/', $tag, $m),
            $theme . "'s overlay container has no class attribute: " . $tag);

        return preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * THE FINDING ITSELF: the overlay container carries NO bare physical inset.
     *
     * `right-4` computed `right: 16px` in BOTH directions, so in Arabic the overlay sat 16-17px
     * from the card's RIGHT edge -- the wrong side, over the mirrored content -- while every other
     * element on the card had swapped. A `ltr:`/`rtl:` PAIR is the other way to spell a mirroring
     * inset, and three search popups in this repository use it, so it is allowed; a bare one is
     * not.
     */
    #[Test]
    #[DataProvider('themeProvider')]
    public function test_the_overlay_container_carries_no_bare_physical_inset(string $theme): void
    {
        foreach ($this->containerClasses($theme) as $class) {
            $this->assertDoesNotMatchRegularExpression(
                '/^(?:-)?(?:right|left)-[\w.\/\[\]-]+$/',
                $class,
                sprintf(
                    "%s's conversion-action overlay container carries the physical utility `%s`, "
                    . 'so the overlay does not mirror in Arabic -- it stays on the same side of the '
                    . 'card while the text, the price and the cart button all swap. Use the '
                    . 'logical inset (`end-*` / `start-*`), or an explicit `ltr:`/`rtl:` pair. '
                    . 'Full tag: %s',
                    $theme,
                    $class,
                    $this->containerTag($theme)
                )
            );
        }
    }

    /**
     * AND IT CARRIES A LOGICAL ONE. Removing the inset altogether also removes the physical
     * utility, so the check above alone would be satisfied by deleting the offset and letting the
     * overlay fall into the corner of its containing block.
     */
    #[Test]
    #[DataProvider('themeProvider')]
    public function test_the_overlay_container_carries_a_logical_inset(string $theme): void
    {
        $classes = $this->containerClasses($theme);

        $logical = array_values(array_filter(
            $classes,
            static fn ($c) => (bool) preg_match('/^(?:-)?(?:end|start)-[\w.\/\[\]-]+$/', $c)
        ));

        $this->assertNotEmpty($logical, sprintf(
            "%s's conversion-action overlay container has no logical inset at all (classes: %s). "
            . 'Without one the overlay sits wherever its containing block puts it, which is not '
            . 'the corner the design asks for in either direction.',
            $theme,
            implode(' ', $classes)
        ));
    }

    /**
     * ALL THREE THEMES USE THE SAME ONE, which is the register's own reason for leaving this open.
     *
     * Mirroring greentic alone "would have made greentic the only mirroring theme, which is a
     * different layout rather than a fix". The inverse is the regression this guards: reverting
     * ONE theme leaves two mirroring and one not, and nothing else in the suite notices.
     */
    #[Test]
    public function test_every_theme_positions_the_overlay_the_same_way(): void
    {
        $insets = [];

        foreach (array_keys(self::CONTAINERS) as $theme) {
            $insets[$theme] = implode(' ', array_values(array_filter(
                $this->containerClasses($theme),
                static fn ($c) => (bool) preg_match('/^(?:-)?(?:end|start|right|left)-/', $c)
            )));
        }

        $this->assertCount(1, array_unique($insets), sprintf(
            'The three themes no longer position the conversion-action overlay the same way: %s. '
            . '`MKT-010` asks for parity across the three, and one theme mirroring while two do '
            . 'not is a different layout rather than a fix -- which is exactly why GAP-220 left '
            . 'this as a three-theme change instead of doing a third of it.',
            json_encode($insets)
        ));
    }

    /**
     * THE PREMISE THAT MAKES THE UTILITY MORE THAN A STRING, and it is the reason this gap could
     * not be closed by reading documentation.
     *
     * The storefront loads Tailwind's RUNTIME JIT CDN, not a build: `end-4` only becomes
     * `inset-inline-end: 1rem` if THAT bundled copy's `inset` core plugin knows the logical
     * utilities, which were added to Tailwind well after this vendored bundle's shape was fixed.
     * Measured in Chromium on a rendered `/{store}/home` for each theme:
     * `.end-4 { inset-inline-end: 1rem; }`, injected inline by the runtime, 606-1205 injected
     * rules per page. This assertion is the cheap, in-suite shadow of that: if a theme's bundle is
     * ever swapped for one whose `inset` plugin has no `end` entry, the class in the card becomes
     * inert and the overlay silently loses its offset in both directions.
     */
    #[Test]
    #[DataProvider('themeProvider')]
    public function test_the_bundled_tailwind_runtime_maps_the_logical_inset(string $theme): void
    {
        $bundle = $this->read("themes/{$theme}/assets/js/tailwind.js");

        foreach ([
            'inset-inline-end' => 'end-*',
            'inset-inline-start' => 'start-*',
        ] as $property => $utility) {
            $this->assertStringContainsString($property, $bundle, sprintf(
                "themes/%s/assets/js/tailwind.js does not mention `%s`, so its `inset` core plugin "
                . 'cannot generate `%s` and the logical inset on that theme\'s overlay container is '
                . 'an inert string. The storefront loads this file as a RUNTIME JIT, so the '
                . 'utility exists only if this bundle knows it.',
                $theme,
                $property,
                $utility
            ));
        }

        /*
         * The mapping itself, not merely the property name: the bundle pairs the utility name with
         * the property inside the `inset` plugin's table, e.g. ["end",["inset-inline-end"]]. The
         * bundles are minified in one theme and pretty-printed in the other two, so whitespace
         * between the tokens is not fixed.
         */
        $this->assertMatchesRegularExpression(
            '/\[\s*"end"\s*,\s*\[\s*"inset-inline-end"\s*\]\s*\]/',
            $bundle,
            sprintf(
                "themes/%s/assets/js/tailwind.js mentions `inset-inline-end` but does not map the "
                . '`end` utility onto it, so `end-4` would generate nothing. Check the bundle '
                . 'before changing the card.',
                $theme
            )
        );
    }

    /**
     * THE HOOK IS STILL IN THE CONTAINER THIS CENSUS MEASURES -- except on techzonix, where it is
     * measured NOT to be, and that asymmetry is the finding rather than an oversight.
     *
     * If someone later moves the call on stylique or greentic out of the positioned container the
     * way techzonix's was moved, the assertions above would keep passing while measuring an
     * element the overlay no longer hangs off. This is the check that notices.
     */
    #[Test]
    public function test_the_overlay_hook_still_hangs_off_the_container_this_census_measures(): void
    {
        foreach (['stylique', 'greentic'] as $theme) {
            $card = $this->cardSource($theme);
            $tag = $this->containerTag($theme);

            $after = substr($card, (int) strpos($card, $tag) + strlen($tag));
            $call = strpos($after, 'Product::actionLinks');
            $close = strpos($after, '</div>');

            $this->assertNotFalse($call, $theme . "'s card no longer calls the hook after its overlay container.");
            $this->assertLessThan($close === false ? PHP_INT_MAX : $close, $call, sprintf(
                "%s's overlay call has moved OUT of the positioned container this census measures, "
                . 'so every assertion above is now about an element the buttons do not hang off. '
                . 'That is what happened on techzonix when the call was moved out of the product '
                . 'anchor -- re-point this census deliberately rather than letting it drift.',
                $theme
            ));
        }

        /*
         * techzonix, stated as a measurement rather than left implicit: the hook is a SIBLING of
         * `.pro-btn-wrapper`, so the container named above carries the wishlist control and the
         * conversion buttons sit in normal flow (measured in Chromium: 295x60, 1px from both card
         * edges, nearest positioned ancestor `div.relative.h-48`). Normal flow mirrors on its own,
         * which is why techzonix's buttons already swapped sides and only this container did not.
         */
        $techzonix = $this->cardSource('techzonix');
        $wrapperAt = strpos($techzonix, 'pro-btn-wrapper');
        $callAt = strpos($techzonix, 'Product::actionLinks');

        $this->assertNotFalse($wrapperAt);
        $this->assertNotFalse($callAt);
        $this->assertLessThan($wrapperAt, $callAt,
            "techzonix's hook is no longer a sibling that precedes `.pro-btn-wrapper`. If it has "
            . 'been moved back inside, this census and the class docblock above both describe a '
            . 'layout that no longer exists.');
    }
}
