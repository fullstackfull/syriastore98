<?php

namespace Tests\Unit\Ui;

use Tests\TestCase;

/**
 * EVERY STOREFRONT IMAGE DECLARES WHEN IT LOADS, AND ONLY A NAMED FEW LOAD EAGERLY.
 * =================================================================================================
 *
 * GAP-274. The recovered Phase 03 bullet at `master-plan/MASTER-PRODUCT-PLAN-v1.0-BASE.md:222`
 * names five obligations -- SEO, indexing, performance, **الصور (images)** and structured data --
 * and the images one had never been tracked: measured when the row was written, the gap register
 * contained ZERO rows mentioning image optimisation, lazy loading, responsive images or a modern
 * format. It had been sitting unread inside another row's prose summary since the plan was
 * recovered. This is the first slice against it.
 *
 * WHAT THIS SLICE DOES AND DELIBERATELY DOES NOT DO. It is the markup half: `loading`,
 * `decoding="async"`, `fetchpriority` and the alt text that goes with them. It does NOT do
 * responsive `srcset`, a modern format (WebP/AVIF) or a CDN, because each of those needs
 * derivative images generated in the upload pipeline -- the same pipeline that carries the
 * `PlatformUploader*` security tests -- and a storage-cost decision that is not an engineering
 * one. Those remain open under GAP-274 and are recorded there, not quietly dropped.
 *
 * WHY THE ALLOWLIST IS THE *EAGER* SET AND NOT THE LAZY ONE. CLAUDE.md §3: when a census hunts for
 * something dangerous, enumerate the SAFE set instead, because a list of dangerous spellings is
 * always short by the one nobody thought of. Here the hazard is an image with NO stated policy --
 * a new `<img>` somebody adds next month. So the census requires `loading` on every storefront
 * image and enumerates the handful allowed to be `eager`, each with its reason. An unlisted new
 * image fails this test rather than silently inheriting the browser default.
 *
 * AND "LAZY EVERYTHING" WOULD HAVE BEEN A PERFORMANCE DEFECT, NOT A FIX. `loading="lazy"` on the
 * element that paints largest DELAYS Largest Contentful Paint by a round trip: the browser must
 * lay the page out before it knows the image is in view, so it cannot start the fetch from the
 * preload scanner. The two LCP elements here are the product detail page's main image and the
 * article page's featured image, and both are `eager` with `fetchpriority="high"`. The product
 * gallery's SECOND and later slides are lazy -- `@if ($loop->first)` -- because a Swiper puts
 * every slide in the DOM at once and only one of them is visible.
 *
 * FOUR STOREFRONT TREES, AND THE FALLBACK IS NOT DEAD CODE. CLAUDE.md records that a theme's views
 * win through `Theme::set()` -> `prependLocation()`, so a view the theme does not override falls
 * through to `resources/views`. Measured when this was written: of the 21 fallback views carrying
 * an `<img>`, exactly ONE (`common/offer.blade.php`) is overridden by any theme. So the fallback
 * tree is the live storefront for almost every image on it, which is why the work is there and not
 * in the three theme directories.
 *
 * COMMENTS ARE STRIPPED BEFORE COUNTING, for the reason CLAUDE.md gives eight measured instances
 * of: a census that reads comments can be satisfied -- or broken -- by prose about the thing it
 * measures. This very docblock contains the strings `loading="lazy"`, `loading="eager"` and
 * `<img>`; without the strip, `test_every_storefront_image_declares_when_it_loads` would be
 * reading its own explanation.
 */
class StorefrontImageDeliveryTest extends TestCase
{
    /**
     * The storefront view trees, in `prependLocation()` precedence order. The three themes come
     * first because theirs win; `resources/views` is the fallback that serves everything they do
     * not override.
     */
    private const TREES = [
        'themes/techzonix/views/front_end',
        'themes/greentic/views/front_end',
        'themes/stylique/views/front_end',
        'resources/views/front_end',
    ];

