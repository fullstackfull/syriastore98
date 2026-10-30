<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\VariantRemovalConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * F205 — THE BULK REMOVAL'S CONSENT IS VERIFIABLE, NOT A BOOLEAN.
 * =================================================================================================
 *
 * The owner's decision, 2026-10-30. Before it, the one door that deletes MANY variants at once was
 * the one whose consent was a plain `$request->boolean()` — satisfied by `'1'`, `'true'`, `'yes'`
 * or `'on'`. The two per-variant doors already required the variant NAME echoed back, so the
 * signal was verifiable on the doors that delete one row and unverifiable on the door that deletes
 * the rest.
 *
 * THE BINDINGS, AND WHAT EACH ONE STOPS — one case per limb, because a token that binds four of
 * five things reads exactly like one that binds all five until the fifth is attacked.
 */
final class VariantRemovalConsentTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:Store,2:Product,3:\Illuminate\Support\Collection} */
    private function arrange(): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();
        $merchant = User::find($merchant->id);
        $this->grantPermission($merchant, 'Edit Products');

        $product = Product::forceCreate([
            'name' => 'F205 Scarf',
            'slug' => 'f205-' . uniqid(),
            'description' => 'x',
            'price' => 100,
            'track_stock' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 25,
            'status' => 1,
            'variant_product' => 1,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ])->fresh();

        foreach ([['S-Red', 10, 5], ['M-Red', 20, 6], ['L-Red', 30, 7]] as $i => [$name, $price, $stock]) {
            ProductVariant::forceCreate([
                'product_id' => $product->id,
                'variant' => $name,
                'variation_price' => $price,
                'price' => $price,
                'stock' => $stock,
                'sku' => 'F205-' . $i,
                'variation_option' => '',
                'store_id' => $store->id,
            ]);
        }

        $removed = ProductVariant::withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->whereIn('variant', ['M-Red', 'L-Red'])
            ->get(['id', 'variant', 'sku', 'variation_price', 'price', 'stock']);

        return [$merchant, $store, $product, $removed];
    }

    // =============================================================================================
    // THE SIGNAL IS NOT FORGEABLE
    // =============================================================================================

    /** The whole point: `confirm=1` is not consent. */
    public function test_a_truthy_value_is_not_a_consent(): void
    {
        [$merchant, , $product, $removed] = $this->arrange();

        foreach (['1', 'true', 'yes', 'on', 'TRUE', '0', '', 'null'] as $guess) {
            $this->assertFalse(
                VariantRemovalConsent::verify($guess, $merchant, $product, $removed),
                'the value ' . var_export($guess, true) . ' was accepted as consent, so the signal '
                . 'records that a parameter was set rather than that anyone saw what they were deleting'
            );
        }
    }

    public function test_a_random_token_of_the_right_shape_is_not_a_consent(): void
    {
        [$merchant, , $product, $removed] = $this->arrange();

        $this->assertFalse(
            VariantRemovalConsent::verify(bin2hex(random_bytes(16)), $merchant, $product, $removed),
            'a token the server never issued was accepted'
        );
    }

    public function test_a_consent_the_server_issued_is_accepted(): void
    {
        [$merchant, , $product, $removed] = $this->arrange();

        $issued = VariantRemovalConsent::issue($merchant, $product, $removed);

        $this->assertSame(VariantRemovalConsent::FIELD, $issued['confirm_with'],
            'the refusal advertises a field name the door does not read');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $issued['confirm_value'],
            'the token is not lowercase hex, so the `xss` middleware can mangle it in transit — it '
            . 'strips `alert`, `javascript`, `&lt;` and `&gt;` from every request string');
        $this->assertGreaterThan(0, $issued['expires_in'], 'the consent advertises no time bound');

        $this->assertTrue(
            VariantRemovalConsent::verify($issued['confirm_value'], $merchant, $product, $removed),
            'the server would not accept the consent it had just issued'
        );
    }

    // =============================================================================================
    // ONE TOKEN, ONE REMOVAL
    // =============================================================================================

    public function test_a_consent_is_consumed_by_the_removal_it_authorises(): void
    {
        [$merchant, , $product, $removed] = $this->arrange();

        $token = VariantRemovalConsent::issue($merchant, $product, $removed)['confirm_value'];

        $this->assertTrue(VariantRemovalConsent::verify($token, $merchant, $product, $removed));
        $this->assertFalse(
            VariantRemovalConsent::verify($token, $merchant, $product, $removed),
            'the same consent authorised a second removal — single use is what stops a captured '
            . 'token being replayed against a set the merchant never reviewed'
        );
    }

    /**
     * And a MISMATCH does not consume it. A merchant whose set changed under them should be able to
     * read the fresh refusal, not also lose the token they were holding.
     */
    public function test_a_mismatch_does_not_burn_the_consent(): void
    {
        [$merchant, , $product, $removed] = $this->arrange();

        $token = VariantRemovalConsent::issue($merchant, $product, $removed)['confirm_value'];
        $other = $removed->take(1);

        $this->assertFalse(VariantRemovalConsent::verify($token, $merchant, $product, $other),
            'a different set was accepted');
        $this->assertTrue(VariantRemovalConsent::verify($token, $merchant, $product, $removed),
            'the failed attempt consumed the consent, so an honest retry is refused too');
    }

    // =============================================================================================
    // THE BINDINGS — one case per limb
    // =============================================================================================

    public function test_a_consent_does_not_cross_to_another_user(): void
    {
        [$merchant, $store, $product, $removed] = $this->arrange();

        $colleague = $this->makeStaff($merchant);
        $colleague->current_store = $store->id;
        $colleague->save();
        $this->grantPermission($colleague, 'Edit Products');
        $colleague = User::find($colleague->id);

        $token = VariantRemovalConsent::issue($merchant, $product, $removed)['confirm_value'];

        $this->assertFalse(VariantRemovalConsent::verify($token, $colleague, $product, $removed),
            'one employee\'s consent authorised another employee\'s removal');
    }

    public function test_a_consent_does_not_cross_to_another_product(): void
    {
        [$merchant, $store, $product, $removed] = $this->arrange();

        $other = Product::forceCreate([
            'name' => 'F205 Other',
            'slug' => 'f205o-' . uniqid(),
            'description' => 'x',
            'price' => 100,
            'track_stock' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 1,
            'status' => 1,
            'variant_product' => 1,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ])->fresh();

        $token = VariantRemovalConsent::issue($merchant, $product, $removed)['confirm_value'];

        $this->assertFalse(VariantRemovalConsent::verify($token, $merchant, $other, $removed),
            'a consent for one product authorised a removal on another');
    }

    public function test_a_consent_does_not_cross_to_another_store(): void
    {
        [$merchant, , $product, $removed] = $this->arrange();

        $token = VariantRemovalConsent::issue($merchant, $product, $removed)['confirm_value'];

        /* The same product id, claimed to be in a different store — the tenant limb on its own. */
        $drifted = clone $product;
        $drifted->store_id = (int) $product->store_id + 1000;

        $this->assertFalse(VariantRemovalConsent::verify($token, $merchant, $drifted, $removed),
            'the consent did not bind the store, so it crosses a tenant boundary when ids coincide');
    }

    public function test_a_consent_does_not_authorise_a_larger_set(): void
    {
        [$merchant, , $product, $removed] = $this->arrange();

        $token = VariantRemovalConsent::issue($merchant, $product, $removed)['confirm_value'];

        $bigger = ProductVariant::withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->get(['id', 'variant', 'sku', 'variation_price', 'price', 'stock']);

        $this->assertCount(3, $bigger, 'premise: the larger set really is larger');
        $this->assertFalse(VariantRemovalConsent::verify($token, $merchant, $product, $bigger),
            'a consent for two variants authorised deleting three — which is the exact shape of '
            . '"confirm one and delete the rest"');
    }

    /**
     * THE STATE BINDING. This is the limb `ss_last_seen_at` cannot provide, and that was measured:
     * with `products.updated_at` parked in 2020, a variant save, a variant delete and the bulk
     * delete each left it untouched — there is no `$touches` and no observer, so the optimistic
     * lock is blind to the very set it is being asked to protect.
     */
    public function test_a_consent_stops_being_valid_when_the_reviewed_data_changes(): void
    {
        [$merchant, , $product, $removed] = $this->arrange();

        $token = VariantRemovalConsent::issue($merchant, $product, $removed)['confirm_value'];

        /* Eloquent, not a raw write: ProductVariant is Cachable and a raw update would leave the
           re-read serving the pre-write row — a trap measured three times in this repository. */
        $one = ProductVariant::withoutGlobalScopes()->find($removed->first()->id);
        $one->price = (int) $one->price + 1;
        $one->timestamps = false;
        $one->save();

        $fresh = ProductVariant::withoutGlobalScopes()
            ->whereIn('id', $removed->pluck('id'))
            ->get(['id', 'variant', 'sku', 'variation_price', 'price', 'stock']);

        $this->assertFalse(VariantRemovalConsent::verify($token, $merchant, $product, $fresh),
            'the price changed after the merchant reviewed it and the consent still applied — so '
            . 'what they agreed to delete is not what would be deleted');
    }

    public function test_a_consent_expires(): void
    {
        [$merchant, , $product, $removed] = $this->arrange();

        $token = VariantRemovalConsent::issue($merchant, $product, $removed)['confirm_value'];

        $this->travel((int) config('consent.ttl_minutes') + 1)->minutes();

        $this->assertFalse(VariantRemovalConsent::verify($token, $merchant, $product, $removed),
            'a consent left in a stale browser tab still authorised a deletion');
    }

    // =============================================================================================
    // THE STABLE ORDER
    // =============================================================================================

    /**
     * `variantsBeingRemoved()` has no `ORDER BY`, so the row order is engine-defined. An unsorted
     * binding would be rejected at random on MySQL/MariaDB — a merchant refused on a correct retry,
     * intermittently, with no diagnosable cause. This is the reason the owner's decision says
     * "in a stable order".
     */
    public function test_the_binding_does_not_depend_on_the_row_order(): void
    {
        [$merchant, , $product, $removed] = $this->arrange();

        $token = VariantRemovalConsent::issue($merchant, $product, $removed)['confirm_value'];

        $this->assertTrue(
            VariantRemovalConsent::verify($token, $merchant, $product, $removed->reverse()->values()),
            'the same set in a different order was rejected, so the binding is order-dependent and '
            . 'will fail at random against a database that does not promise row order'
        );
    }

    public function test_the_variant_ids_are_sorted_whatever_order_they_arrive_in(): void
    {
        [, , , $removed] = $this->arrange();

        $forward = VariantRemovalConsent::variantIds($removed);
        $backward = VariantRemovalConsent::variantIds($removed->reverse()->values());

        $this->assertSame($forward, $backward, 'the id list is not stable under reordering');
        $this->assertSame($forward, array_values(array_unique($forward)), 'the id list carries duplicates');

        $sorted = $forward;
        sort($sorted, SORT_NUMERIC);
        $this->assertSame($sorted, $forward, 'the id list is not sorted');
    }

    // =============================================================================================
    // WHERE THE RECORD LIVES
    // =============================================================================================

    /**
     * NOT THE DEFAULT STORE, and this is the measured reason: `laravel-model-caching` flushes on
     * every model save, and because `DatabaseStore` is not taggable that flush is `Cache::flush()`
     * over the WHOLE default store. `Product` and `ProductVariant` are both `Cachable`, so a
     * consent in the default store would be erased by any model save anywhere in the installation
     * between issue and verify.
     */
    public function test_the_consent_does_not_live_in_the_default_cache_store(): void
    {
        $configured = config('consent.store');

        $this->assertNotSame(config('cache.default'), $configured,
            'the consent record shares the default cache store, which laravel-model-caching '
            . 'flushes wholesale on any model save — the merchant would be refused on a correct '
            . 'retry with no diagnosable cause');
        $this->assertIsString($configured, 'no consent store is configured, so the record would '
            . 'fall back to the default store this test exists to keep it out of');
    }

    /** Surviving a default-store flush is the property, so it is asserted rather than assumed. */
    public function test_a_consent_survives_a_flush_of_the_default_cache_store(): void
    {
        [$merchant, , $product, $removed] = $this->arrange();

        $token = VariantRemovalConsent::issue($merchant, $product, $removed)['confirm_value'];

        \Illuminate\Support\Facades\Cache::store(config('cache.default'))->flush();

        $this->assertTrue(VariantRemovalConsent::verify($token, $merchant, $product, $removed),
            'a flush of the default cache store erased the consent — which is what any `Cachable` '
            . 'model save does in production');
    }
}
