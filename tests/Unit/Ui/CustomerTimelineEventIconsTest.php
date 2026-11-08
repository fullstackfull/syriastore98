<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * THE SEVEN TIMELINE EVENT ICONS.  P2.5-PKG-H slice 2
 * =================================================================================================
 *
 * `customer/timeline.blade.php` drew its seven event icons as FOURTEEN inline `<svg>` elements --
 * two copies of each, one per `$key % 2` arm -- carrying 42 `fill="black"`, on SIX different
 * internal scales (458x446, 460x460, 360x513, 369x522, 512x480 shared by the two cart types, and
 * 480x454), every one forced into `width="13" height="14"`. So what differed between them was not
 * their box but their apparent STROKE WEIGHT: a 369-unit drawing and a 512-unit drawing squeezed
 * into 13px do not land on the same visual weight.
 *
 * `fill="black"` ALSO IGNORED THE THEME. `.tl-btn` declares no colour in light mode, and the dark
 * arm at public/css/custom.css:5353 sets `color: var(--ss-grey-900, #1a1d23)` on `.tl-box .tl-btn`.
 * The hard-coded glyph was the one thing in the chip that did not follow that guard. A `ti` glyph
 * inherits `currentColor`, so it is now the colour of the label beside it by construction.
 *
 * WHAT THIS TEST IS FOR, and it is not glyph existence -- `IconNamesExistTest` already enforces
 * that every `ti-*` name in the repository is in the bundled font. This pins the MAPPING: which
 * event gets which glyph. Two of the seven were chosen against a plausible alternative for a
 * measured reason, and without this test a later edit could quietly undo either:
 *
 *   * `add wishlist` / `delete wishlist` are BOOKMARK, not HEART. `ti-heart` exists, but
 *     ti-heart-plus, ti-heart-minus and ti-heart-off are ALL absent from the bundled font, so an
 *     add/remove pair cannot be built from it. Bookmark is the only family here offering a true
 *     on/off pair.
 *   * `remove to cart` is `-x`, NOT `-off`. The two hand-drawn cart glyphs shared a cart body and
 *     differed only in a corner badge -- the `-plus`/`-x` relationship, not the `-off` one. Both
 *     names are bundled, so matching what the drawings already did cost nothing.
 *
 * It also holds the inline SVGs out. The file is the only place in the merchant admin that drew its
 * own icons, and a single `fill="black"` returning would be invisible in review and wrong in dark
 * mode.
 */
class CustomerTimelineEventIconsTest extends TestCase
{
    private const VIEW = 'resources/views/customer/timeline.blade.php';

    /** event log_type => the Tabler glyph that must sit in its chip. */
    private const MAPPING = [
        'register' => 'ti-user-plus',
        'last login' => 'ti-login',
        'add wishlist' => 'ti-bookmark',
        'delete wishlist' => 'ti-bookmark-off',
        'add to cart' => 'ti-shopping-cart-plus',
        'remove to cart' => 'ti-shopping-cart-x',
        'place order' => 'ti-package',
    ];

    private static function source(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::VIEW;
        self::assertFileExists($path, self::VIEW . ' is gone; this ratchet names it directly.');

        return (string) file_get_contents($path);
    }

    /**
     * Strips Blade comments, so the rationale blocks above each slice -- which quote the old
     * markup, `fill="black"` included -- cannot satisfy or break an assertion about live markup.
     */
    private static function live(): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', self::source());
    }

    public function test_each_event_branch_carries_its_mapped_glyph(): void
    {
        $live = self::live();

        foreach (self::MAPPING as $logType => $glyph) {
            $pos = strpos($live, "log_type == '" . $logType . "'");
            $this->assertNotFalse(
                $pos,
                "the branch for log_type '{$logType}' is gone from " . self::VIEW
                . ' -- if the event was retired, retire its row here too rather than deleting this'
                . ' assertion.'
            );

            // The chip is the first thing in the branch, so the glyph is within a short window.
            $window = substr($live, $pos, 600);

            $this->assertStringContainsString(
                'ti ' . $glyph . '"',
                $window,
                "the '{$logType}' chip no longer carries {$glyph}. The mapping is deliberate: see"
                . ' this file\'s docblock for why wishlist is bookmark-not-heart and why'
                . ' remove-to-cart is -x and not -off.'
            );
        }
    }

    public function test_the_glyphs_are_decorative_because_the_chip_already_names_the_event(): void
    {
        $live = self::live();

        $icons = preg_match_all('/<i class="ti ti-[a-z0-9-]+"[^>]*>/', $live, $m);

        $this->assertGreaterThanOrEqual(
            count(self::MAPPING),
            $icons,
            'fewer icons than mapped events'
        );

        foreach ($m[0] as $tag) {
            $this->assertStringContainsString(
                'aria-hidden="true"',
                $tag,
                "{$tag} is announced to assistive technology. The chip's text already names the"
                . ' event via ActivityLogLabel::for(), so an announced glyph reads it twice.'
            );
        }
    }

    public function test_the_view_draws_no_icons_of_its_own(): void
    {
        $live = self::live();

        $this->assertSame(
            0,
            substr_count($live, '<svg'),
            self::VIEW . ' is drawing its own icons again. It carried 14 inline SVGs on six'
            . ' internal scales, which is where the mismatched stroke weights came from.'
        );

        $this->assertSame(
            0,
            substr_count($live, 'fill="black"'),
            'a hard-coded black fill is back. `.tl-btn` has a dark-mode colour guard at'
            . ' public/css/custom.css:5353; a hard-coded fill does not follow it.'
        );
    }

    /**
     * PREMISE: the comment-stripping above is load-bearing, so it is proved rather than trusted.
     * The slice docblocks quote `fill="black"` and `<svg` while describing what was removed; if
     * stripping ever stopped working, test_the_view_draws_no_icons_of_its_own would fail for a
     * reason that has nothing to do with the markup.
     */
    public function test_the_premise_that_the_rationale_blocks_really_are_stripped(): void
    {
        $raw = self::source();
        $live = self::live();

        $this->assertStringContainsString('{{--', $raw, 'this view has no Blade comments at all, so this premise no longer holds');
        $this->assertStringNotContainsString('{{--', $live);
        $this->assertLessThan(strlen($raw), strlen($live), 'stripping removed nothing');
    }
}