    /**
     * THE EAGER ALLOWLIST. Keyed by view path, then by a distinguishing substring of the tag
     * itself -- NOT by line number, which CLAUDE.md's `GAP-304` records goes stale on any
     * insertion above it (four of five `place_order:<n>` citations were stale, each by a different
     * amount). The value is the reason, which is the part a future reader needs.
     */
    private const MAY_LOAD_EAGERLY = [
        'resources/views/front_end/pages/product-detail.blade.php' => [
            '$loop->first' => 'the gallery\'s first slide is this page\'s LCP element; slides 2..n are lazy in the same tag',
            'storefront_image($product->cover_image_path) }}" alt="{{ $product->name }}"' => 'the no-sub-images fallback IS the main image, so it is the LCP element',
        ],
        'resources/views/front_end/pages/article.blade.php' => [
            'w-10 h-10 rounded-full' => 'a 40px author avatar above the fold: lazy would cost a round trip and save nothing',
            'fetchpriority="high"' => 'the featured image is the article page\'s LCP element',
        ],
        'resources/views/front_end/pages/about.blade.php' => [
            'abouts_us_full_image\'] ?? \'\'' => 'the first about-us image sits above the fold',
        ],
        'resources/views/front_end/jQueryRoute.blade.php' => [
            'logo-img' => 'the header logo is above the fold on every storefront page',
            'style="display:none"' => 'a <noscript> tracking beacon: deferring a 1x1 pixel that must fire is incoherent',
        ],
        'themes/techzonix/views/front_end/partison/header.blade.php' => [
            'header_logo' => 'the header logo is above the fold on every storefront page',
        ],
        'themes/greentic/views/front_end/partison/header.blade.php' => [
            'header_logo' => 'the header logo is above the fold on every storefront page',
        ],
        'themes/stylique/views/front_end/partison/header.blade.php' => [
            'header_logo' => 'the header logo is above the fold on every storefront page',
        ],
    ];

    /**
     * THE IMAGES THAT ARE THE LARGEST CONTENTFUL PAINT, keyed by a distinguishing substring of the
     * tag rather than a line number. Each of these must be `fetchpriority="high"`, and the test
     * below checks EVERY tag a needle matches, not merely that the view contains one such tag
     * somewhere.
     *
     * THE WEAKER FORM OF THIS TEST SURVIVED A MUTANT. It asserted "this view has at least one
     * `fetchpriority="high"` image", and `product-detail.blade.php` has two main-image tags (the
     * slider's first slide and the no-sub-images fallback), so dropping the attribute from one of
     * them left the other to satisfy it -- 6 passed. The same weakness would have let somebody
     * prioritise a THUMBNAIL and leave the main image unprioritised, which is the exact mistake
     * the test exists to prevent.
     *
     * The product-detail needle is the main image's class string. It is not interchangeable with
     * the thumbnail's: both carry the same five utilities, but in a different order
     * (`w-full h-full absolute top-0 left-0` against `absolute top-0 left-0 w-full h-full`), so
     * the needle selects the two main images and neither thumbnail.
     */
    private const LCP_IMAGES = [
        'resources/views/front_end/pages/product-detail.blade.php' => [
            'class="w-full h-full absolute top-0 left-0 object-contain"' => 2,
        ],
        'resources/views/front_end/pages/article.blade.php' => [
            'alt="{{ $blog->title ?? \'\' }}"' => 1,
        ],
    ];

