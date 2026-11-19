<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tax;
use App\Models\TaxMethod;
use App\Models\TaxOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Security\DetectsActiveMarkup;
use Tests\Support\Security\MinimalRows;

/**
 * P1-SEC-80, second round -- the review of the first round found the same defect in the places that are not DataTable cells: HTML built in a controller and handed to the browser as a
 * JSON `html` string that a script writes into the page, and values echoed into a page's own script.
 * ============================================================================================================================================
 *
 * The till (`/pos`) is the clearest case. `ProductController::searchProducts`, `searchProductsSku` and `addToCart` and `CategoryController::getProductCategories` concatenate a product's
 * name and slug, a category's name and a tax method's name into the tiles, the category buttons and the cart row, and `pos/index.blade.php` writes them with `.html()` on page load -- no
 * click. The writers are staff members who may create a product, a category or a tax method (their validators are `required` and nothing more), and, through the open registration, any
 * visitor for their own store; the XSS middleware is a four-string denylist that passes `<img src=x onerror=...>` unchanged.
 *
 * Each case drives the real route as the person who reads, with the payload as the stored name, and asks the DOM of the returned fragment (DetectsActiveMarkup). The pages (`/finance`,
 * `/order/create`) are asked a different question, because a script legitimately contains data: is the payload ever placed where the BROWSER would parse it as markup or as code?
 */
class PanelFragmentStoredXssTest extends SecurityTestCase
{
    use DetectsActiveMarkup;
    use MinimalRows;
    use RefreshDatabase;

    private const PAYLOAD = '<img src=x onerror=XSS-1>';

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    /** @return array{0:\App\Models\User,1:\App\Models\Store} */
    private function till(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create Pos');

        return [$merchant, $store];
    }

    private function productNamed(\App\Models\Store $store, string $name, bool $withVariants = false, int $taxId = 0): Product
    {
        return $this->minimalRow(Product::class, [
            'store_id' => $store->id, 'name' => $name, 'slug' => $name, 'price' => 10, 'status' => 1, 'variant_product' => $withVariants ? 1 : 0, 'product_type' => null, 'product_stock' => 5, 'tax_id' => $taxId,
        ]);
    }

    /** A line of the till's session cart, as `addToCart` stores it. */
    private function cartLine(Product $product): array
    {
        return [
            'product_id' => $product->id, 'id' => $product->id, 'name' => $product->name, 'image' => '', 'quantity' => 1, 'originalquantity' => 5, 'orignal_price' => 10.00,
            'per_product_discount_price' => 0, 'discount_price' => 0, 'final_price' => 10.00, 'tax' => 0, 'total_orignal_price' => 10.00, 'variant_id' => 0, 'variant_name' => null, 'return' => 0,
        ];
    }

    private function assertMessageIsInert(?string $message, string $door): void
    {
        $this->assertStringContainsString('XSS-1', (string) $message, 'premise: ' . $door . ' names the product in the "added to cart" message');
        $this->assertSame([], $this->activeMarkupIn((string) $message), $door . ' carries the product name as markup');
    }

    private function assertFragmentIsInert(?string $html, string $door): void
    {
        $this->assertNotNull($html, 'premise: ' . $door . ' answered with a fragment');
        $this->assertStringContainsString('XSS-1', (string) $html, 'premise: ' . $door . ' prints the stored string in some form (raw before the fix, escaped after it)');
        $this->assertSame([], $this->activeMarkupIn((string) $html), $door . ' printed a stored name as markup');
    }

    public function test_a_product_name_and_slug_are_not_printed_raw_on_the_pos_product_tiles(): void
    {
        [$merchant, $store] = $this->till();
        $this->productNamed($store, self::PAYLOAD);

        $response = $this->actingAs($merchant)->withHeaders(self::AJAX)
            ->getJson('/search-products?' . http_build_query(['session_key' => 'pos_' . $store->id, 'cat_id' => '0', 'search' => '']));

        $response->assertOk();
        $this->assertFragmentIsInert($response->json('html'), 'the POS product tiles');
    }

