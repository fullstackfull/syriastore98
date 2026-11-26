<?php

namespace Tests\Feature\Storefront;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE LOADING POLICY SURVIVES THE BLADE BRANCH AND REACHES THE BROWSER.
 * =================================================================================================
 *
 * GAP-274, and this test exists because its sibling CANNOT answer this question.
 * `tests/Unit/Ui/StorefrontImageDeliveryTest` is a SOURCE census, and the product gallery's main
 * image is written as one tag carrying both spellings:
 *
 *     @if ($loop->first) loading="eager" fetchpriority="high" @else loading="lazy" decoding="async" @endif
 *
 * To a census that tag contains `loading="eager"` AND `loading="lazy"`, so the census cannot tell
 * whether the branch picks the right one -- or whether it emits BOTH into one element, which is
 * what a misplaced `@endif` would do and which no source assertion would notice. Only rendering it
 * answers that, and it has to be rendered with MORE THAN ONE sub-image or the `@else` arm never
 * runs. The fixture below gives the product two.
 *
 * It renders through the real route, `GET {storeSlug}/product/{product_slug}`
 * (`routes/customer.php`, `page.product` -> `HomeController::product_detail`), not through
 * `view()->make()`, because the view is reached through `Theme::set()`'s `prependLocation()` and a
 * direct make would not prove the storefront serves this file.
 */
class StorefrontImageLoadingRendersTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $merchant = User::forceCreate([
            'name' => 'Image Merchant', 'email' => 'image-merchant@example.test',
            'password' => bcrypt('x'), 'type' => 'admin',
            'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->store = Store::create([
            'name' => 'Image Store', 'email' => 'image-store@example.test',
            'slug' => 'image-store', 'theme_id' => 'stylique',
            'created_by' => $merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);

        $merchant->current_store = $this->store->id;
        $merchant->save();

        $this->product = Product::forceCreate([
            'name' => 'Damascene Brass Tray',
            'slug' => 'damascene-brass-tray',
            'price' => 120,
            'product_stock' => 7,
            'track_stock' => 1,
            'variant_product' => 0,
            'store_id' => $this->store->id,
            'created_by' => $merchant->id,
        ]);

        /* TWO sub-images, which is the whole point: with one, `$loop->first` is always true and
         * the `@else` arm -- the lazy one -- is never rendered, so a broken `@else` would pass. */
        foreach (['gallery-one.png', 'gallery-two.png'] as $file) {
            ProductImage::forceCreate([
                'product_id' => $this->product->id,
                'image_path' => 'uploads/' . $this->store->id . '/' . $file,
                'image_url' => 'uploads/' . $this->store->id . '/' . $file,
            ]);
        }
    }

    private function page(): string
    {
        $response = $this->get('/' . $this->store->slug . '/product/' . $this->product->slug);

        $this->assertSame(
            200,
            $response->status(),
            'premise: the product page must render. A redirect here is `Product not found.` and a '
            . '500 is a broken fixture; either way nothing below is about the markup'
        );

        return $response->getContent() ?: '';
    }

    /** @return list<string> every rendered <img ...> element, in document order */
    private function renderedImages(string $html): array
    {
        preg_match_all('/<img\b[^>]*>/s', $html, $m);

        return $m[0] ?? [];
    }

    #[Test]
    public function test_the_fixture_really_renders_two_gallery_slides(): void
    {
        $this->assertSame(
            2,
            ProductImage::where('product_id', $this->product->id)->count(),
            'premise: without a SECOND sub-image the @else arm of the loading branch never runs '
            . 'and this file would pass while testing half of it'
        );

        $images = $this->renderedImages($this->page());

        $this->assertGreaterThanOrEqual(
            4,
            count($images),
            'premise: the gallery should render two main slides and two thumbnails'
        );
    }

    #[Test]
    public function test_no_rendered_image_carries_two_loading_values(): void
    {
        $confused = [];

        foreach ($this->renderedImages($this->page()) as $tag) {
            if (preg_match_all('/loading\s*=/', $tag) > 1) {
                $confused[] = trim(preg_replace('/\s+/', ' ', $tag) ?? '');
            }
        }

        $this->assertSame(
            [],
            $confused,
            "a rendered image carries more than one `loading` attribute, so the Blade conditional\n"
            . "emitted both arms into one element. A source census cannot see this -- the tag is\n"
            . "written once and contains both spellings:\n  " . implode("\n  ", $confused)
        );
    }

    #[Test]
    public function test_the_first_gallery_slide_is_eager_and_prioritised_and_the_second_is_not(): void
    {
        $main = array_values(array_filter(
            $this->renderedImages($this->page()),
            static fn ($t) => str_contains($t, 'w-full h-full absolute top-0 left-0 object-contain')
        ));

        $this->assertCount(
            2,
            $main,
            'premise: two main gallery slides should have rendered, one per sub-image'
        );

        $this->assertStringContainsString('loading="eager"', $main[0], 'the first slide is the LCP element');
        $this->assertStringContainsString('fetchpriority="high"', $main[0], 'and it must say so');
        $this->assertStringNotContainsString('loading="lazy"', $main[0], 'the LCP element must not be deferred');

        $this->assertStringContainsString(
            'loading="lazy"',
            $main[1],
            'the second slide is off-screen in the swiper, so deferring it is the whole point'
        );
        $this->assertStringNotContainsString(
            'fetchpriority="high"',
            $main[1],
            'priority is zero-sum: prioritising an off-screen slide demotes the one that paints'
        );
    }

    #[Test]
    public function test_every_gallery_thumbnail_is_deferred(): void
    {
        $thumbs = array_values(array_filter(
            $this->renderedImages($this->page()),
            static fn ($t) => str_contains($t, 'absolute top-0 left-0 w-full h-full object-contain')
        ));

        $this->assertNotSame([], $thumbs, 'premise: the thumbnail strip rendered');

        foreach ($thumbs as $thumb) {
            $this->assertStringContainsString('loading="lazy"', $thumb, 'thumbnails sit below the main image');
            $this->assertStringContainsString('decoding="async"', $thumb);
        }
    }

    #[Test]
    public function test_the_rendered_alt_text_names_this_product_and_not_the_demo_one(): void
    {
        $html = $this->page();

        $this->assertStringNotContainsString(
            'Organic Vegetable Box',
            $html,
            'the shipped demo alt is still being rendered, so every product on every store '
            . 'announces itself as a vegetable box to a screen reader and to a crawler'
        );

        $galleryAlts = [];
        foreach ($this->renderedImages($html) as $tag) {
            if (! str_contains($tag, 'object-contain')) {
                continue;
            }

            if (preg_match('/alt\s*=\s*"([^"]*)"/', $tag, $m) === 1) {
                $galleryAlts[] = $m[1];
            }
        }

        $this->assertNotSame([], $galleryAlts, 'premise: the gallery images carry an alt attribute');

        foreach ($galleryAlts as $alt) {
            $this->assertSame(
                $this->product->name,
                $alt,
                'a gallery image describes something other than the product it shows'
            );
        }
    }

    /**
     * THE BILINGUAL DIRECTION, which this change actually improves rather than merely preserves.
     * CLAUDE.md requires any UI change to be checked in Arabic (RTL) and English (LTR). The
     * attributes themselves are direction-neutral, but the alt text is not: it used to be the
     * English string "Organic Vegetable Box" on every product of every store, so an Arabic
     * storefront announced an English vegetable box. Now it is the product's own name, so the
     * Arabic store reads Arabic. That is worth an assertion because it is the half a reviewer
     * would assume rather than measure.
     */
    #[Test]
    public function test_an_arabic_product_name_reaches_the_alt_attribute_intact(): void
    {
        $arabicName = 'صينية نحاسية دمشقية';

        $this->product->name = $arabicName;
        $this->product->save();

        $html = $this->page();

        $galleryAlts = [];
        foreach ($this->renderedImages($html) as $tag) {
            if (! str_contains($tag, 'object-contain')) {
                continue;
            }

            if (preg_match('/alt\s*=\s*"([^"]*)"/u', $tag, $m) === 1) {
                $galleryAlts[] = $m[1];
            }
        }

        $this->assertNotSame([], $galleryAlts, 'premise: the gallery rendered with an alt attribute');

        foreach ($galleryAlts as $alt) {
            $this->assertSame(
                $arabicName,
                $alt,
                'an Arabic product name did not survive into the alt attribute. Blade escapes `{{ }}`'
                . ' with `htmlspecialchars`, which leaves Arabic alone but would mangle it if the '
                . 'view ever switched to a raw echo with the wrong charset'
            );
        }
    }
}
