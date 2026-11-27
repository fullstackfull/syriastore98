<?php

namespace Tests\Feature\Storefront;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Store;
use App\Models\User;
use App\Services\ImageVariants;
use App\Support\Storefront\StorefrontImageSizes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE WEBP `<source>` REACHES THE BROWSER, SCOPED TO THE ELEMENT IT BELONGS TO.
 * =================================================================================================
 *
 * GAP-274, pipeline half. Rendered through the real route
 * (`GET {storeSlug}/product/{product_slug}` -> `HomeController::product_detail`), not through
 * `view()->make()`, because the view is reached through `Theme::set()`'s `prependLocation()` and a
 * direct make would not prove the storefront serves this file.
 *
 * EVERY ASSERTION IS SCOPED TO ONE `<picture>` BLOCK, not to the page body. CLAUDE.md §3 records a
 * mutation surviving a whole-body assertion because another component on the page carried the same
 * string; this page renders FOUR pictures -- a main image and a thumbnail per gallery slide -- with
 * two different `sizes` values, so "the page contains the card `sizes`" would be satisfied by the
 * wrong element every time. The parser below cuts the body into `<picture>` blocks and each
 * assertion names the block it is about.
 *
 * THE FIXTURE WRITES REAL FILES, and that is not a shortcut either. `ImageVariants` resolves
 * through `realpath(base_path(...))` and `gd` reads with ordinary file functions -- the `Storage`
 * facade is not in the path -- so `Storage::fake('theme')` would put the fixture where
 * `base_path()` cannot see it and every assertion here would pass on "there were no variants". The
 * premise assertions below say the files are where they are claimed to be.
 *
 * BOTH DIRECTIONS. Arabic (RTL) and English (LTR) are driven separately, because the `sizes`
 * attribute is a layout claim and a mirrored layout could have mirrored the measurement. (It does
 * not: the boxes measured identical in both, which is why one constant serves both.)
 */
class StorefrontResponsiveImageRendersTest extends TestCase
{
    use RefreshDatabase;

    /** A store id no store has: the variant folder has to be the store's own or nothing is written. */
    private const STORE_ID = 987321;

    private Store $store;
    private Product $product;
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->folder = base_path('uploads/' . self::STORE_ID);

        $this->assertDirectoryDoesNotExist(
            $this->folder,
            'premise: uploads/' . self::STORE_ID . ' must not exist yet, or what is read back could be a previous run\'s'
        );

        mkdir($this->folder, 0o755, true);

        $merchant = User::forceCreate([
            'name' => 'Responsive Image Merchant', 'email' => 'responsive-image@example.test',
            'password' => bcrypt('x'), 'type' => 'admin',
            'created_by' => 1, 'email_verified_at' => now(),
        ]);

        /* The store's id is FORCED to the folder's number. Without that, "the route's store", "the
         * folder the variants are in" and "the id ImageVariants is handed" would be three different
         * numbers and the confinement would refuse everything -- which reads as "no variants" and
         * would make every assertion below vacuous. */
        $this->store = Store::forceCreate([
            'id' => self::STORE_ID,
            'name' => 'Responsive Image Store', 'email' => 'responsive-image-store@example.test',
            'slug' => 'responsive-image-store', 'theme_id' => 'stylique',
            'created_by' => $merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);

        $merchant->current_store = $this->store->id;
        $merchant->save();

        $this->product = Product::forceCreate([
            'name' => 'Damascene Brass Tray',
            'slug' => 'damascene-brass-tray',
            'price' => 120, 'product_stock' => 7, 'track_stock' => 1, 'variant_product' => 0,
            'status' => 1, 'product_type' => null,
            'store_id' => $this->store->id,
            'created_by' => $merchant->id,
            'cover_image_path' => $this->writeOriginal('cover', 1000, 750),
        ]);

        /* TWO sub-images, so the gallery's `@else` arm renders: with one, `$loop->first` is always
         * true and the lazy arm -- which has to keep its attributes through the wrap -- never runs. */
        foreach (['gallery-one', 'gallery-two'] as $file) {
            ProductImage::forceCreate([
                'product_id' => $this->product->id,
                'image_path' => $this->writeOriginal($file, 1000, 750),
                'image_url' => '',
                'store_id' => $this->store->id,
            ]);
        }
    }