    public function test_a_product_name_is_not_printed_raw_on_the_pos_sku_search_tiles(): void
    {
        [$merchant, $store] = $this->till();
        $this->productNamed($store, self::PAYLOAD);

        $response = $this->actingAs($merchant)->withHeaders(self::AJAX)
            ->getJson('/search-products-sku?' . http_build_query(['session_key' => 'pos_' . $store->id, 'cat_id' => '0', 'search' => '']));

        $response->assertOk();
        $this->assertFragmentIsInert($response->json('html'), 'the POS SKU search tiles');
    }

    public function test_a_category_name_is_not_printed_raw_on_the_pos_category_buttons(): void
    {
        [$merchant, $store] = $this->till();
        $this->minimalRow(Category::class, ['store_id' => $store->id, 'name' => self::PAYLOAD, 'slug' => 'cat-x']);

        $response = $this->actingAs($merchant)->withHeaders(self::AJAX)->getJson('/product-categories');

        $response->assertOk();
        $this->assertFragmentIsInert($response->json('html'), 'the POS category buttons');
    }

    public function test_a_product_name_is_not_printed_raw_in_the_pos_cart_row(): void
    {
        [$merchant, $store] = $this->till();
        $product = $this->productNamed($store, self::PAYLOAD);

        $response = $this->actingAs($merchant)->withHeaders(self::AJAX)->getJson('/addToCart/' . $product->id . '/pos_' . $store->id);

        $response->assertOk();
        $this->assertFragmentIsInert($response->json('carthtml'), 'the POS cart row');
        // the success message is shown by the page's toast, which writes it with innerHTML
        $this->assertSame([], $this->activeMarkupIn((string) $response->json('success')), 'the "added to cart" message carries the product name as markup');
    }

    public function test_a_product_name_is_not_printed_raw_in_the_testimonial_product_select(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Testimonial');
        $category = $this->minimalRow(Category::class, ['store_id' => $store->id, 'name' => 'Plain', 'slug' => 'plain']);
        $this->minimalRow(Product::class, ['store_id' => $store->id, 'name' => self::PAYLOAD, 'category_id' => $category->id, 'product_type' => null]);

        $response = $this->actingAs($merchant)->withHeaders(self::AJAX)->postJson('/get-product', ['id' => $category->id, 'val' => '']);

        $response->assertOk();
        $this->assertFragmentIsInert($response->json('html'), 'the testimonial product select');
    }

    /**
     * Both tile builders are written twice: a product WITHOUT variants shows a price, a product WITH variants shows "In Variant" -- four copies of the same name and slug prints, and the
     * first two cases above reach only the first copy of each builder.
     *
     * @return array<string,array{0:string}>
     */
    public static function tileBuilders(): array
    {
        return ['product tiles' => ['/search-products'], 'SKU search tiles' => ['/search-products-sku']];
    }

    #[DataProvider('tileBuilders')]
    public function test_a_product_with_variants_is_not_printed_raw_on_the_pos_tiles(string $route): void
    {
        [$merchant, $store] = $this->till();
        $this->productNamed($store, self::PAYLOAD, true);

        $response = $this->actingAs($merchant)->withHeaders(self::AJAX)
            ->getJson($route . '?' . http_build_query(['session_key' => 'pos_' . $store->id, 'cat_id' => '0', 'search' => '']));

        $response->assertOk();
        $this->assertStringContainsString('In Variant', (string) $response->json('html'), 'premise: the product took the variant branch of the tile builder');
        $this->assertFragmentIsInert($response->json('html'), $route . ' (a product with variants)');
    }

    /** The cart row's tax cell lists the product's tax methods by name; a tax method is created by a staff member who may manage taxes. Badges are only built under exclusive pricing. */
    public function test_a_tax_method_name_is_not_printed_raw_in_the_pos_cart_row(): void
    {
        [$merchant, $store] = $this->till();
        $this->minimalRow(TaxOption::class, ['store_id' => $store->id, 'name' => 'price_type', 'value' => 'exclusive']);
        $this->minimalRow(TaxOption::class, ['store_id' => $store->id, 'name' => 'shop_price', 'value' => 'excluding']);
        $tax = $this->minimalRow(Tax::class, ['store_id' => $store->id, 'name' => 'VAT']);
        $this->minimalRow(TaxMethod::class, ['store_id' => $store->id, 'tax_id' => $tax->id, 'name' => self::PAYLOAD, 'tax_rate' => 5, 'priority' => 1]);
        $product = $this->productNamed($store, 'Plain', false, $tax->id);

        $response = $this->actingAs($merchant)->withHeaders(self::AJAX)->getJson('/addToCart/' . $product->id . '/pos_' . $store->id);

        $response->assertOk();
        $this->assertFragmentIsInert($response->json('carthtml'), 'the POS cart row tax badge');
    }

