<?php

namespace Tests\Unit\Ui;

use App\Support\Storefront\StorefrontImageSizes;
use Tests\TestCase;

/**
 * EVERY `<picture>` ON THE STOREFRONT IS WELL FORMED, AND ITS `<source>` IS ABOUT ITS OWN IMAGE.
 * =================================================================================================
 *
 * GAP-274, pipeline half. `StorefrontResponsiveImageRendersTest` renders the product page and reads
 * the markup a browser gets; this is the source-level ratchet beside it, and it exists for the two
 * mistakes a rendered test on one page cannot catch.
 *
 *  1. **A `<picture>` with no `<img>` shows nothing** to a browser that cannot decode WebP. The
 *     `<img>` is not a nicety inside a `<picture>`; it is the only required child and the only
 *     fallback. A wrap written without it would still render for almost everybody, which is what
 *     makes it worth a ratchet.
 *  2. **A `<source>` whose `srcset` is built from a DIFFERENT image than the `<img>`'s `src`** is
 *     the silent version of the same mistake: the page shows one product's photograph to most
 *     visitors and another's to the rest. Nothing about the page looks wrong. The product detail
 *     page is the shape that invites it -- it holds four images, two of them the same two paths in
 *     a different element -- so the test compares the first argument of each
 *     `storefront_webp_source()` call with the `src` of the `<img>` in the same block.
 *
 * AND THE `sizes` ARGUMENT MUST BE ONE OF THE THREE MEASURED CONSTANTS, never a literal. The
 * figures in `App\Support\Storefront\StorefrontImageSizes` come from a browser measurement, the
 * product card exists in FOUR copies, and `FileViewFinder::find()` memoises by view name -- so
 * under php-fpm whichever store a worker serves first fixes which copy every later store renders.
 * Four hand-written `sizes` strings would drift and the drift would show on only one of them.
 *
 * COMMENTS ARE STRIPPED FIRST, for the reason CLAUDE.md §3 gives eight measured instances of: this
 * file's own subject appears inside the Blade comments the wraps carry (`the <img> is UNCHANGED`,
 * `the <source> offers narrower WebP copies`), so without the strip the census would be reading the
 * explanations rather than the markup -- in both directions.
 */
class StorefrontPictureSourceTest extends TestCase
{
    /** The same roots `StorefrontImageDeliveryTest` reads, for the same reasons; see its TREES docblock. */
    private const TREES = [
        'themes/techzonix/views',
        'themes/greentic/views',
        'themes/stylique/views',
        'resources/views/front_end',
    ];

    private const EXTRA_VIEWS = ['resources/views/components/product-card.blade.php'];

    /**
     * HOW MANY IMAGES EACH FILE DELIVERS RESPONSIVELY, declared so that a new wrap has to be
     * thought about rather than appearing.
     *
     * WHY THESE FIVE FILES AND NOT ALL 76 TAGS. The ladder in `App\Services\ImageVariants` was
     * derived from a browser measurement of three elements -- the product detail page's main image
     * and thumbnail, and the product card's cover -- and a `sizes` attribute is a claim about a box
     * that has been measured. The remaining storefront images (a header logo, a 24px payment icon,
     * a hero decoration, a theme home page nobody has measured) would each need their own figure,
     * and an invented one is worse than none: over-declare and the browser downloads a wider
     * candidate than it needs, which makes the page HEAVIER than before any of this existed.
     *
     * The product card's four copies are all listed because any of them can be the one that
     * renders: `FileViewFinder::find()` memoises by view name, so the first store a php-fpm worker
     * serves fixes the copy for every later store in that process.
     *
     * @var array<string, int>
     */
    private const WRAPPED = [
        'resources/views/front_end/pages/product-detail.blade.php' => 4,
        'resources/views/components/product-card.blade.php' => 1,
        'themes/techzonix/views/components/product-card.blade.php' => 1,
        'themes/greentic/views/components/product-card.blade.php' => 1,
        'themes/stylique/views/components/product-card.blade.php' => 1,
    ];

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function withoutComments(string $source): string
    {
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source) ?? $source;

