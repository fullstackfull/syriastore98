<?php

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M0 — the storefront must never hand the browser `<img src="">`.
 *
 * MEASURED on `damascus-textiles/home` before this change: 83 `<img>` elements, 60 broken, and
 * every one of those sixty had an EMPTY src. Not a wrong path, not a 404 — an empty string, which
 * every browser draws as a broken-image icon.
 *
 * The dev fixture explains the COUNT (30 of 32 products had no uploaded picture); it does not
 * explain the BEHAVIOUR. `get_file()` takes a stored path and returns a URL, and given nothing it
 * correctly returns nothing — its own `catch` returns `''` too. Callers that care already guard
 * the INPUT; the storefront's product, category and brand images never got that guard. A real
 * merchant mid-upload saw the same page.
 *
 * After: 0 broken, 0 empty, on both tenants.
 *
 * THE FIX IS NOT A CHANGE TO `get_file()`, and that was checked rather than assumed: no caller
 * anywhere tests its RESULT for emptiness, so returning a placeholder from it would have broken
 * nothing — and would still have been wrong, because it serves avatars, invoices, logos and
 * e-mail attachments, and a product placeholder is the right answer for one of those.
 */
class StorefrontImageFallbackTest extends TestCase
{
    use RefreshDatabase;

    /* =================================================================================
     * The helper
     * ================================================================================= */

    /** @return array<string, array{0: mixed}> */
    public static function emptyish(): array
    {
        return [
            'empty string' => [''],
            'null' => [null],
            'whitespace' => ['   '],
            'not a string' => [[]],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('emptyish')]
    public function test_storefront_image_never_returns_an_empty_url(mixed $path): void
    {
        $url = storefront_image($path);

        $this->assertNotSame('', trim($url),
            'storefront_image() returned an empty string, which reaches the browser as src="".');
        $this->assertStringContainsString('product-placeholder', $url);
    }

    /**
     * `get_file()` returns '' from its own `catch` as well as for an empty argument, so the helper
     * re-checks the RESULT. A stored path that exists but cannot be resolved must still not reach
     * the browser as an empty src.
     */
    #[Test]
    public function test_a_path_that_cannot_be_resolved_still_yields_a_placeholder(): void
    {
        $url = storefront_image('uploads/definitely/not/here-p3.png');

        $this->assertNotSame('', trim($url));
    }

    #[Test]
    public function test_a_real_path_is_passed_through_unchanged(): void
    {
        $url = storefront_image('uploads/1/p2dev/swatch-a.png');

        $this->assertStringContainsString('uploads/1/p2dev/swatch-a.png', $url);
        $this->assertStringNotContainsString('placeholder', $url,
            'A product WITH a picture must show its own picture, not the placeholder.');
    }

    /** An avatar takes the avatar placeholder. A person is not a product. */
    #[Test]
    public function test_the_fallback_is_choosable_so_an_avatar_is_not_a_product(): void
    {
        $this->assertStringContainsString(
            'avatar-placeholder',
            storefront_image('', 'images/avatar-placeholder.svg')
        );
    }

    /* =================================================================================
     * The assets, because a fallback that cannot resolve is not a fallback
     * ================================================================================= */

    /**
     * Both placeholders are committed under `public/` on purpose. `.gitignore` carries
     * `/storage/*`, and `public/storage` is a TRACKED REGULAR FILE holding the vendor's build path
     * rather than a symlink — so anything under `/storage/…` is unreachable from a clone. The
     * Phase 2.5 avatar placeholder exists for the same reason.
     */
    #[Test]
    public function test_both_placeholder_assets_exist_and_are_not_under_storage(): void
    {
        foreach (['images/product-placeholder.png', 'images/avatar-placeholder.svg'] as $asset) {
            $this->assertFileExists(public_path($asset),
                "{$asset} is gone, so the fallback it backs resolves to nothing.");
            $this->assertStringNotContainsString('storage/', $asset,
                'A placeholder under /storage/ cannot resolve from a clone.');
        }
    }

    /* =================================================================================
     * The call sites
     * ================================================================================= */

    /**
     * Every MERCHANT-OWNED picture on the storefront — a product cover, a category image or icon,
     * a brand logo, a blog cover — goes through the helper. Theme-setting images (logo, favicon,
     * offer banners) are deliberately NOT converted: they carry their own configured defaults, and
     * replacing those with a product placeholder would override a merchant's branding.
     *
     * @return array<string, array{0: string}>
     */
    public static function storefrontViews(): array
    {
        $views = [];
        foreach (['stylique', 'techzonix', 'greentic'] as $theme) {
            $views["{$theme} product card"] = ["themes/{$theme}/views/components/product-card.blade.php"];
            $views["{$theme} blog card"] = ["themes/{$theme}/views/components/blog-card.blade.php"];
            $views["{$theme} home"] = ["themes/{$theme}/views/main_file.blade.php"];
        }
        $views['shared product detail'] = ['resources/views/front_end/pages/product-detail.blade.php'];
        $views['shared collection list'] = ['resources/views/front_end/pages/collection-list.blade.php'];

        return $views;
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('storefrontViews')]
    public function test_no_merchant_owned_picture_is_rendered_with_a_bare_get_file(string $view): void
    {
        $path = base_path($view);
        $this->assertFileExists($path);
        $blade = file_get_contents($path);

        // The expressions that are empty in real data. A bare get_file() on any of them is the
        // defect coming back.
        $bare = [
            'get_file($product->cover_image_path)',
            'get_file($products->cover_image_path)',
            'get_file($item->cover_image_path)',
            'get_file($blog->cover_image_path)',
            'get_file($category->image_path)',
            'get_file($category->icon_path)',
            'get_file($brand->logo)',
            'get_file($review->avatar)',
        ];

        foreach ($bare as $expr) {
            $this->assertStringNotContainsString($expr, $blade,
                "{$view} renders a merchant-owned picture with a bare {$expr}. When the merchant "
                . 'has not uploaded one that emits src="", which every browser draws as a broken '
                . 'image. Use storefront_image().');
        }
    }

    /**
     * THE TESTIMONIAL AVATARS, which were three different kinds of broken in three themes and are
     * worth pinning as a set: stylique had no fallback at all, techzonix fell back to `avatar.png`
     * — measured 404 — and greentic to a theme image. All three now take the avatar placeholder.
     */
    #[Test]
    public function test_every_theme_testimonial_avatar_has_a_resolvable_fallback(): void
    {
        foreach (['stylique', 'techzonix', 'greentic'] as $theme) {
            $blade = file_get_contents(base_path("themes/{$theme}/views/main_file.blade.php"));

            $this->assertStringContainsString(
                "storefront_image(\$review->avatar, 'images/avatar-placeholder.svg')",
                str_replace('$testimonial', '$review', $blade),
                "{$theme}'s testimonial avatar does not use a resolvable fallback."
            );
            $this->assertStringNotContainsString("get_file(\$testimonial->avatar ?? 'avatar.png')", $blade,
                "{$theme} is back to the /avatar.png fallback, which 404s.");
        }
    }
}