    /** `addToCart` answers from one of three places: an empty cart (above), a cart that already holds the line, and a cart that holds other lines. Each prints the name in its own message. */
    public function test_a_product_name_is_not_printed_raw_in_the_message_when_the_line_is_already_in_the_cart(): void
    {
        [$merchant, $store] = $this->till();
        $product = $this->productNamed($store, self::PAYLOAD);
        $key = 'pos_' . $store->id;

        $response = $this->actingAs($merchant)->withSession([$key => [$product->id => $this->cartLine($product)]])->withHeaders(self::AJAX)->getJson('/addToCart/' . $product->id . '/' . $key);

        $response->assertOk();
        $this->assertMessageIsInert($response->json('success'), 'the "line already in the cart" path');
    }

    public function test_a_product_name_is_not_printed_raw_in_the_message_or_row_when_the_cart_holds_other_lines(): void
    {
        [$merchant, $store] = $this->till();
        $other = $this->productNamed($store, 'Other');
        $product = $this->productNamed($store, self::PAYLOAD);
        $key = 'pos_' . $store->id;

        $response = $this->actingAs($merchant)->withSession([$key => [$other->id => $this->cartLine($other)]])->withHeaders(self::AJAX)->getJson('/addToCart/' . $product->id . '/' . $key);

        $response->assertOk();
        $this->assertMessageIsInert($response->json('success'), 'the "cart holds other lines" path');
        $this->assertFragmentIsInert($response->json('carthtml'), 'the POS cart row (a cart that holds other lines)');
    }

    // ------------------------------------------------------------------ pages: is the payload ever placed where the browser parses it as markup or as code?

    /**
     * `order/createOrder.blade.php` writes the product options with Blade `{{ }}` INSIDE a JavaScript template literal. Blade's escaping is HTML escaping: it leaves the backtick and `${`
     * alone, so a product named with a backtick-and-`${` runs as code with no tag in it. The oracle's "a script of the payload" is the right question: the name must not be in a script at all.
     */
    public function test_a_product_name_is_not_written_into_the_create_order_script(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');
        $this->minimalRow(Product::class, ['store_id' => $store->id, 'name' => '`${XSS-2}`', 'status' => 1, 'price' => 10, 'product_type' => null]);

        $response = $this->actingAs($merchant)->get('/order/create');

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('XSS-2', $html, 'premise: the page lists the product by name somewhere (in the markup, escaped, or in the script)');
        $this->assertSame([], $this->activeMarkupIn($html), 'a product name was written into the create-order page\'s script');
    }

    /**
     * `/finance?search_field=description&search_value=...` selects the field, so the page's own script fires its `change` handler on load and writes the control with `innerHTML`, interpolating
     * the REQUEST's `search_value` into `value="..."` unescaped: a link a stranger sends is script in the merchant's session. The page now hands the script a second, HTML-escaped copy for
     * every HTML context and keeps the raw one for comparisons.
     */
    public function test_the_finance_search_value_is_not_interpolated_into_markup_by_the_pages_script(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'View Finance Dashboard');

        $response = $this->actingAs($merchant)->get('/finance?' . http_build_query(['search_field' => 'description', 'search_value' => '"><img src=x onerror=XSS-3>']));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('searchInputContainer.innerHTML', $html, 'premise: the page carries the script that writes the search control');
        $this->assertDoesNotMatchRegularExpression('/value="\$\{currentSearchValue\b/', $html, 'the script writes the raw request value into an HTML attribute');
        $this->assertStringContainsString('currentSearchValueHtml', $html, 'the script is handed an HTML-escaped copy for markup contexts');
        $this->assertStringContainsString('&quot;&gt;&lt;img src=x onerror=XSS-3&gt;', $html, 'the escaped copy really is escaped');
    }
}
