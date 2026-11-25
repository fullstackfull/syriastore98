<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\Product;
use App\Models\Testimonial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * `random_review` RETURNS EVERY TENANT'S REVIEWS.
 * =============================================================================================
 *
 *     $limit  = !empty($request->limit) ? $request->limit : 10;
 *     $review = Testimonial::limit($limit)->get();          // ApiController, random_review
 *
 * No store predicate at all, and `Testimonial` is NOT in `config/tenancy.php`'s `scoped_models`
 * and carries no scope of its own -- so the table is returned as it stands. The rows are whole
 * model rows, so every column goes out: `title`, `description`, `username`, `rating_no`,
 * `product_id`, `user_id` and the `store_id` that says whose they are.
 *
 * `$limit` IS CALLER-CONTROLLED AND UNCAPPED, which turns a sample into a dump: `limit = 100000`
 * returns the whole `testimonials` table for every store on the platform. That is asserted here
 * rather than left as prose.
 *
 * THE SIBLING IS THE FIX. `landingpage()`, forty lines below in the same file, reads the same
 * table for the same purpose and already writes `->where('store_id', $store->id)`. So the
 * predicate introduces no new behaviour class -- it makes the two agree. There is no
 * store-verified parent to gate on here (the handler reads reviews with no product context), so
 * this is the case where the row's own `store_id` IS the only evidence available, which is exactly
 * when the column is the right predicate.
 *
 * ---------------------------------------------------------------------------------------------
 * WHAT THIS TEST DELIBERATELY DOES NOT ASSERT: a `status` predicate. The reads have none, so a
 * review a merchant has deactivated is returned along with the active ones. It is NOT added here
 * and the reason is recorded in the slice report: the storefront's own writer hard-codes
 * `status = 1`, `landingpage()` has no status predicate either, and whether a store's own
 * deactivated review disappears from its own storefront is a moderation decision that changes
 * behaviour for legitimate same-store traffic. Mixing it into a tenancy fix would make the fix and
 * the regression indistinguishable.
 */
class StorefrontRandomReviewTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function productIn($store, string $name): Product
    {
        return Product::forceCreate([
            'name' => $name,
            'slug' => 'gaprev-' . uniqid(),
            'description' => 'x',
            'product_stock' => 1,
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

    /** @return array{0:Testimonial,1:string} the row and the distinctive needle in it */
    private function reviewIn($store, string $tag, int $status = 1): array
    {
        $product = $this->productIn($store, 'GAPREV-' . $tag . '-PRODUCT');

        $reviewer = \App\Models\User::factory()->create([
            'name' => 'GAPREV ' . $tag . ' Reviewer',
            'email' => 'gaprev-' . $tag . '-' . uniqid() . '@example.test',
        ]);

        $review = Testimonial::forceCreate([
            'product_id' => $product->id,
            /* NULL, not the 0 default: `testimonials.category_id` has a foreign key onto
               `categories` and there is no category 0, so the default is refused outright. */
            'category_id' => null,
            'user_id' => $reviewer->id,
            'username' => 'GAPREV-' . $tag . '-USERNAME',
            'title' => 'GAPREV-' . $tag . '-TITLE',
            'description' => 'GAPREV-' . $tag . '-BODY',
            'rating_no' => 4,
            'status' => $status,
            'store_id' => $store->id,
        ]);

        return [$review, 'GAPREV-' . $tag . '-BODY'];
    }

    /** @return array<string,string> label => bearer token, all three tokenable classes */
    private function threeTokensFor($merchant, $store): array
    {
        $customer = Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => 'A',
            'email' => 'gaprev-shopper-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('x'), 'store_id' => $store->id,
            'status' => 1, 'register_type' => 'email',
        ]);

        $driver = DeliveryBoy::forceCreate([
            'name' => 'Driver', 'email' => 'gaprev-drv-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_by' => $merchant->id, 'store_id' => $store->id,
        ]);

        return [
            'customer' => $customer->createToken('t')->plainTextToken,
            'merchant' => $merchant->createToken('t')->plainTextToken,
            'driver' => $driver->createToken('t')->plainTextToken,
        ];
    }

    private function randomReview($store, array $payload, string $bearer)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $bearer])
            ->postJson('/api/' . $store->slug . '/random_review', $payload);
    }

    public function test_no_token_holder_is_served_another_tenants_reviews(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        [$ownReview, $ownNeedle] = $this->reviewIn($storeA, 'own');
        [$foreignReview, $foreignNeedle] = $this->reviewIn($storeB, 'foreign');

        /* PREMISES: both rows exist and the foreign one really is store B's. */
        $this->assertSame((int) $storeB->id, (int) DB::table('testimonials')->where('id', $foreignReview->id)->value('store_id'),
            'premise: the foreign review is not store B\'s');
        $this->assertSame((int) $storeA->id, (int) DB::table('testimonials')->where('id', $ownReview->id)->value('store_id'));
        $this->assertSame(2, (int) DB::table('testimonials')->count(), 'premise: the fixture did not create two reviews');

        foreach ($this->threeTokensFor($merchantA, $storeA) as $label => $bearer) {
            $response = $this->randomReview($storeA, [], $bearer);

            $this->assertSame(200, $response->status(), 'random_review did not answer for a ' . $label . ' token');
            $body = (string) $response->getContent();

            $this->assertStringNotContainsString($foreignNeedle, $body, sprintf(
                'random_review handed a %s token of store A store B\'s review. Testimonial is NOT '
                . 'in config/tenancy.php scoped_models and carries no scope, so the handler\'s own '
                . 'predicate is the only control -- and landingpage() forty lines below already '
                . 'writes it for the same table.',
                $label
            ));
            $this->assertStringNotContainsString('GAPREV-foreign-USERNAME', $body,
                'random_review disclosed a foreign reviewer\'s name to a ' . $label . ' token');

            /* CONTROL IN THE SAME CASE: store A's own review is still served, so the assertion
               above cannot be satisfied by an endpoint that returns nothing. */
            $this->assertStringContainsString($ownNeedle, $body,
                'random_review stopped serving store A its OWN review for a ' . $label
                . ' token, which is a regression and not a fix');
        }
    }

    /**
     * AND `limit` DOES NOT TURN IT INTO A PLATFORM-WIDE DUMP. The parameter is uncapped, so the
     * leak is not limited to ten rows: this drives twelve foreign reviews and asks for a hundred
     * thousand.
     */
    public function test_a_large_limit_does_not_dump_every_stores_reviews(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->reviewIn($storeA, 'own');

        $foreignNeedles = [];
        for ($i = 0; $i < 12; $i++) {
            [, $foreignNeedles[]] = $this->reviewIn($storeB, 'foreign' . $i);
        }

        $this->assertSame(13, (int) DB::table('testimonials')->count(), 'premise: the fixture is not the size this case needs');

        $tokens = $this->threeTokensFor($merchantA, $storeA);
        $response = $this->randomReview($storeA, ['limit' => 100000], $tokens['customer']);

        $body = (string) $response->getContent();

        foreach ($foreignNeedles as $needle) {
            $this->assertStringNotContainsString($needle, $body,
                'random_review returned the whole testimonials table for an uncapped limit, so '
                . 'the leak is every review on the platform and not a ten-row sample');
        }

        $this->assertCount(1, (array) $response->json('data'),
            'random_review answered with more rows than store A owns');
    }

    /** A malformed `limit` must not be a 500 either -- it reaches `->limit()` unparsed. */
    public function test_a_malformed_limit_does_not_crash_the_endpoint(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->reviewIn($storeA, 'own');

        $tokens = $this->threeTokensFor($merchantA, $storeA);

        foreach (['abc', '-5', '1.5'] as $limit) {
            $response = $this->randomReview($storeA, ['limit' => $limit], $tokens['customer']);

            $this->assertNotSame(500, $response->status(), 'random_review crashed on limit=' . $limit);
        }
    }

    public function test_the_route_still_refuses_a_caller_with_no_token(): void
    {
        [, $storeA] = $this->makeTenant();

        $response = $this->postJson('/api/' . $storeA->slug . '/random_review', []);

        $this->assertSame(401, $response->status(), 'random_review is anonymous');
    }
}