        return preg_replace('/<!--.*?-->/s', '', $source) ?? $source;
    }

    /** @return array<string, string> view path => comment-free source */
    private function views(): array
    {
        $out = [];

        foreach (self::TREES as $tree) {
            $dir = $this->root() . '/' . $tree;

            if (! is_dir($dir)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $entry) {
                if ($entry->isFile() && str_ends_with($entry->getFilename(), '.blade.php')) {
                    $out[ltrim(str_replace($this->root(), '', $entry->getPathname()), '/')] = $this->withoutComments((string) file_get_contents($entry->getPathname()));
                }
            }
        }

        foreach (self::EXTRA_VIEWS as $view) {
            if (is_file($this->root() . '/' . $view)) {
                $out[$view] = $this->withoutComments((string) file_get_contents($this->root() . '/' . $view));
            }
        }

        ksort($out);

        return $out;
    }

    /** @return list<array{view: string, block: string}> */
    private function pictures(): array
    {
        $blocks = [];

        foreach ($this->views() as $view => $source) {
            preg_match_all('/<picture\b.*?<\/picture>/s', $source, $m);

            foreach ($m[0] as $block) {
                $blocks[] = ['view' => $view, 'block' => $block];
            }
        }

        return $blocks;
    }

    public function test_the_wrapped_images_are_the_declared_ones(): void
    {
        $found = [];

        foreach ($this->pictures() as $picture) {
            $found[$picture['view']] = ($found[$picture['view']] ?? 0) + 1;
        }

        ksort($found);
        $expected = self::WRAPPED;
        ksort($expected);

        $this->assertSame(
            $expected,
            $found,
            "the set of storefront images delivered through a <picture> has changed. A new one needs\n"
            . "a measured `sizes` figure in App\\Support\\Storefront\\StorefrontImageSizes -- an\n"
            . 'invented one makes the page heavier, which is the opposite of the point.'
        );
    }

    public function test_no_storefront_picture_is_without_the_image_it_falls_back_to(): void
    {
        $broken = [];

        foreach ($this->pictures() as $picture) {
            if (preg_match_all('/<img(?![\w-])/', $picture['block']) !== 1) {
                $broken[] = $picture['view'] . ' :: ' . trim(preg_replace('/\s+/', ' ', $picture['block']) ?? '');
            }
        }

        $this->assertSame(
            [],
            $broken,
            "a <picture> does not hold exactly one <img>. The <img> is the only required child and\n"
            . "the ONLY thing a browser without WebP support can show, so a <picture> without one\n"
            . "renders nothing at all for those visitors -- and two would make the second dead\n"
            . "markup:\n  " . implode("\n  ", $broken)
        );
    }

    public function test_every_source_is_built_from_the_same_image_its_img_falls_back_to(): void
    {
        $mismatched = [];

        foreach ($this->pictures() as $picture) {
            $calls = $this->sourceCalls($picture['block']);

            $this->assertCount(
                1,
                $calls,
                'a <picture> in ' . $picture['view'] . ' holds ' . count($calls) . ' storefront_webp_source() '
                . 'calls rather than one, so either it offers no WebP at all or it offers two sources '
                . 'and only the first is ever used'
            );

            preg_match('/<img(?![\w-])(?:[^>]|(?<=[-=])>)*>/s', $picture['block'], $img);
            preg_match('/\bsrc="([^"]*)"/', $img[0] ?? '', $src);

            $path = $calls[0]['path'];

            if (! str_contains($src[1] ?? '', $path)) {
                $mismatched[] = $picture['view'] . ' :: <source> is built from `' . $path . '` while the <img> src is `' . ($src[1] ?? '(none)') . '`';
            }
        }

        $this->assertSame(
            [],
            $mismatched,
            "a <source>'s srcset is built from a DIFFERENT image than the <img> it sits beside, so\n"
            . "the page shows one picture to a browser with WebP support and another to a browser\n"
            . "without it -- and nothing about the page looks wrong:\n  " . implode("\n  ", $mismatched)
        );
    }

    public function test_every_sizes_argument_is_a_measured_constant(): void
    {
        $allowed = [
            'StorefrontImageSizes::PRODUCT_MAIN',
            'StorefrontImageSizes::PRODUCT_THUMBNAIL',
            'StorefrontImageSizes::PRODUCT_CARD',
        ];

        $literal = [];
        $used = [];

        foreach ($this->pictures() as $picture) {
            foreach ($this->sourceCalls($picture['block']) as $call) {
                $matched = null;

                foreach ($allowed as $constant) {
                    if (str_ends_with(trim($call['sizes']), $constant)) {
                        $matched = $constant;
                        break;
                    }
                }

                if ($matched === null) {
                    $literal[] = $picture['view'] . ' :: ' . trim($call['sizes']);
                } else {
                    $used[$matched] = ($used[$matched] ?? 0) + 1;
                }
            }
        }

        $this->assertSame(
            [],
            $literal,
            "a storefront <source> declares its `sizes` as something other than one of the three\n"
            . "measured constants. The figures come from a browser measurement of the real layout;\n"
            . "a literal here drifts from it silently, and the product card alone has four copies:\n  "
            . implode("\n  ", $literal)
        );

        // and all three are in use, so the test cannot pass by every wrap having chosen one of them
        ksort($used);
        $this->assertSame(
            ['StorefrontImageSizes::PRODUCT_CARD', 'StorefrontImageSizes::PRODUCT_MAIN', 'StorefrontImageSizes::PRODUCT_THUMBNAIL'],
            array_keys($used),
            'one of the three measured sizes is used nowhere, so it is either dead or something is rendering with the wrong one'
        );
    }

    public function test_no_storefront_source_call_sits_outside_a_picture(): void
    {
        /* A `<source>` outside a `<picture>` is inert markup: the browser ignores it, the image
         * loads from the `<img>` as before, and nothing fails -- so the whole feature would be
         * silently off for that image. */
        $outside = [];

        foreach ($this->views() as $view => $source) {
            $inPictures = 0;

            preg_match_all('/<picture\b.*?<\/picture>/s', $source, $m);

            foreach ($m[0] as $block) {
                $inPictures += count($this->sourceCalls($block));
            }

            $total = count($this->sourceCalls($source));

            if ($total !== $inPictures) {
                $outside[] = $view . ' :: ' . ($total - $inPictures) . ' of ' . $total . ' storefront_webp_source() calls are outside a <picture>';
            }
        }

        $this->assertSame([], $outside, implode("\n  ", $outside));
    }

    public function test_the_three_measured_sizes_describe_the_breakpoints_the_layout_changes_at(): void
    {
        /* The constants are data, and a typo in one is invisible: a browser given a malformed
         * `sizes` falls back to `100vw` and quietly downloads the widest candidate on every
         * surface. So the shape is asserted -- descending media queries, Tailwind's own
         * breakpoints, and a bare `vw` fallback last, which is the one entry the syntax requires. */
        foreach ([
            'PRODUCT_MAIN' => StorefrontImageSizes::PRODUCT_MAIN,
            'PRODUCT_THUMBNAIL' => StorefrontImageSizes::PRODUCT_THUMBNAIL,
            'PRODUCT_CARD' => StorefrontImageSizes::PRODUCT_CARD,
        ] as $name => $value) {
            $entries = array_map('trim', explode(',', $value));

            $this->assertCount(5, $entries, "{$name} does not describe four breakpoints and a fallback");

            $widths = [];

            foreach (array_slice($entries, 0, 4) as $entry) {
                $this->assertMatchesRegularExpression(
                    '/\A\(min-width: (\d+)px\) \d+px\z/',
                    $entry,
                    "{$name} has an entry that is not `(min-width: Npx) Mpx`: {$entry}"
                );
                preg_match('/(\d+)px\)/', $entry, $m);
                $widths[] = (int) $m[1];
            }

            $this->assertSame([1536, 1280, 1024, 768], $widths, "{$name}'s breakpoints are not Tailwind's, descending -- a browser reads the FIRST match, so order is the whole meaning");
            $this->assertMatchesRegularExpression('/\A\d+vw\z/', $entries[4], "{$name}'s last entry must be the unconditional one, and below the smallest breakpoint the box is a fraction of a viewport whose width is not known in advance");
        }
    }

    /**
     * The `storefront_webp_source(<path>, <sizes>, ...)` calls in a fragment, with their first two
     * arguments. Parsed by matching brackets rather than by a lazy regex, because the first
     * argument is a PHP expression and can itself contain parentheses.
     *
     * @return list<array{path: string, sizes: string}>
     */
    private function sourceCalls(string $fragment): array
    {
        $calls = [];
        $offset = 0;

        while (($at = strpos($fragment, 'storefront_webp_source(', $offset)) !== false) {
            $i = $at + strlen('storefront_webp_source(');
            $depth = 1;
            $args = [''];

            while ($i < strlen($fragment) && $depth > 0) {
                $c = $fragment[$i];

                if ($c === '(') {
                    $depth++;
                } elseif ($c === ')') {
                    $depth--;

                    if ($depth === 0) {
                        break;
                    }
                } elseif ($c === ',' && $depth === 1) {
                    $args[] = '';
                    $i++;

                    continue;
                }

                $args[count($args) - 1] .= $c;
                $i++;
            }

            $calls[] = ['path' => trim($args[0] ?? ''), 'sizes' => trim($args[1] ?? '')];
            $offset = $i;
        }

        return $calls;
    }
}
