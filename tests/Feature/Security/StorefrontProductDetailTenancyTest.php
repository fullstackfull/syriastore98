<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Testimonial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * GAP-233 — `product-detail` AND `product-detail-guest` RETURN THE WHOLE FOREIGN PRODUCT ROW,
 * ITS IMAGE GALLERY, AND ITS REVIEWERS' NAMES AND E-MAIL ADDRESSES.
 * =============================================================================================
 *
 * The same family as GAP-131 and **strictly worse**: that one disclosed a single stock integer,
 * this one serialises the entire row with `->toArray()` and then adds an account e-mail address.
 *
 * Three unscoped reads per handler, measured:
 *
 *     Product::where('id', $id)->first()            -> `product_info`, the whole row
 *     ProductImage::where('product_id', $id)->get() -> `product_image`, the foreign gallery
 *     Testimonial::where('product_id', $id)->get()  -> `product_Review`, with `user_name`
 *                                                      and **`user_email`**
 *
 * WHOSE E-MAIL. `Testimonial::UserData()` is `hasOne(User::class, 'id', 'user_id')` and
 * `testimonials` has NO customer column (`user_id` was added by
 * 2024_03_19_104643_add_user_id_column_in_testimonials_table.php). So the address the handler
 * emits through that relation is a **users.email** -- another MERCHANT's or their staff's account
 * address. That is checked here rather than asserted: the fixture creates a real `User` row and
 * the test looks for ITS address, which only appears if the relation resolves against `users`.
 *
 * (The column itself holds ids from two tables depending on who wrote the row -- the storefront
 * writer in `product_rating()` puts a CUSTOMERS id in it, the admin-side writer a USERS id -- a
 * conflation recorded in that handler's own comment and deliberately not changed here. Either way
 * the relation THIS handler reads points at `users`, which is why a merchant's address comes out.
 * No line numbers: this slice moved several, and a stale anchor reads as a measurement.)
 *
 * None of the three models is in `config/tenancy.php`'s `scoped_models`, so no global scope
 * contains any of them -- `Product` carries `StoreScope` through `BelongsToStore` and it is INERT
 * because of that; `ProductImage` and `Testimonial` have no scope at all. The handler resolves
 * `$store` from the slug and uses it only for the cart count and the related-products list.
 *
 * ---------------------------------------------------------------------------------------------
 * AND THE `-guest` TWIN HAS NO STORE GUARD AT ALL, so an unknown slug is a 500 rather than a
 * refusal: `$store = getStore($slug)` with no `empty($store)` check, and `$store->id` is read
 * later (`Product::instruction_array($store->id)`). Every sibling in this controller checks it.
 * A 500 is also the shape that lets a regression test pass for the wrong reason, which is why it
 * has its own case here.
 *
 * ---------------------------------------------------------------------------------------------
 * THREE PRINCIPALS, because `custom.auth` admits a merchant's and a driver's token while
 * `auth('customers')` stays null, and this handler branches on `auth('customers')->user()` for its
 * cart count -- so the three are not interchangeable even though none of them should see store B.
 *
 * ---------------------------------------------------------------------------------------------
 * THE GALLERY FIXTURE'S PATH IS NOT ARBITRARY. `ProductImage::saving` runs
 * `StoredPathPolicy::productImage()` (P1-SEC-28), which refuses any `image_path` that is not a
 * relative path under `uploads/` INSIDE THE OWNING STORE'S OWN FOLDER. A bare file name is
 * refused with `ValidationException: The file path is not accepted.` -- measured: the first run of
 * this file failed four cases on exactly that, in the fixture rather than in the handler. So the
 * path is `uploads/<store id>/<name>`, and the needle is the file name, which is unique per store.
 */
class StorefrontProductDetailTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const FOREIGN_NAME = 'GAP233-FOREIGN-PRODUCT-NAME';

    private const FOREIGN_DETAIL = 'GAP233-FOREIGN-INTERNAL-DETAIL';

    private function productIn($store, string $name, string $detail): Product
    {
        return Product::forceCreate([
            'name' => $name,
            'slug' => 'gap233-' . uniqid(),
            'description' => 'x',
            'detail' => $detail,
            'product_stock' => 7,
            'track_stock' => 1,
            'variant_product' => 0,
            'stock_status' => 'in_stock',
            'trending' => 0,
            'status' => 1,
            'price' => 10,
            'sale_price' => 10,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);
    }

    /**
     * A gallery row and a review whose reviewer is a real `User` row.
     *
     * @return array<string,string> what the response must or must not contain, by what it is
     */
    private function decorate(Product $product, $store, string $tag): array
    {
        $image = 'gap233-' . $tag . '-gallery.webp';

        ProductImage::forceCreate([
            'product_id' => $product->id,
            /* `image_path`/`image_url`, NOT `image`; and under the store's own folder, or
               StoredPathPolicy refuses the save. */
            'image_path' => 'uploads/' . $store->id . '/' . $image,
            'image_url' => 'uploads/' . $store->id . '/' . $image,
            'store_id' => $store->id,
        ]);

        /*
         * THE REVIEWER IS A `User`, NOT A `Customer`, AND THAT IS THE WHOLE POINT OF THIS FIXTURE.
         * `type` is deliberately NOT set: it is irrelevant to what this route discloses. The
         * address is the finding.
         */
        $reviewer = \App\Models\User::factory()->create([
            'name' => 'GAP233 ' . $tag . ' Reviewer',
            'email' => 'gap233-' . $tag . '-reviewer@example.test',
        ]);

        Testimonial::forceCreate([
            'product_id' => $product->id,
            /* EXPLICITLY NULL. `testimonials.category_id` is `->default(0)->nullable()` with a
               foreign key onto `categories`, and there is no category with id 0 -- so omitting it
               takes the default and the insert is refused outright (measured: `SQLSTATE[23000] ...
               FOREIGN KEY constraint failed` on four cases of the first run of this file). The
               2026_10_23 migration that converts 0 to NULL is on `product_reviews`, a different
               table. The handler never reads this column. */
            'category_id' => null,
            'user_id' => $reviewer->id,
            'username' => 'GAP233 ' . $tag . ' Username',
            'title' => 'GAP233 ' . $tag . ' Title',
            'description' => 'GAP233 ' . $tag . ' Review Body',
            'rating_no' => 5,
            'status' => 1,
            'store_id' => $store->id,
        ]);

        return [
            'the foreign image gallery' => $image,
            'a reviewer\'s name' => $reviewer->name,
            'A MERCHANT USER\'S E-MAIL ADDRESS' => $reviewer->email,
            'the review body' => 'GAP233 ' . $tag . ' Review Body',
        ];
    }

    /** @return array<string,string> label => bearer token, all three tokenable classes */
    private function threeTokensFor($merchant, $store): array
    {
        $customer = Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => 'A',
            'email' => 'gap233-shopper-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('x'), 'store_id' => $store->id,
            'status' => 1, 'register_type' => 'email',
        ]);

        $driver = DeliveryBoy::forceCreate([
            'name' => 'Driver', 'email' => 'gap233-drv-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_by' => $merchant->id, 'store_id' => $store->id,
        ]);

        return [
            'customer' => $customer->createToken('t')->plainTextToken,
            'merchant' => $merchant->createToken('t')->plainTextToken,
            'driver' => $driver->createToken('t')->plainTextToken,
        ];
    }

    private function detail($store, string $path, $productId, string $bearer)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $bearer])
            ->postJson('/api/' . $store->slug . '/' . $path, ['id' => $productId]);
    }

    /** @return array<string,array<int,string>> the two routes, so neither twin can be fixed alone */
    public static function bothRoutes(): array
    {
        return [
            'authenticated' => ['product-detail'],
            'guest' => ['product-detail-guest'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bothRoutes')]
    public function test_no_token_holder_is_served_another_tenants_product(string $path): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $foreign = $this->productIn($storeB, self::FOREIGN_NAME, self::FOREIGN_DETAIL);
        $needles = $this->decorate($foreign, $storeB, 'foreign');

        /* PREMISES: the row really is store B's and really carries what the assertions look for. */
        $row = Product::withoutGlobalScopes()->find($foreign->id);
        $this->assertSame((int) $storeB->id, (int) $row->store_id, 'premise: the product is not store B\'s');
        $this->assertNotSame((int) $storeA->id, (int) $storeB->id, 'premise: one store is being used for both tenants');
        $this->assertSame(self::FOREIGN_NAME, (string) $row->name);
        $this->assertSame(self::FOREIGN_DETAIL, (string) $row->detail);
        $this->assertSame(1, (int) DB::table('testimonials')->where('product_id', $foreign->id)->count(),
            'premise: no review exists, so the e-mail assertion below is vacuous');
        $this->assertSame(1, (int) DB::table('product_images')->where('product_id', $foreign->id)->count(),
            'premise: no gallery row exists, so the image assertion below is vacuous');

        foreach ($this->threeTokensFor($merchantA, $storeA) as $label => $bearer) {
            $response = $this->detail($storeA, $path, $foreign->id, $bearer);

            $this->assertSame(200, $response->status(), $path . ' did not answer at all for ' . $label
                . ', so the assertions below would be vacuous');

            $body = (string) $response->getContent();

            foreach (array_merge([
                'the whole product row' => self::FOREIGN_NAME,
                'the product\'s internal detail field' => self::FOREIGN_DETAIL,
            ], $needles) as $what => $needle) {
                $this->assertStringNotContainsString($needle, $body, sprintf(
                    '%s disclosed %s of store B to a %s token of store A. None of Product, '
                    . 'ProductImage or Testimonial is in config/tenancy.php scoped_models, so the '
                    . 'handler\'s own predicate is the only control.',
                    $path, $what, $label
                ));
            }

            /* AND THE DIRECT QUESTION, not only the needles: the key is absent altogether. */
            $this->assertNull(data_get($response->json(), 'data.product_info'),
                $path . ' still built product_info out of a foreign row for a ' . $label . ' token');
            $this->assertEmpty(data_get($response->json(), 'data.product_image') ?? [],
                $path . ' still built product_image out of a foreign gallery for a ' . $label . ' token');
            $this->assertEmpty(data_get($response->json(), 'data.product_Review') ?? [],
                $path . ' still built product_Review out of foreign reviews for a ' . $label . ' token');
        }
    }

    /** CONTROL: the store's own product, gallery and review are still served, to all three. */
    #[\PHPUnit\Framework\Attributes\DataProvider('bothRoutes')]
    public function test_a_stores_own_product_is_still_served_in_full(string $path): void
    {
        [$merchantA, $storeA] = $this->makeTenant();

        $own = $this->productIn($storeA, 'GAP233-OWN-PRODUCT', 'GAP233-OWN-DETAIL');
        $needles = $this->decorate($own, $storeA, 'own');

        foreach ($this->threeTokensFor($merchantA, $storeA) as $label => $bearer) {
            $response = $this->detail($storeA, $path, $own->id, $bearer);

            $response->assertStatus(200);
            $body = (string) $response->getContent();

            $this->assertStringContainsString('GAP233-OWN-PRODUCT', $body,
                $path . ' refused a legitimate same-store product for a ' . $label
                . ' token, which is a regression and not a fix');
            $this->assertStringContainsString($needles['the foreign image gallery'], $body,
                $path . ' dropped the store\'s own image gallery for a ' . $label . ' token');
            $this->assertStringContainsString($needles['the review body'], $body,
                $path . ' dropped the store\'s own reviews for a ' . $label . ' token');
            $this->assertStringContainsString($needles['A MERCHANT USER\'S E-MAIL ADDRESS'], $body,
                $path . ' dropped the reviewer identity from its OWN reviews. Whether an account '
                . 'e-mail belongs in a storefront response at all is a separate product question '
                . '(see the report\'s dispositions), and narrowing it is NOT what this slice does: '
                . 'a tenancy fix that also silently changed the same-store payload would be '
                . 'indistinguishable from a regression.');

            $this->assertSame((int) $own->id, (int) data_get($response->json(), 'data.product_info.id'),
                $path . ' served a different product than the one asked for, to a ' . $label . ' token');
        }
    }

    /**
     * THE `-guest` TWIN HAD NO STORE GUARD, so an unknown slug was a 500 rather than a refusal.
     *
     * The authenticated twin is driven in the same case as the control, because it already has the
     * guard: without it, "not a 500" could be satisfied by a route that does not exist at all.
     */
    public function test_an_unknown_slug_is_refused_rather_than_crashing(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $own = $this->productIn($storeA, 'GAP233-OWN-PRODUCT', 'GAP233-OWN-DETAIL');

        $tokens = $this->threeTokensFor($merchantA, $storeA);

        foreach (['product-detail-guest', 'product-detail'] as $path) {
            $response = $this->withHeaders(['Authorization' => 'Bearer ' . $tokens['customer']])
                ->postJson('/api/no-such-store-slug-' . uniqid() . '/' . $path, ['id' => $own->id]);

            $this->assertNotSame(500, $response->status(), $path
                . ' read $store->id on null: product_detail_guest has no empty($store) check, '
                . 'unlike every sibling in this controller');
            /* HTTP 200 with `{status: 0}` is the refusal shape on this API -- `ApiResponser::
               error()` answers 200 unless a code is passed explicitly, and the shipped Flutter app
               force-clears its session on any 401, so introducing a new one here would be a
               user-visible regression. The envelope is what is asserted, not the status. */
            $this->assertSame(200, $response->status(),
                $path . ' answered an unknown slug with something other than the refusal envelope '
                . 'this API uses (status ' . $response->status() . ')');
            $this->assertSame(0, $response->json('status'),
                $path . ' answered an unknown slug with a success envelope');
            $this->assertStringNotContainsString('GAP233-OWN-PRODUCT', (string) $response->getContent(),
                $path . ' served store A\'s product through a slug that names no store');
        }
    }

    /** `-guest` is not public: `custom.auth` refuses a missing bearer before the handler runs. */
    public function test_both_routes_still_refuse_a_caller_with_no_token(): void
    {
        [, $storeA] = $this->makeTenant();

        foreach (['product-detail', 'product-detail-guest'] as $path) {
            /* Its own request each time: withHeaders() persists across requests inside one test
               method, so a probe that sent a token earlier inherits it. */
            $response = $this->postJson('/api/' . $storeA->slug . '/' . $path, ['id' => 1]);

            $this->assertSame(401, $response->status(), $path . ' is anonymous');
        }
    }
}