    /**
     * Demo alt text shipped with the theme that asserted something no data supports. These are not
     * merely generic (`alt="cart-image"` is generic and honest); each of these NAMED a product or
     * a person, so a screen reader announced every product as "Organic Vegetable Box" and every
     * author as "Emma Wilson", and a search engine indexed the same.
     */
    private const FALSE_ALT_TEXT = [
        'Organic Vegetable Box',
        'Emma Wilson',
        'Fresh Organic Onion',
        'article-banner',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<string> every storefront blade file, comment-free */
    private function storefrontViews(): array
    {
        $files = [];

        foreach (self::TREES as $tree) {
            $dir = $this->root() . '/' . $tree;

            if (! is_dir($dir)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $entry) {
                if ($entry->isFile() && str_ends_with($entry->getFilename(), '.blade.php')) {
                    $files[] = $entry->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private function withoutComments(string $source): string
    {
        // Blade comments first, then HTML comments. Both non-greedy, both across lines.
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source) ?? $source;

        return preg_replace('/<!--.*?-->/s', '', $source) ?? $source;
    }

    /** @return list<array{view: string, tag: string}> */
    private function imageTags(): array
    {
        $tags = [];

        foreach ($this->storefrontViews() as $path) {
            $source = $this->withoutComments((string) file_get_contents($path));
            $view = ltrim(str_replace($this->root(), '', $path), '/');

            /* FROM `<img` TO THE `>` THAT REALLY CLOSES IT, WHICH IS NOT THE FIRST ONE.
             *
             * This was written as `.*?>` and that was wrong in the QUIET direction: a Blade echo
             * inside an attribute contains `->` all the time (`$item->image_path`,
             * `$loop->first`), so the non-greedy match stopped INSIDE the expression and the
             * census read a truncated tag. It still counted as a tag, so the count premise was
             * satisfied; what it lost was the attributes further right. Measured: it reported that
             * `product-detail.blade.php` carried no `fetchpriority="high"` when the file plainly
             * does, because the match ended at `$loop->`.
             *
             * So: consume anything that is not `>`, plus any `>` that is preceded by `-` or `=`
             * (the `->` and `=>` of PHP), and stop at the first `>` that is neither. */
            if (preg_match_all('/<img(?![\w-])(?:[^>]|(?<=[-=])>)*>/s', $source, $m) === false) {
                continue;
            }

            foreach ($m[0] as $tag) {
                $tags[] = ['view' => $view, 'tag' => $tag];
            }
        }

        return $tags;
    }

    public function test_the_census_really_reads_the_storefront_image_tags(): void
    {
        $tags = $this->imageTags();

        $this->assertGreaterThanOrEqual(
            40,
            count($tags),
            'the storefront carried 46 image tags when this was written; a collapse here means the '
            . 'census stopped seeing the view tree and every other assertion in this file is vacuous'
        );

        /* AND NO TAG WAS TRUNCATED MID-EXPRESSION, which is the way this census failed when it
         * was written. A match that stopped at `$loop->` ends in `->`, so the character before
         * the closing `>` tells us directly. Without this, a regex regression makes every other
         * assertion in this file read clean while examining half of each tag. */
        $truncated = array_values(array_filter(
            $tags,
            static fn ($t) => (bool) preg_match('/[-=]>$/', rtrim($t['tag']))
        ));

        $this->assertSame(
            [],
            array_map(static fn ($t) => $t['view'] . ' :: ' . trim($t['tag']), $truncated),
            'an image tag match ends in `->` or `=>`, so it stopped inside a Blade expression '
            . 'rather than at the end of the tag, and every attribute to its right is invisible '
            . 'to this census'
        );

        $views = array_unique(array_column($tags, 'view'));

        $this->assertGreaterThanOrEqual(
            4,
            count(array_filter($views, static fn ($v) => str_starts_with($v, 'themes/'))),
            'premise: the theme trees are being read too, not only the fallback'
        );
    }

    public function test_every_storefront_image_declares_when_it_loads(): void
    {
        $silent = [];

        foreach ($this->imageTags() as $tag) {
            if (! str_contains($tag['tag'], 'loading=')) {
                $silent[] = $tag['view'] . ' :: ' . trim(preg_replace('/\s+/', ' ', $tag['tag']) ?? '');
            }
        }

        $this->assertSame(
            [],
            $silent,
            "a storefront image states no loading policy, so it inherits the browser default and\n"
            . "nobody decided that. Add loading=\"lazy\" decoding=\"async\", or, if it is above the\n"
            . "fold, loading=\"eager\" plus an entry in MAY_LOAD_EAGERLY saying why:\n  "
            . implode("\n  ", $silent)
        );
    }

    public function test_only_the_named_images_load_eagerly(): void
    {
        $unjustified = [];

        foreach ($this->imageTags() as $tag) {
            if (! str_contains($tag['tag'], 'loading="eager"')) {
                continue;
            }

            $reasons = self::MAY_LOAD_EAGERLY[$tag['view']] ?? [];
            $matched = false;

            foreach (array_keys($reasons) as $needle) {
                if (str_contains($tag['tag'], $needle)) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $unjustified[] = $tag['view'] . ' :: ' . trim(preg_replace('/\s+/', ' ', $tag['tag']) ?? '');
            }
        }

        $this->assertSame(
            [],
            $unjustified,
            "an image loads eagerly with no recorded reason. Eager is correct for the element that\n"
            . "paints largest and for anything above the fold, and wrong for everything else --\n"
            . "say which it is in MAY_LOAD_EAGERLY:\n  " . implode("\n  ", $unjustified)
        );
    }

    public function test_the_eager_allowlist_has_no_entry_that_matches_nothing(): void
    {
        $tags = $this->imageTags();
        $dead = [];

        foreach (self::MAY_LOAD_EAGERLY as $view => $reasons) {
            foreach ($reasons as $needle => $reason) {
                $found = false;

                foreach ($tags as $tag) {
                    if ($tag['view'] === $view && str_contains($tag['tag'], (string) $needle)) {
                        $found = true;
                        break;
                    }
                }

                if (! $found) {
                    $dead[] = $view . ' :: ' . $needle;
                }
            }
        }

        $this->assertSame(
            [],
            $dead,
            "an allowlist entry matches no image tag. A stale exemption is how an allowlist stops\n"
            . "being a statement about the product: delete it, or re-point it at the tag it meant:\n  "
            . implode("\n  ", $dead)
        );
    }

    public function test_no_storefront_image_claims_a_product_or_person_the_data_does_not_name(): void
    {
        $lying = [];

        foreach ($this->imageTags() as $tag) {
            /* THE INSTRUMENT IS THE `alt` ATTRIBUTE, NOT THE WHOLE TAG, and the difference was a
             * false positive: `article.blade.php`'s featured image carries
             * `src="... ?? 'assets/images/article-banner-1.png'"`, so a tag-wide search for
             * `article-banner` flagged a file whose alt had already been fixed. A census must
             * measure the thing it is named after. */
            if (preg_match_all('/alt\s*=\s*(["\'])(.*?)\1/s', $tag['tag'], $m) === false) {
                continue;
            }

            foreach (($m[2] ?? []) as $alt) {
                foreach (self::FALSE_ALT_TEXT as $demo) {
                    if (str_contains($alt, $demo)) {
                        $lying[] = $tag['view'] . ' :: alt="' . $alt . '" contains the demo string "' . $demo . '"';
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $lying,
            "a shipped demo alt describes content the page does not have. Every product announced\n"
            . "as \"Organic Vegetable Box\" and every author as \"Emma Wilson\" -- to a screen reader\n"
            . "and to a search engine alike:\n  " . implode("\n  ", $lying)
        );
    }

    public function test_every_lcp_image_is_prioritised_and_no_thumbnail_is(): void
    {
        $tags = $this->imageTags();

        foreach (self::LCP_IMAGES as $view => $needles) {
            foreach ($needles as $needle => $expected) {
                $matching = array_values(array_filter(
                    $tags,
                    static fn ($t) => $t['view'] === $view && str_contains($t['tag'], (string) $needle)
                ));

                $this->assertCount(
                    $expected,
                    $matching,
                    'premise: ' . $view . ' should carry ' . $expected . ' tag(s) matching "'
                    . $needle . '". A different count means the markup moved and this register is '
                    . 'describing a page that no longer exists'
                );

                foreach ($matching as $tag) {
                    $this->assertStringContainsString(
                        'fetchpriority="high"',
                        $tag['tag'],
                        $view . ' has an LCP image that is not prioritised. `loading="eager"` only '
                        . 'stops the browser deferring it; on a page whose largest paint IS this '
                        . 'image, saying so is what moves the metric the plan\'s performance '
                        . 'obligation is about: ' . trim(preg_replace('/\s+/', ' ', $tag['tag']) ?? '')
                    );
                }
            }
        }
    }

    public function test_no_image_outside_the_lcp_register_is_prioritised(): void
    {
        $stolen = [];

        foreach ($this->imageTags() as $tag) {
            if (! str_contains($tag['tag'], 'fetchpriority="high"')) {
                continue;
            }

            $isRegistered = false;

            foreach (self::LCP_IMAGES[$tag['view']] ?? [] as $needle => $_) {
                if (str_contains($tag['tag'], (string) $needle)) {
                    $isRegistered = true;
                    break;
                }
            }

            if (! $isRegistered) {
                $stolen[] = $tag['view'] . ' :: ' . trim(preg_replace('/\s+/', ' ', $tag['tag']) ?? '');
            }
        }

        $this->assertSame(
            [],
            $stolen,
            "an image claims high fetch priority without being registered as a largest contentful\n"
            . "paint. Priority is zero-sum -- the browser reorders the queue, so prioritising a\n"
            . "thumbnail DEMOTES the image that actually paints:\n  " . implode("\n  ", $stolen)
        );
    }
}
