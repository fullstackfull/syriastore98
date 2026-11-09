<?php

namespace Tests\Feature\ApiContract;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5-FLUTTER-PRICE — the server half of the product-price fix.
 * =============================================================================================
 *
 * THE DEFECT WAS IN THE APP AND THE REPAIR IS IN THE APP, but it rests entirely on one promise
 * this endpoint makes, and nothing here was asserting it. The Flutter product card used to build
 * its price from `sale_price`, which is the optional DISCOUNT — `->default(0)` in the products
 * migration, validated `nullable|numeric|min:0|lte:price` — so it was `0` for every product
 * nobody had discounted, and the card rendered an em dash where the merchant web printed the
 * price. The card now reads **`final_price`**.
 *
 * SO `final_price` HAS TO KEEP MEANING WHAT IT MEANS, and what it means here is subtle enough to
 * be worth pinning:
 *
 *   * `final_price` is NOT a column on `products`. It is an appended accessor
 *     (`Product::$appends`), and the accessor runs a FlashSale query per row.
 *   * `ProductList` deliberately strips that append and sets the field itself:
 *     `getRawOriginal('final_price') ?? getRawOriginal('price') ?? 0`. The first term is always
 *     null — there is no such column — so the field resolves to the product's own `price`.
 *   * `$product->price` is exactly what the merchant WEB list and grid print
 *     (`ProductDataTable.php:68`, `resources/views/product/list.blade.php:138`).
 *
 * That chain is why the two surfaces now agree, and every link in it is implicit. If someone
 * restores the append, adds a real `final_price` column, or changes the fallback, the app would
 * start showing a different number from the web again and nothing would say so. This says so.
 *
 * IT ALSO ASSERTS `price` AND `sale_price` ARE STILL SENT UNCHANGED. The repair was additive by
 * construction — no new field, no removed field, no changed calculation — and the owner's
 * authorisation was explicit that no public contract may break. A client reading either of the
 * older fields must keep getting what it got before.
 */
class ProductListPriceContractTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * Drives the real endpoint as a real merchant and returns the first row of its payload.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function firstProductRow(array $attributes): array
    {
        [$merchant, $store] = $this->makeTenant();

        // The endpoint serves `cost_price`, so it is permission-gated. A fixture merchant holds no
        // Laratrust role; without this the call would 403 for a reason orthogonal to the subject.
        $this->grantPermission(User::find($merchant->id), 'Manage Products');

        // `forceFill`, not `create`: `created_by` is NOT NULL on this table and is NOT in
        // Product::$fillable, so `create()` silently drops it and the insert fails on the
        // constraint. Measured, not guessed -- the first version of this fixture did exactly that.
        $product = new Product();
        $product->forceFill(array_merge([
            'name' => 'Damascene cotton shirt',
            'store_id' => $store->id,
            'product_type' => null,
            'product_stock' => 12,
            'track_stock' => 1,
            'status' => 1,
            'created_by' => $merchant->id,
        ], $attributes));
        $product->save();

        $token = User::find($merchant->id)->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/productlist?store_id=' . $store->id);

        $response->assertOk();

        $payload = $response->json();

        $this->assertSame(
            1,
            $payload['status'] ?? null,
            'productlist did not answer with status 1, so nothing below was measured.'
        );

        $rows = $payload['data']['data'] ?? [];

        $this->assertNotEmpty(
            $rows,
            'productlist returned no products, so the price contract was not exercised.'
        );

        return $rows[0];
    }

    /**
     * The promise the app's product card now depends on.
     */
    public function test_final_price_is_sent_and_equals_the_regular_price_when_undiscounted(): void
    {
        $row = $this->firstProductRow([
            'price' => 150,
            'sale_price' => 0,
        ]);

        $this->assertArrayHasKey(
            'final_price',
            $row,
            'productlist stopped sending `final_price`. The Flutter product card reads it, so '
            . 'every product would render an em dash again — see '
            . 'StoreSyria_app98/test/screens/product_price_parity_test.dart.'
        );

        $this->assertSame(
            '150.00',
            (string) $row['final_price'],
            '`final_price` no longer resolves to the product\'s own price. The merchant web '
            . 'prints `$product->price` on both its list and its grid, so the app reading this '
            . 'field is what keeps the two surfaces showing the same number.'
        );
    }

    /**
     * THE CASE THAT MATTERS MOST, because it is the one a careless repair gets wrong.
     *
     * A discounted product must STILL report the regular price here, because that is what the web
     * shows. Resolving `final_price` to the discount would make the two surfaces disagree again —
     * just differently, and more plausibly, which is worse.
     */
    public function test_a_discount_does_not_change_what_final_price_reports(): void
    {
        $row = $this->firstProductRow([
            'price' => 150,
            'sale_price' => 120,
        ]);

        $this->assertSame(
            '150.00',
            (string) $row['final_price'],
            '`final_price` now follows the discount. The merchant web prints the regular price '
            . 'even when a discount exists, so this puts web and app back into disagreement.'
        );

        $this->assertSame(
            '120.00',
            (string) $row['sale_price'],
            'the discount itself must still be sent, unchanged, for any consumer that reads it.'
        );
    }

    /**
     * The older fields are untouched. The repair added nothing and removed nothing.
     */
    public function test_price_and_sale_price_are_still_sent_in_their_existing_shape(): void
    {
        $row = $this->firstProductRow([
            'price' => 99.5,
            'sale_price' => 0,
        ]);

        foreach (['price', 'sale_price', 'final_price'] as $field) {
            $this->assertArrayHasKey(
                $field,
                $row,
                $field . ' is no longer sent by productlist, which is a breaking contract change '
                . 'for the shipped app.'
            );
        }

        // Two decimal places, as a string: the shape `number_format($value, 2, '.', '')` has
        // always produced, and the shape `formatPriceRaw` on the client parses.
        $this->assertSame('99.50', (string) $row['price']);
        $this->assertSame('0.00', (string) $row['sale_price']);
    }

    /**
     * PREMISE. `final_price` must not be a real column on `products`, because the whole fallback
     * above depends on `getRawOriginal('final_price')` being null.
     *
     * If a migration ever adds the column, the endpoint would start serving whatever that column
     * holds instead of the price — silently, and only on this one surface.
     */
    public function test_premise_final_price_is_an_accessor_and_not_a_products_column(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('products', 'final_price'),
            '`products.final_price` now exists as a column. `ProductList` resolves the field with '
            . '`getRawOriginal(\'final_price\') ?? getRawOriginal(\'price\')`, so the endpoint '
            . 'would begin serving the column instead of the price and the app would quietly '
            . 'disagree with the web again. Re-read that fallback before allowing this.'
        );

        $this->assertContains(
            'final_price',
            (new Product())->getAppends(),
            'final_price is no longer an appended accessor on Product, so the strip-the-append '
            . 'step in ProductList is operating on something else than it was written for.'
        );
    }
}