    protected function tearDown(): void
    {
        foreach (['variants', ''] as $sub) {
            $dir = rtrim($this->folder . '/' . $sub, '/');

            if (! is_dir($dir)) {
                continue;
            }

            foreach ((array) glob($dir . '/*') as $entry) {
                if (is_file($entry)) {
                    unlink($entry);
                }
            }

            @rmdir($dir);
        }

        parent::tearDown();
    }

    /** A real image on the real disk, returned as its stored path. */
    private function writeOriginal(string $name, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 160, 110, 70));
        $relative = 'uploads/' . self::STORE_ID . '/' . $name . '.png';
        imagepng($image, base_path($relative));
        imagedestroy($image);

        return $relative;
    }

    private function page(string $language): string
    {
        $this->store->default_language = $language;
        $this->store->save();

        $response = $this->get('/' . $this->store->slug . '/product/' . $this->product->slug);

        $this->assertSame(
            200,
            $response->status(),
            'premise: the product page must render. A 302 here is `Product not found.` and a 500 a '
            . 'broken fixture; either way nothing below is about the markup'
        );

        $html = $response->getContent() ?: '';

        $this->assertMatchesRegularExpression(
            '/<html[^>]*\bdir="' . ($language === 'ar' ? 'rtl' : 'ltr') . '"/',
            $html,
            "premise: the page did not render dir=\"" . ($language === 'ar' ? 'rtl' : 'ltr') . '", so this is not the direction it claims'
        );

        return $html;
    }

    /** @return list<string> every `<picture>...</picture>` block, in document order */
    private function pictures(string $html): array
    {
        preg_match_all('/<picture\b.*?<\/picture>/s', $html, $m);

        return $m[0];
    }

    /**
     * How many of each kind of image the page's `<picture>` blocks hold.
     *
     * Identified by the `<img>` class string inside each block. The main image's and the
     * thumbnail's carry THE SAME FIVE UTILITIES IN A DIFFERENT ORDER
     * (`w-full h-full absolute top-0 left-0` against `absolute top-0 left-0 w-full h-full`), which
     * is the only thing that separates them -- the same needle `StorefrontImageDeliveryTest` uses,
     * and the reason a looser one would check the wrong element.
     *
     * @param  list<string>  $pictures
     * @return array<string, int>
     */
    private function census(array $pictures): array
    {
        $counts = ['main' => 0, 'thumbnail' => 0, 'card' => 0];

        foreach ($pictures as $index => $picture) {
            if (str_contains($picture, 'class="w-full h-full absolute top-0 left-0 object-contain"')) {
                $counts['main']++;
            } elseif (str_contains($picture, 'class="absolute top-0 left-0 w-full h-full object-contain"')) {
                $counts['thumbnail']++;
            } elseif (str_contains($picture, 'product-image')) {
                $counts['card']++;
            } else {
                $this->fail("picture {$index} holds an <img> this test cannot identify: " . $picture);
            }
        }

        return $counts;
    }

    /** @return array<string, array{0: string}> */
    public static function languages(): array
    {
        return ['english' => ['en'], 'arabic' => ['ar']];
    }

    #[Test]
    #[DataProvider('languages')]
    public function test_the_variants_are_generated_at_save_and_sit_in_the_stores_own_folder(string $language): void
    {
        /* The premise for everything else: the observer ran when the paths were persisted, nobody
         * had to call the generator, and the files are under this store's folder. Driven once per
         * language so a provider-wide fixture failure cannot hide behind the other case. */
        $variants = ImageVariants::existing($this->product->cover_image_path, self::STORE_ID);

        $this->assertSame([160, 320, 480, 768], array_keys($variants), 'the cover image got no variants, so nothing was generated at save');

        foreach ($variants as $width => $relative) {
            $this->assertStringStartsWith('uploads/' . self::STORE_ID . '/variants/', $relative, 'a variant landed outside this store\'s folder');
            $this->assertFileExists(base_path($relative));
            $this->assertSame($width, (int) getimagesize(base_path($relative))[0]);
        }

        foreach (ProductImage::withoutGlobalScopes()->where('product_id', $this->product->id)->get() as $image) {
            $this->assertSame(
                [160, 320, 480, 768],
                array_keys(ImageVariants::existing($image->image_path, self::STORE_ID)),
                'a gallery image got no variants, so the ProductImage half of the observer did not run'
            );
        }

        $this->assertNotSame('', $language);
    }

    #[Test]
    #[DataProvider('languages')]
    public function test_every_gallery_picture_offers_webp_at_every_generated_width(string $language): void
    {
        $pictures = $this->pictures($this->page($language));

        /* PREMISE: WHICH ELEMENTS THESE FIVE ARE, not merely how many. The gallery renders two
         * slides -- a main image and a thumbnail each -- and the page's similar-products slider
         * renders one `<x-product-card>`, so the card wrap is exercised through a real route too.
         * A count alone would be satisfied by five of the wrong element. */
        $this->assertSame(
            ['main' => 2, 'thumbnail' => 2, 'card' => 1],
            $this->census($pictures),
            'the product page no longer renders the elements this test is about'
        );

        foreach ($pictures as $index => $picture) {
            $this->assertStringContainsString('<source type="image/webp"', $picture, "picture {$index} offers no WebP");

            preg_match('/<source[^>]*\bsrcset="([^"]*)"/', $picture, $m);

            $this->assertNotEmpty($m, "picture {$index} has a <source> with no srcset");

            $widths = [];
            foreach (explode(',', $m[1]) as $candidate) {
                $parts = preg_split('/\s+/', trim($candidate));
                $this->assertCount(2, $parts, "a srcset candidate in picture {$index} is not `<url> <width>w`");
                $this->assertStringContainsString('/uploads/' . self::STORE_ID . '/variants/', $parts[0], "a srcset candidate in picture {$index} points outside this store's variant folder");
                $widths[] = $parts[1];
            }

            $this->assertSame(['160w', '320w', '480w', '768w'], $widths, "picture {$index} does not offer every generated rung, ascending");
        }
    }

    #[Test]
    #[DataProvider('languages')]
    public function test_the_main_image_and_the_thumbnail_declare_their_own_measured_sizes(string $language): void
    {
        /* THE ASSERTION THAT MAKES THE SCOPING MATTER. Both `sizes` strings are on the same page, so
         * "the page contains PRODUCT_MAIN" would be satisfied by the thumbnail's block and a mutant
         * that swapped the two would survive. Each block is identified by the <img> class string
         * inside it -- the main image's and the thumbnail's carry the same five utilities in a
         * different order, which is the only thing that tells them apart. */
        $main = 0;
        $thumbnail = 0;
        $card = 0;

        foreach ($this->pictures($this->page($language)) as $index => $picture) {
            preg_match('/<source[^>]*\bsizes="([^"]*)"/', $picture, $m);
            $this->assertNotEmpty($m, "picture {$index} has a <source> with no sizes");
            $sizes = htmlspecialchars_decode($m[1]);

            if (str_contains($picture, 'class="w-full h-full absolute top-0 left-0 object-contain"')) {
                $main++;
                $this->assertSame(StorefrontImageSizes::PRODUCT_MAIN, $sizes, "the main image in picture {$index} declares the wrong sizes");
            } elseif (str_contains($picture, 'class="absolute top-0 left-0 w-full h-full object-contain"')) {
                $thumbnail++;
                $this->assertSame(StorefrontImageSizes::PRODUCT_THUMBNAIL, $sizes, "the thumbnail in picture {$index} declares the wrong sizes");
            } elseif (str_contains($picture, 'product-image')) {
                $card++;
                $this->assertSame(StorefrontImageSizes::PRODUCT_CARD, $sizes, "the product card in picture {$index} declares the wrong sizes");
            } else {
                $this->fail("picture {$index} holds an <img> this test cannot identify, so its sizes went unchecked: " . $picture);
            }
        }

        $this->assertSame(2, $main, 'both main images must be checked, not one of them');
        $this->assertSame(2, $thumbnail, 'both thumbnails must be checked, not one of them');
        $this->assertSame(1, $card, 'the similar-products card must be checked too');
    }

    #[Test]
    #[DataProvider('languages')]
    public function test_the_wrap_did_not_take_the_loading_policy_away(string $language): void
    {
        /* The markup half of GAP-274 is the thing most easily broken by this one: inside a
         * <picture> the <img> is still the element that loads, so its loading/decoding/
         * fetchpriority still govern -- but only if they are still there. */
        $pictures = $this->pictures($this->page($language));

        $eager = 0;
        $lazy = 0;

        foreach ($pictures as $index => $picture) {
            preg_match('/<img\b[^>]*>/s', $picture, $m);
            $this->assertNotEmpty($m, "picture {$index} contains no <img> at all, so there is no fallback for a browser without WebP");
            $img = $m[0];

            $this->assertMatchesRegularExpression('/\bloading="(eager|lazy)"/', $img, "the <img> in picture {$index} lost its loading policy");
            $this->assertStringContainsString('src="', $img, "the <img> in picture {$index} lost its src, which is the non-WebP fallback");

            if (str_contains($img, 'loading="eager"')) {
                $eager++;
                $this->assertStringContainsString('fetchpriority="high"', $img, 'an eager gallery image must be the prioritised one');
            } else {
                $lazy++;
                $this->assertStringContainsString('decoding="async"', $img, "the lazy <img> in picture {$index} lost decoding=\"async\"");
            }
        }

        $this->assertSame(1, $eager, 'exactly the first slide is eager: the LCP element, and only it');
        $this->assertSame(4, $lazy, 'the second slide, both thumbnails and the similar-products card are lazy');
    }

    #[Test]
    #[DataProvider('languages')]
    public function test_an_image_with_no_variants_renders_with_no_source_at_all(string $language): void
    {
        /* THE CONTRACT: an <img> with no variants must render exactly as it does today. Driven by
         * deleting the variants rather than by a second product, so the only difference between
         * this case and the one above is the files. */
        foreach ((array) glob($this->folder . '/variants/*') as $file) {
            unlink($file);
        }

        $this->assertSame(
            [],
            ImageVariants::existing($this->product->cover_image_path, self::STORE_ID),
            'premise: the variants really are gone'
        );

        $pictures = $this->pictures($this->page($language));

        $this->assertSame(
            ['main' => 2, 'thumbnail' => 2, 'card' => 1],
            $this->census($pictures),
            'the <picture> wrappers still render; it is the <source> inside them that must be absent'
        );

        foreach ($pictures as $index => $picture) {
            $this->assertStringNotContainsString('<source', $picture, "picture {$index} offers a WebP candidate that is not on disk");
            $this->assertStringNotContainsString('srcset', $picture, "picture {$index} advertises a srcset with nothing behind it");
            $this->assertMatchesRegularExpression('/<img\b[^>]*\bsrc="[^"]+"/', $picture, "picture {$index} lost the original it falls back to");
        }
    }

    #[Test]
    public function test_a_row_whose_path_names_a_sibling_stores_folder_gets_no_variants_there(): void
    {
        /* THIS CASE EXISTS BECAUSE A MUTATION SURVIVED WITHOUT IT. Replacing the observer's
         * `self::storeOf($model->store_id)` with `null` left every other test green: the fixture's
         * product and its files are in ONE store's folder, so "with the store id" and "without it"
         * cannot disagree there. The input that separates them is a row whose path names ANOTHER
         * store's folder -- and that row is reachable, because the two rules differ on purpose:
         * `StoredPathPolicy` admits a folder belonging to another store OF THE SAME OWNER, so such
         * a value SAVES, while `ImageVariants` is deliberately tighter and will not write there.
         *
         * With the store id, nothing is written into the sibling's folder. Without it, the folder
         * is simply "a store folder" and the variants land in it -- one merchant's store writing
         * files into another's directory. */
        $sibling = Store::forceCreate([
            'id' => self::STORE_ID + 7,
            'name' => 'Sibling Store', 'email' => 'sibling-store@example.test',
            'slug' => 'sibling-store', 'theme_id' => 'stylique',
            'created_by' => $this->store->created_by, 'is_active' => 1, 'default_language' => 'en',
        ]);

        $foreign = $this->writeOriginal('sibling-cover', 1000, 750);

        $product = Product::forceCreate([
            'name' => 'Sibling Product', 'slug' => 'sibling-product',
            'price' => 90, 'product_stock' => 3, 'track_stock' => 1, 'variant_product' => 0,
            'status' => 1, 'product_type' => null,
            'store_id' => $sibling->id,
            'created_by' => $this->store->created_by,
            'cover_image_path' => $foreign,
        ]);

        $this->assertSame(
            $foreign,
            $product->fresh()->cover_image_path,
            'premise: the row SAVED with a path in the other store\'s folder -- StoredPathPolicy '
            . 'admits a sibling store of the same owner, which is what makes this case reachable'
        );
        $this->assertSame(
            [],
            ImageVariants::existing($foreign, null),
            'a variant was written into store ' . self::STORE_ID . "'s folder for a product owned by store " . $sibling->id
        );

        /* The control: the same path, saved on a product of the store that OWNS the folder, does
         * get variants -- so "nothing was written" above is the confinement and not a broken
         * fixture or an unreadable original. */
        $this->product->cover_image_path = $foreign;
        $this->product->save();

        $this->assertSame(
            [160, 320, 480, 768],
            array_keys(ImageVariants::existing($foreign, self::STORE_ID)),
            'the owning store got no variants either, so this test measured nothing'
        );
    }

    #[Test]
    public function test_a_sizes_value_is_escaped_before_it_reaches_the_attribute(): void
    {
        /* `sizes` is a constant at every call site today -- `StorefrontPictureSourceTest` refuses a
         * literal -- but the helper is a public function and its contract is that what it returns
         * is one attribute. Without the escape, a value carrying a quote closes `sizes=` and opens
         * whatever follows as a new attribute on the <source>. */
        $source = storefront_webp_source(
            $this->product->cover_image_path,
            '100vw" onerror="x',
            self::STORE_ID
        );

        $this->assertNotSame('', $source, 'premise: this image has variants, or nothing is being escaped');
        $this->assertStringNotContainsString('onerror="x', $source, 'the hostile sizes value reached the attribute unescaped');
        $this->assertStringContainsString('&quot; onerror=&quot;x', $source);
        $this->assertSame(1, preg_match_all('/\bsizes="/', $source), 'the output is no longer one sizes attribute');
    }

    #[Test]
    public function test_another_stores_variants_are_not_offered(): void
    {
        /* The store id is not decoration. `storefront_webp_source()` passes it through to the
         * confinement, so a view rendering store B's page can never advertise store A's files --
         * and a mutant that drops the argument is caught here rather than in production. */
        $this->assertNotSame(
            '',
            storefront_webp_source($this->product->cover_image_path, StorefrontImageSizes::PRODUCT_CARD, self::STORE_ID),
            'premise: the owning store does get a <source>, or the negative below proves nothing'
        );

        $this->assertSame(
            '',
            storefront_webp_source($this->product->cover_image_path, StorefrontImageSizes::PRODUCT_CARD, self::STORE_ID + 1),
            'a path in store ' . self::STORE_ID . "'s folder was offered to store " . (self::STORE_ID + 1)
        );
    }

    #[Test]
    public function test_a_save_that_does_not_change_the_image_generates_nothing(): void
    {
        /* THE COST GUARANTEE OF THIS GENERATION POINT, and the one thing about it that could go
         * wrong quietly. `Product::saved` fires on every save -- including the stock decrement on
         * every checkout -- so without the `wasChanged()` guard every order would re-run up to five
         * `gd` resizes per line. That is invisible from the outside, because regeneration produces
         * the same files; it is only observable by taking the files away first. */
        foreach ((array) glob($this->folder . '/variants/*') as $file) {
            unlink($file);
        }

        $this->assertSame([], ImageVariants::existing($this->product->cover_image_path, self::STORE_ID), 'premise: the variants are gone');

        $this->product->product_stock = 6;
        $this->product->save();

        $this->assertTrue($this->product->wasChanged('product_stock'), 'premise: the save really did change something');
        $this->assertSame(
            [],
            ImageVariants::existing($this->product->cover_image_path, self::STORE_ID),
            'a save that did not touch the image path regenerated the variants anyway'
        );

        /* And the control: a save that DOES change the path generates them, so "nothing happened"
         * above is the guard and not a broken observer. */
        $this->product->cover_image_path = $this->writeOriginal('cover-two', 1000, 750);
        $this->product->save();

        $this->assertSame(
            [160, 320, 480, 768],
            array_keys(ImageVariants::existing($this->product->cover_image_path, self::STORE_ID)),
            'a changed cover image generated nothing, so the observer is not running at all'
        );
    }

    #[Test]
    public function test_a_variant_url_is_encoded_rather_than_interpolated(): void
    {
        /* `StoredPathPolicy` refuses neither `"` nor `<` on purpose -- real upload names carry odd
         * characters -- and the variant's DIRECTORY is the original's, unreduced. So a stored path
         * with a quote in a directory segment reaches the attribute, and an unescaped interpolation
         * would end the srcset attribute and start another. The product's own folder is used so the
         * confinement still admits it. */
        $hostile = 'uploads/' . self::STORE_ID . '/a"b onerror=x/photo.png';
        mkdir(dirname(base_path($hostile)), 0o755, true);

        try {
            $image = imagecreatetruecolor(1000, 750);
            imagepng($image, base_path($hostile));
            imagedestroy($image);

            $written = ImageVariants::generate($hostile, self::STORE_ID);

            $this->assertNotSame([], $written, 'premise: the hostile-but-confined path really does get variants, or nothing is being escaped');

            $source = storefront_webp_source($hostile, StorefrontImageSizes::PRODUCT_CARD, self::STORE_ID);

            $this->assertStringNotContainsString('"b onerror=x/', $source, 'the raw directory segment reached the attribute');
            $this->assertStringContainsString('a%22b%20onerror%3Dx/', $source, 'the hostile segment was not percent-encoded');

            /* And the attribute really is still one attribute: exactly one srcset= and one sizes=. */
            $this->assertSame(1, preg_match_all('/\bsrcset="/', $source));
            $this->assertSame(1, preg_match_all('/\bsizes="/', $source));

            /* AND EVERY CANDIDATE IS STILL ONE CANDIDATE. This is the half an HTML-escaping
             * assertion cannot see: `srcset` separates a URL from its width descriptor by
             * WHITESPACE and separates candidates by commas, so a space or a comma in the stored
             * directory splits one candidate into two -- malformed markup with nothing
             * HTML-special in it. */
            preg_match('/\bsrcset="([^"]*)"/', $source, $m);

            foreach (explode(',', $m[1]) as $candidate) {
                $this->assertCount(2, preg_split('/\s+/', trim($candidate)), 'a srcset candidate is not `<url> <width>w`: ' . $candidate);
            }
        } finally {
            foreach ((array) glob(dirname(base_path($hostile)) . '/variants/*') as $f) {
                @unlink($f);
            }
            @rmdir(dirname(base_path($hostile)) . '/variants');
            foreach ((array) glob(dirname(base_path($hostile)) . '/*') as $f) {
                @unlink($f);
            }
            @rmdir(dirname(base_path($hostile)));
        }
    }
}
