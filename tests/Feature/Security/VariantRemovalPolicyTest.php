<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StripsSourceComments;

/**
 * F195 (COMPLETION) — ONE DELETION POLICY, ON EVERY FIRST-PARTY DOOR.
 * =============================================================================================
 *
 * D-28c keeps the F195 fix and requires it to be finished: *"one authoritative deletion policy
 * across every reachable path — via a shared service or policy. No duplicated reference-check
 * implementation, and no calling a web controller from an API controller as a workaround."*
 *
 * THREE FIRST-PARTY DOORS REACH `ProductVariant::delete()`:
 *
 *   PUT    product/{id}                  ProductController::update()
 *   DELETE product-attribute-delete/{id} ProductController::product_attribute_delete()
 *   DELETE api/admin/deletevariant       Api\DashboardController::DeleteVariant()
 *
 * The first two carry the D-20 reference block. **The third did not** — its own comment said so,
 * because the checks lived in a `private` method of a web controller and the three ways to reach
 * them from an API controller were all worse than recording the gap. That is what this slice
 * closes, by moving them into `App\Services\VariantRemovalPolicy` which all three call.
 *
 * AND THE PERMISSION QUESTION IS NOT THE SAME ON ALL THREE, which is the owner's second condition.
 * `ProductController::lacksProductPermission()` is operator-first and then `isAbleTo()` — right
 * about the operator, wrong about the store, because `allPermissions()` has no store predicate
 * (Laratrust's team feature is disabled) and so unions every role the user holds across every
 * store. `MerchantPermissions::allowsAny($user, $names, $storeId)` is the corrected read and is
 * what the policy uses. The cases below drive the difference directly: **staff with
 * `Edit Products` in ANOTHER store** is the one that separates them.
 *
 * OWNERSHIP FOLLOWS THE PARENT PRODUCT, not `ProductVariant.store_id`. The trash door already did
 * that; the API door narrowed on the variant's own column, which its comment recorded as *"not the
 * authority on who owns it"*.
 *
 * WHAT THIS FILE DOES NOT COVER, deliberately: `packages/workdo/AuctionProduct`'s
 * `AuctionProductController::update()`, which has TWO unguarded variant deletes of its own
 * (`whereNotIn('variant', $sku_array)` and `whereNotIn('variant', $com)`) against the SAME
 * `App\Models\ProductVariant` rows, behind `auth` + `web` + `PlanModuleCheck:AuctionProduct`. It is
 * vendored and must not be edited. **So it is false to say every variant-deletion path is closed**,
 * and the ledger says so at that severity instead.
 */
class VariantRemovalPolicyTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    /** @return array{0:User,1:Store,2:Product,3:ProductVariant} */
    private function arrange(): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();
        $merchant = User::find($merchant->id);

        $this->grantPermission($merchant, 'Edit Products');

        $product = Product::forceCreate([
            'name' => 'F195 Scarf',
            'slug' => 'f195c-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'track_stock' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 7,
            'status' => 1,
            'variant_product' => 1,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);

        $variant = ProductVariant::forceCreate([
            'product_id' => $product->id,
            'variant' => 'S-Red',
            'variation_price' => 101,
            'price' => 201,
            'stock' => 31,
            'sku' => 'F195C-0001',
            'variation_option' => '',
            'store_id' => $store->id,
        ]);

        return [$merchant, $store, $product, $variant->fresh()];
    }

    private function staffIn(User $owner, Store $store, ?string $permission = null): User
    {
        $staff = $this->makeStaff($owner);
        $staff->current_store = $store->id;
        $staff->save();

        if ($permission !== null) {
            $this->grantPermission(User::find($staff->id), $permission);
        }

        return User::find($staff->id);
    }

    /** A shopper, whose token `custom.auth` logs into the `customers` guard. */
    private function makeCustomerPrincipal(int $storeId): \App\Models\Customer
    {
        return \App\Models\Customer::forceCreate([
            'first_name' => 'Tok',
            'last_name' => 'Cust',
            'email' => 'f195c-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('secret123'),
            'store_id' => $storeId,
            'status' => 1,
            'register_type' => 'email',
        ]);
    }

    /** A driver, whose token `custom.auth` logs into the `deliveryboy` guard. */
    private function makeDeliveryBoyPrincipal(int $storeId): \App\Models\DeliveryBoy
    {
        $store = Store::withoutGlobalScopes()->whereKey($storeId)->first();

        return \App\Models\DeliveryBoy::forceCreate([
            'name' => 'Driver ' . uniqid(),
            'email' => 'f195d-' . uniqid() . '@example.test',
            'password' => bcrypt('x'),
            'contact' => '0000000',
            'created_by' => $store->created_by,
            'store_id' => $storeId,
        ]);
    }

    /**
     * The API door, with a REAL bearer token — `custom.auth` resolves the tokenable, not actingAs().
     *
     * F184: `$consent` is the verifiable explicit-consent echo. The default is the variant's own
     * name — the value a client can only supply by having read the row — because most cases here
     * assert what a PERMITTED caller may do, and a permitted caller confirms. Pass `null` to drive
     * the unconfirmed call, and a wrong string to drive a blind guess.
     */
    private function api(User $user, $storeId, $variantId, $consent = '__echo__', array $extra = [])
    {
        if ($consent === '__echo__') {
            $row = ProductVariant::withoutGlobalScopes()->find($variantId);
            $consent = $row ? $row->variant : null;
        }

        $payload = ['store_id' => $storeId, 'variant_id' => $variantId];

        if ($consent !== null) {
            $payload['confirm_variant'] = $consent;
        }

        /* `$extra` lets a test send back exactly the key/value pair a refusal advertised, rather
           than the pair the helper would have chosen — which is the only way to prove the
           advertised signal is actionable and not just a string that matches the test. */
        $payload = array_merge($payload, $extra);

        return $this->withHeader(
            'Authorization',
            'Bearer ' . $user->createToken('auth_token')->plainTextToken
        )->deleteJson('/api/admin/deletevariant', $payload);
    }

    /** The trash door, a session route. `$consent` as above, under the key `ss_confirm_variant`. */
    private function trash(User $user, $variantId, $consent = '__echo__')
    {
        if ($consent === '__echo__') {
            $row = ProductVariant::withoutGlobalScopes()->find($variantId);
            $consent = $row ? $row->variant : null;
        }

        return $this->actingAs($user)->delete(
            route('product.attribute.delete', $variantId),
            $consent === null ? [] : ['ss_confirm_variant' => $consent]
        );
    }

    /** `ProductVariant` carries no StoreScope, but read without scopes anyway — the project's rule. */
    private function stillExists($id): bool
    {
        return ProductVariant::withoutGlobalScopes()->whereKey($id)->exists();
    }

    /**
     * `ApiResponser::error()` answers **HTTP 200** with `{status: 0, message: 'fail', data: {...}}`,
     * so the top-level `message` is the literal "fail" for every refusal and asserting it proves
     * nothing. The real text is `data.message`, and `status` is what separates refusal from
     * success. A 401 is never used here on purpose: the shipped Flutter app forces a logout on any
     * 401, so a new one on a path a client calls is a user-visible regression.
     */
    private function assertApiRefused($response, string $why): void
    {
        $this->assertSame(200, $response->getStatusCode(),
            $why . ' — the refusal must stay inside the envelope the clients read, not become a'
            . ' status code they treat as a session loss');
        $this->assertSame(0, (int) $response->json('status'),
            $why . ' — status 0 means refused: ' . json_encode($response->json()));
    }

    private function assertApiAllowed($response, string $why): void
    {
        $this->assertSame(1, (int) $response->json('status'),
            $why . ': ' . json_encode($response->json()));
    }

    // =============================================================================================
    // THE DEFECT: the API door had no reference check at all
    // =============================================================================================

    /** A shopper's cart line. */
    public function test_the_api_door_refuses_a_variant_a_cart_references(): void
    {
        [$merchant, $store, $product, $variant] = $this->arrange();

        \DB::table('carts')->insert([
            'customer_id' => 1,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'qty' => 1,
            'price' => 10,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->api($merchant, $store->id, $variant->id);

        $this->assertApiRefused($response,
            'the API door deleted a variant sitting in a shopper cart — the D-20 block the Save and'
            . ' trash paths both apply');
        $this->assertTrue($this->stillExists($variant->id), 'the row is gone, so the refusal was cosmetic');
    }

    /** A wishlist entry. */
    public function test_the_api_door_refuses_a_variant_a_wishlist_references(): void
    {
        [$merchant, $store, $product, $variant] = $this->arrange();

        \DB::table('wishlists')->insert([
            'customer_id' => 1,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->api($merchant, $store->id, $variant->id);

        $this->assertApiRefused($response, 'the API door deleted a wishlisted variant');
        $this->assertTrue($this->stillExists($variant->id), 'the row is gone');
    }

    /**
     * THE HARD BLOCK — an order naming the product at all.
     *
     * The storefront's order shape writes the basket as json with `product_id` on every line and
     * no `variant_id`, so *"no order used this variant"* is unprovable while *"no order named this
     * product"* is provable. It refuses unconditionally, and the owner's §4.3 is explicit that a
     * merchant's confirmation does not turn an unsafe operation into a safe one.
     */
    public function test_the_api_door_refuses_when_an_order_names_the_product(): void
    {
        [$merchant, $store, $product, $variant] = $this->arrange();

        \DB::table('orders')->insert([
            'product_order_id' => 'F195C-' . uniqid(),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_id' => $product->id,
            'delivery_id' => 0,
            'delivered_status' => 0,
            'return_status' => 0,
            'reward_points' => 0,
            'order_date' => now(),
            'payment_status' => 'Paid',
            'final_price' => 100,
            'store_id' => $store->id,
            'product_json' => json_encode([[
                'product_id' => $product->id,
                'name' => 'F195 Scarf',
                'qty' => 1,
                'price' => 100,
                'final_price' => 100,
                'total_orignal_price' => 100,
            ]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->api($merchant, $store->id, $variant->id);

        $this->assertApiRefused($response,
            'the API door deleted a variant of a product that appears in order history, which no'
            . ' confirmation may carry past');
        $this->assertTrue($this->stillExists($variant->id), 'the row is gone');
    }

    /** And a reference-free variant is still deletable, or the endpoint is merely broken. */
    public function test_the_api_door_still_deletes_a_reference_free_variant(): void
    {
        [$merchant, $store, , $variant] = $this->arrange();

        $response = $this->api($merchant, $store->id, $variant->id);

        $this->assertApiAllowed($response, 'a reference-free variant was refused, so the guard is a lockout');
        $this->assertFalse($this->stillExists($variant->id), 'the variant was reported deleted and is still there');
    }

    // =============================================================================================
    // F184 — THE CONSENT SIGNAL IS VERIFIED, NOT ASSUMED FROM THE CLICK
    // =============================================================================================

    /**
     * BOTH PER-VARIANT DOORS REFUSE AN UNCONFIRMED DELETE.
     *
     * The owner's F184 condition: *"the trash click itself is not the confirmation and must not
     * start the delete"*, and for the API path *"use a verifiable explicit-consent signal rather
     * than permitting a plain API call."* Before this, both doors passed a hardcoded `true` for
     * `$confirmed` — D-20's reading was that a dedicated per-variant endpoint is itself the
     * deliberate act. So a client-side dialog alone would have been unverifiable: a hand-posted
     * DELETE still deleted, which is the exact shape of the F195 bypass.
     *
     * @dataProvider perVariantDoors
     */
    #[DataProvider('perVariantDoors')]
    public function test_an_unconfirmed_delete_is_refused_on_both_per_variant_doors(string $door): void
    {
        [$merchant, $store, , $variant] = $this->arrange();

        $response = $door === 'api'
            ? $this->api($merchant, $store->id, $variant->id, null)
            : $this->trash($merchant, $variant->id, null);

        if ($door === 'api') {
            $this->assertApiRefused($response, 'the API door deleted a variant with no consent signal');
            $this->assertStringContainsString('S-Red', (string) $response->json('data.message'),
                'the refusal must NAME the variant, so a second call can be an informed one: '
                . json_encode($response->json()));
        } else {
            $this->assertSame(409, $response->getStatusCode(),
                'the trash door deleted a variant on a bare click with no consent signal. Body: '
                . substr((string) $response->getContent(), 0, 200));
        }

        $this->assertTrue($this->stillExists($variant->id), 'the row is gone');
    }

    /**
     * AND A BLIND GUESS IS NOT CONSENT — which is what makes the signal verifiable rather than
     * decorative. A bare boolean would have been satisfied by anything that could set a parameter.
     *
     * @dataProvider perVariantDoors
     */
    #[DataProvider('perVariantDoors')]
    public function test_a_wrong_consent_value_is_refused_on_both_per_variant_doors(string $door): void
    {
        [$merchant, $store, , $variant] = $this->arrange();

        foreach (['1', 'true', 'yes', 'S-Blue', 'S-Red '] as $guess) {
            $response = $door === 'api'
                ? $this->api($merchant, $store->id, $variant->id, $guess)
                : $this->trash($merchant, $variant->id, $guess);

            /* 'S-Red ' — the right name with a trailing space — is the one that must be ACCEPTED,
               because the comparison trims both sides: a form field can pick up whitespace and
               refusing that would be a lockout rather than a control. Everything else is a guess. */
            if ($guess === 'S-Red ') {
                $this->assertTrue(
                    $door === 'api'
                        ? (int) $response->json('status') === 1
                        : $response->getStatusCode() < 300,
                    'the consent comparison must trim: a name with surrounding whitespace is the'
                    . ' same name, and refusing it would be a lockout. Body: '
                    . substr((string) $response->getContent(), 0, 200)
                );
                continue;
            }

            $this->assertTrue(
                $door === 'api'
                    ? (int) $response->json('status') === 0
                    : $response->getStatusCode() >= 400,
                "the consent value '{$guess}' was accepted as confirmation, so the signal records"
                . ' that a parameter was set rather than that anyone saw what they were deleting'
            );
            $this->assertTrue($this->stillExists($variant->id), "the row is gone after '{$guess}'");
        }
    }

    /**
     * AND A CORRECT CONSENT STILL CANNOT CARRY A DELETE PAST A HARD BLOCK, which is the owner's
     * condition that no confirmation may override one. It holds by construction:
     * `VariantRemovalPolicy::refuse()` applies every reference check before it looks at
     * `$confirmed`.
     *
     * @dataProvider perVariantDoors
     */
    #[DataProvider('perVariantDoors')]
    public function test_a_correct_consent_does_not_override_a_hard_block(string $door): void
    {
        [$merchant, $store, $product, $variant] = $this->arrange();

        \App\Models\Order::forceCreate([
            'product_order_id' => 'F184-' . uniqid(),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_id' => $product->id,
            'delivery_id' => 0,
            'delivered_status' => 0,
            'return_status' => 0,
            'reward_points' => 0,
            'order_date' => now(),
            'payment_status' => 'Paid',
            'final_price' => 100,
            'store_id' => $store->id,
            'product_json' => json_encode([['product_id' => $product->id, 'qty' => 1]]),
        ]);

        $response = $door === 'api'
            ? $this->api($merchant, $store->id, $variant->id)
            : $this->trash($merchant, $variant->id);

        $this->assertTrue(
            $door === 'api'
                ? (int) $response->json('status') === 0
                : $response->getStatusCode() === 409,
            'a CORRECT consent token carried a delete past the unverifiable-order-history block.'
            . ' Confirmation does not make an unsafe operation safe. Body: '
            . substr((string) $response->getContent(), 0, 200)
        );
        $this->assertTrue($this->stillExists($variant->id), 'the row is gone');
    }

    /** @return array<string,array{0:string}> */
    public static function perVariantDoors(): array
    {
        return ['the API door' => ['api'], 'the trash door' => ['trash']];
    }

    /**
     * NOR MAY THE `xss` MIDDLEWARE'S REWRITING MAKE A VARIANT UNDELETABLE.
     *
     * Found by the same review pass, and it is the bigger version of the `TrimStrings` defect.
     * `App\Http\Middleware\XSS::handle()` rewrites **every** request string before the handler
     * runs — `htmlspecialchars_decode()`, then `str_replace(['&lt;', '&gt;', 'javascript',
     * 'alert'], '', …)` — and `product.attribute.delete` sits inside the
     * `['auth','xss','setlocate','verified']` group. So the echo and the stored name are not in the
     * same string space: a variant named `alert-Red` arrives as `-Red`, and no value the merchant
     * can type will ever equal the stored name.
     *
     * Such a name is reachable without any attack: `POST api/admin/updatevariant` carries no `xss`
     * middleware, so a rename through the Flutter app stores it verbatim. And a variant named
     * exactly `alert` normalises to the empty string, so it needs the `#<id>` fallback too — the
     * name genuinely cannot be transmitted through the pipeline, and the merchant must be shown
     * something they *can* send.
     *
     * One normaliser, applied to both sides, so the comparison is symmetric whichever middleware
     * ran on the way in — the API door has no `xss`, the web door does, and both must behave.
     *
     * @dataProvider perVariantDoors
     */
    #[DataProvider('perVariantDoors')]
    public function test_the_xss_middlewares_rewriting_does_not_make_a_variant_undeletable(string $door): void
    {
        foreach (['alert-Red', 'javascript-Blue', 'A&lt;B', 'alert'] as $stored) {
            [$merchant, $store, , $variant] = $this->arrange();

            $variant->variant = $stored;
            $variant->timestamps = false;
            $variant->save();
            $variant = $variant->fresh();

            /* Exactly what the trash button renders into `data-variant` and the dialog displays. */
            $token = \App\Services\VariantRemovalPolicy::consentToken($variant);
            $this->assertNotSame('', $token,
                'a variant named ' . json_encode($stored) . ' was given nothing to echo');

            $response = $door === 'api'
                ? $this->api($merchant, $store->id, $variant->id, $token)
                : $this->trash($merchant, $variant->id, $token);

            if ($door === 'api') {
                $this->assertApiAllowed($response, 'a variant named ' . json_encode($stored)
                    . ' is undeletable through the API door: ' . json_encode($response->json()));
            } else {
                $this->assertSame(200, $response->getStatusCode(),
                    'a variant named ' . json_encode($stored) . ' is undeletable through the trash '
                    . 'door: ' . substr((string) $response->getContent(), 0, 240));
            }

            $this->assertFalse($this->stillExists($variant->id),
                'the token was accepted for ' . json_encode($stored) . ' but the variant survived');
        }
    }

    /**
     * AND THE ROW THE API DOOR ACTS ON MUST BE THE ROW THE CONSENT NAMED.
     *
     * `'variant_id' => 'required'` accepts an ARRAY, and `whereKey([a, b])` compiles to
     * `whereIn(...)` — so `->first()` resolves the LOWEST matching id rather than refusing, and the
     * row acted on is chosen by caller-supplied input across the whole `product_variants` table.
     * Review measured both halves: a merchant sending `variant_id=[v1,v2]` with the consent for
     * `v2` got a refusal naming `v1`; and sending `[X, <own id>]` with `X` lower distinguishes
     * "a row with id X exists in some tenant" from "it does not" by the two different bodies —
     * a platform-wide existence oracle for a merchant with `Edit Products` in one of their own
     * stores.
     *
     * Pre-existing rather than introduced here, but it is on a door this change is rewriting, and
     * it is the *"a predicate built from caller-supplied input is not a tenant check"* shape. The
     * consent signal is meaningless if the id can select a different row than the one confirmed.
     */
    public function test_the_api_door_refuses_an_array_of_variant_ids(): void
    {
        [$merchant, $store, , $first] = $this->arrange();

        $second = ProductVariant::forceCreate([
            'product_id' => $first->product_id,
            'variant' => 'M-Blue',
            'variation_price' => 55,
            'price' => 66,
            'stock' => 7,
            'sku' => 'F195C-0002',
            'variation_option' => '',
            'store_id' => $store->id,
        ])->fresh();

        /* THE CONSENT MATCHES THE LOWEST ID, WHICH IS THE ONE `whereIn(...)->first()` RESOLVES.
           A first version of this test sent the consent for the HIGHER id and passed without the
           fix — not because the array was refused but because the consent did not match the row
           that got resolved. That is the shape §5 forbids: a test that passes for the wrong
           reason. With the consent matching the resolved row, nothing but refusing the array
           itself can keep the row alive. */
        $response = $this->api($merchant, $store->id, null, null, [
            'variant_id' => [$first->id, $second->id],
            'confirm_variant' => $first->variant,
        ]);

        $this->assertApiRefused($response,
            'an array of variant ids was accepted, so the row acted on is chosen by caller input '
            . 'rather than by the id the consent named: ' . json_encode($response->json()));

        $this->assertTrue($this->stillExists($first->id),
            'the lowest id in the array was deleted, so `whereKey([...])` picked the row');
        $this->assertTrue($this->stillExists($second->id), 'the other id was deleted');

        /* And the refusal must be about the IDENTIFIER, not a consent refusal that happens to
           look the same from outside. */
        $this->assertStringContainsString('variant', strtolower((string) $response->json('data.message')),
            'the refusal did not name the offending field: ' . json_encode($response->json()));
        $this->assertNull($response->json('data.ss_variant_removal'),
            'the array was refused by the removal policy rather than by validation, which means it '
            . 'reached the policy at all: ' . json_encode($response->json()));

        /* The scalar form still works, so this is a narrowing and not a lockout. */
        $ok = $this->api($merchant, $store->id, $first->id, $first->variant);
        $this->assertApiAllowed($ok, 'validating the id broke the ordinary scalar call: '
            . json_encode($ok->json()));
        $this->assertFalse($this->stillExists($first->id), 'the scalar call did not delete');
    }

    /**
     * AN INVISIBLE EDGE CHARACTER IN THE STORED NAME MUST NOT MAKE A VARIANT UNDELETABLE.
     *
     * Found by independent review of F184, and it is a permanent lockout rather than a nuisance.
     * Laravel's global `TrimStrings` middleware runs `Str::trim()`, whose charlist is Unicode `\s`
     * **plus** `Str::INVISIBLE_CHARACTERS` — NBSP, ZWSP, BOM, the ideographic space, and
     * **RLM/ALM/ALM-isolate**. PHP's `trim()` strips only `" \t\n\r\0\x0B"`. So the consent echo
     * arrives already stripped while the stored side was compared with `trim()`, and the two could
     * never be equal: measured, `"Rouge\u{00A0}"` arrives as `"Rouge"` and was compared against
     * `"Rouge\u{00A0}"`.
     *
     * RLM is the one that makes this more than theoretical in THIS product. The app is bilingual
     * Arabic/English, and a name imported from an external catalogue
     * (`ShopifyProductController::…` writes the title verbatim, outside any request, so no
     * middleware trims it) can easily carry a leading RLM. The merchant would see the right name
     * in the dialog, confirm, and be refused for ever with *"To proceed, confirm the removal
     * explicitly"* — which reads as a broken server, not as a consent requirement.
     *
     * @dataProvider perVariantDoors
     */
    #[DataProvider('perVariantDoors')]
    public function test_an_invisible_edge_character_does_not_make_a_variant_undeletable(string $door): void
    {
        foreach (["Rouge\u{00A0}", "\u{200F}أحمر", "Bleu\u{200B}", "Vert\u{FEFF}"] as $stored) {
            [$merchant, $store, , $variant] = $this->arrange();

            /* Eloquent, not DB::table(): ProductVariant is Cachable, and a raw write would leave
               route-model binding serving the pre-write row — this project's standing trap. */
            $variant->variant = $stored;
            $variant->timestamps = false;
            $variant->save();
            $variant = $variant->fresh();

            /* What the browser puts in `data-variant`, and what the API refusal advertises. */
            $token = \App\Services\VariantRemovalPolicy::consentToken($variant);

            $response = $door === 'api'
                ? $this->api($merchant, $store->id, $variant->id, $token)
                : $this->trash($merchant, $variant->id, $token);

            if ($door === 'api') {
                $this->assertApiAllowed($response,
                    'a variant whose stored name carries ' . json_encode($stored) . ' could not be '
                    . 'confirmed, so it is undeletable: ' . json_encode($response->json()));
            } else {
                $this->assertSame(200, $response->getStatusCode(),
                    'a variant whose stored name carries ' . json_encode($stored) . ' could not be '
                    . 'confirmed through the trash door: ' . substr((string) $response->getContent(), 0, 200));
            }

            $this->assertFalse($this->stillExists($variant->id),
                'the consent was accepted for ' . json_encode($stored) . ' but the variant survived');
        }
    }

    /**
     * AND NEITHER MAY AN EMPTY STORED NAME — WHICH IS A REGRESSION F184 ITSELF INTRODUCED.
     *
     * `product_variants.variant` is NOT NULL with no default, and F199's measured outcome was a
     * product whose variants were replaced by a single row named `''`. Before F184 the trash door
     * passed a hardcoded `true` for `$confirmed` and such a row could be deleted. With the consent
     * gate, `trim($sent) !== ''` refuses an empty echo unconditionally — and
     * `ConvertEmptyStringsToNull` turns the echo into `null` before the handler even sees it — so
     * a row that WAS deletable became permanently undeletable, with a dialog that named nothing.
     *
     * The token is therefore the stored name when there is one and `#<id>` when there is not: still
     * verifiable (the merchant is shown exactly what they must echo), never empty, and one
     * implementation shared by the doors and the view.
     *
     * @dataProvider perVariantDoors
     */
    #[DataProvider('perVariantDoors')]
    public function test_a_variant_with_no_stored_name_can_still_be_confirmed(string $door): void
    {
        [$merchant, $store, , $variant] = $this->arrange();

        $variant->variant = '';
        $variant->timestamps = false;
        $variant->save();
        $variant = $variant->fresh();

        $token = \App\Services\VariantRemovalPolicy::consentToken($variant);
        $this->assertSame('#' . $variant->id, $token,
            'a nameless variant must still have something to echo, or it cannot be confirmed');

        /* The echo must still be REQUIRED: the fallback token is not a free pass. */
        $unconfirmed = $door === 'api'
            ? $this->api($merchant, $store->id, $variant->id, null)
            : $this->trash($merchant, $variant->id, null);

        if ($door === 'api') {
            $this->assertApiRefused($unconfirmed, 'a nameless variant was deleted with no consent');
            $this->assertSame($token, $unconfirmed->json('data.ss_variant_removal.confirm_value'),
                'the refusal did not tell the client what to echo for a nameless variant: '
                . json_encode($unconfirmed->json('data.ss_variant_removal')));
        } else {
            $this->assertSame(409, $unconfirmed->getStatusCode(),
                'a nameless variant was deleted with no consent through the trash door');
        }
        $this->assertTrue($this->stillExists($variant->id), 'the nameless row is gone');

        $response = $door === 'api'
            ? $this->api($merchant, $store->id, $variant->id, $token)
            : $this->trash($merchant, $variant->id, $token);

        if ($door === 'api') {
            $this->assertApiAllowed($response, 'a nameless variant is undeletable: '
                . json_encode($response->json()));
        } else {
            $this->assertSame(200, $response->getStatusCode(),
                'a nameless variant is undeletable through the trash door: '
                . substr((string) $response->getContent(), 0, 200));
        }

        $this->assertFalse($this->stillExists($variant->id),
            'the fallback token was accepted but the variant survived');
    }

    /**
     * THE REFUSAL MUST NAME THE KEY THE DOOR ACTUALLY READS, AND THE VALUE IT ACTUALLY WANTS.
     *
     * Found by independent review of F184, inside F184's own change. `refuse()` hardcoded
     * `'confirm_with' => 'ss_confirm_variant_removal'` — the BULK save door's key, whose value is
     * a boolean (`$request->boolean()`). The two per-variant doors read different keys and want a
     * different value: the trash door reads `ss_confirm_variant` and the API door reads
     * `confirm_variant`, and both compare it to the stored variant NAME.
     *
     * The API door forwards `ss_variant_removal` to the client verbatim, so the signal the owner
     * asked to be "verifiable" was telling an API client to retry with a key that door does not
     * read, carrying a value it would reject anyway. A client that followed it would be refused
     * for ever and have no way to discover why — which is worse than no hint, because it reads as
     * a server bug rather than as a consent requirement.
     *
     * §4.4 requires the API path's consent signal to be documented and compatibility-tested. This
     * is that test: it drives the real door and asserts the advertised key and value are the ones
     * that, when sent back, actually work.
     */
    public function test_the_api_refusal_names_the_consent_key_that_door_reads(): void
    {
        [$merchant, $store, , $variant] = $this->arrange();

        $refused = $this->api($merchant, $store->id, $variant->id, null);
        $this->assertApiRefused($refused, 'the API door deleted a variant with no consent signal');

        $advertised = $refused->json('data.ss_variant_removal');
        $this->assertIsArray($advertised, 'the API refusal carried no ss_variant_removal to act on');

        $this->assertSame('confirm_variant', $advertised['confirm_with'] ?? null,
            'the API refusal advertised a consent key this door does not read, so a client '
            . 'following it is refused for ever: ' . json_encode($advertised));

        $this->assertSame($variant->variant, $advertised['confirm_value'] ?? null,
            'the API refusal did not say WHAT to send, so the key alone is unusable: '
            . json_encode($advertised));

        /* The advertised pair, sent back verbatim, must actually work. Without this the two
           assertions above only prove the strings match the ones I happened to write. */
        $second = $this->api($merchant, $store->id, $variant->id, null, [
            $advertised['confirm_with'] => $advertised['confirm_value'],
        ]);

        $this->assertApiAllowed($second,
            'the consent pair the refusal advertised was itself refused, so the signal is not '
            . 'actionable: ' . json_encode($second->json()));
        $this->assertFalse($this->stillExists($variant->id),
            'the advertised consent pair was accepted but the variant survived');
    }

    /**
     * And the same question for the trash door, which does NOT forward `ss_variant_removal` in its
     * 409 body — so this asks the policy with that door's own consent descriptor rather than
     * widening the web AJAX contract for a consumer that does not exist.
     */
    public function test_the_trash_doors_consent_descriptor_names_its_own_key(): void
    {
        [, , $product, $variant] = $this->arrange();

        $refusal = \App\Services\VariantRemovalPolicy::refuse(
            $product,
            collect([$variant]),
            false,
            ['key' => 'ss_confirm_variant', 'value' => 'variant_name']
        );

        $this->assertIsArray($refusal, 'an unconfirmed single-variant removal was not refused');
        $this->assertSame('ss_confirm_variant', $refusal['ss_variant_removal']['confirm_with'] ?? null,
            'the trash door descriptor did not reach the refusal: ' . json_encode($refusal));
        $this->assertSame($variant->variant, $refusal['ss_variant_removal']['confirm_value'] ?? null,
            'the trash door was not told which value to send: ' . json_encode($refusal));

        /* The BULK door's default must not have moved: it reads a boolean under its own key, and
           every existing caller passes no descriptor at all. */
        $bulk = \App\Services\VariantRemovalPolicy::refuse($product, collect([$variant]), false);
        $this->assertSame('ss_confirm_variant_removal', $bulk['ss_variant_removal']['confirm_with'] ?? null,
            'the bulk save door\'s advertised key changed, which would break the edit page: '
            . json_encode($bulk));
        /* AND IT MUST ADVERTISE NO `confirm_value`. Review's point, and it is about what the
           server tells a client rather than about what it enforces: this door reads
           `$request->boolean()`, which `filter_var(..., FILTER_VALIDATE_BOOLEAN)` satisfies with
           '1', 'true', 'yes' or 'on'. Publishing `confirm_value: 'true'` would be the server
           advertising that a bare literal is sufficient — documenting the asymmetry in the
           protocol instead of leaving it as a recorded finding (F205) for the owner to decide.
           There is nothing actionable to advertise here: the flag lives in the form already. */
        $this->assertArrayNotHasKey('confirm_value', $bulk['ss_variant_removal'],
            'the bulk refusal advertised a bare literal as sufficient consent: ' . json_encode($bulk));
    }

    // =============================================================================================
    // THE NINE PRINCIPAL AND TARGET CASES THE OWNER NAMED (§4.2)
    // =============================================================================================

    public function test_the_merchant_may_delete_their_own_variant(): void
    {
        [$merchant, $store, , $variant] = $this->arrange();

        $this->assertApiAllowed($this->api($merchant, $store->id, $variant->id), 'the owner was refused');
        $this->assertFalse($this->stillExists($variant->id), 'the owner\'s delete did not happen');
    }

    public function test_authorised_staff_may_delete_a_variant_in_their_own_store(): void
    {
        [$merchant, $store, , $variant] = $this->arrange();
        $staff = $this->staffIn($merchant, $store, 'Edit Products');

        $this->assertApiAllowed($this->api($staff, $store->id, $variant->id),
            'staff holding Edit Products in this store were refused');
        $this->assertFalse($this->stillExists($variant->id), 'the staff delete did not happen');
    }

    public function test_unauthorised_staff_may_not_delete_a_variant(): void
    {
        [$merchant, $store, , $variant] = $this->arrange();
        $staff = $this->staffIn($merchant, $store, 'Manage Product Category');

        $this->assertApiRefused($this->api($staff, $store->id, $variant->id),
            'staff with no product permission deleted a variant and its stock');
        $this->assertTrue($this->stillExists($variant->id), 'the row is gone');
    }

    /**
     * THE CASE THAT SEPARATES `MerchantPermissions` FROM `isAbleTo()`, and the owner's reason for
     * requiring store-scoped permissions.
     *
     * `isAbleTo()` unions every role the user holds, with no store predicate anywhere in it. So a
     * staff member granted `Edit Products` in store B answers TRUE for store A as well. The policy
     * resolves the permission against the store the data belongs to, so this is refused.
     */
    public function test_staff_permitted_in_another_store_may_not_delete_here(): void
    {
        [$merchant, $store, , $variant] = $this->arrange();

        $otherStore = $this->makeStore($merchant, ['default_language' => 'en']);
        $staff = $this->staffIn($merchant, $otherStore, 'Edit Products');

        $response = $this->api($staff, $store->id, $variant->id);

        $this->assertApiRefused($response,
            'a staff member whose Edit Products grant belongs to ANOTHER store deleted this'
            . " store's variant — the union `isAbleTo()` reports is exactly this defect");
        $this->assertTrue($this->stillExists($variant->id), 'the row is gone');
    }

    /**
     * THE PLATFORM OPERATOR. `isAbleTo()` REFUSES them — there is no super-admin bypass in this
     * codebase and operator status is a string on `users.type` — so a permission check without an
     * operator limb is a lockout rather than a tightening.
     */
    public function test_the_platform_operator_may_delete_a_variant_in_any_store(): void
    {
        [$merchant, $store, , $variant] = $this->arrange();

        $operator = $this->makeMerchant();
        $operator->type = 'super admin';
        $operator->current_store = null;
        $operator->save();
        $operator = User::find($operator->id);

        $this->assertSame([], $operator->roles()->pluck('name')->all(),
            'premise: the operator holds no role, so `isAbleTo()` answers false for them');

        $response = $this->api($operator, $store->id, $variant->id);

        $this->assertApiAllowed($response,
            'the platform operator was refused their own platform: '
            . json_encode($response->json()));
    }

    /**
     * DELETING THE PARENT PRODUCT TAKES THE VARIANT WITH IT, so this route produces no orphan at
     * all — and saying so is the point.
     *
     * `product_variants_product_id_foreign` is `ON DELETE CASCADE`. A first version of this case
     * deleted the product and called the result an orphan; the variant was simply gone, so the
     * endpoint answered "not found" and the case passed without ever reaching the policy's
     * no-parent branch. Asserting the cascade is what makes the NEXT case necessary rather than
     * decorative.
     */
    public function test_deleting_the_parent_product_cascades_so_no_orphan_is_left(): void
    {
        [$merchant, $store, $product, $variant] = $this->arrange();

        \DB::table('products')->where('id', $product->id)->delete();

        $this->assertFalse($this->stillExists($variant->id),
            'premise: the FK is ON DELETE CASCADE, so the product\'s deletion removes its variants');

        $this->assertApiRefused($this->api($merchant, $store->id, $variant->id),
            'a variant id that no longer names a row was not refused cleanly');
    }

    /**
     * A REAL ORPHAN — a variant whose `product_id` names nothing — reaches the policy's no-parent
     * branch, and that branch must refuse.
     *
     * DRIVEN AGAINST THE POLICY DIRECTLY RATHER THAN OVER HTTP, and the reason is a real
     * constraint rather than convenience. An orphan row cannot be written while the FK is
     * enforced, and `Schema::disableForeignKeyConstraints()` does not help on SQLite: it issues
     * `PRAGMA foreign_keys = OFF`, which SQLite **ignores inside a transaction**, and
     * `RefreshDatabase` wraps every test in one. The attempt failed with
     * "FOREIGN KEY constraint failed", which is the database being right.
     *
     * So the row cannot exist here — and in production it cannot exist either, since that is MySQL
     * with InnoDB and the constraint declared. This is the policy's DEFENSIVE branch: the state it
     * guards against is a row predating the constraint, or a database where enforcement is off. A
     * guard whose "cannot establish ownership" case fell through to ALLOW would be the worst kind,
     * and nothing else in this file proves it does not.
     */
    public function test_the_policy_refuses_when_the_parent_product_cannot_be_resolved(): void
    {
        [$merchant, , , ] = $this->arrange();

        $orphan = new ProductVariant(['product_id' => 987654, 'variant' => 'S-Red']);
        $orphan->product_id = 987654;

        $this->assertNull(\App\Services\VariantRemovalPolicy::parentProduct($orphan),
            'premise: a product_id naming nothing resolves to no parent');

        $this->assertNotNull(
            \App\Services\VariantRemovalPolicy::authorize($merchant, null),
            'the policy ALLOWED a removal whose parent product could not be resolved — ownership'
            . ' follows the parent, so with no parent there is nothing to establish it from, and'
            . ' "cannot establish" is a refusal, never a pass'
        );

        $this->assertNotNull(
            \App\Services\VariantRemovalPolicy::authorize(null, null),
            'the policy allowed a removal for no principal at all'
        );

        $storeless = new Product(['name' => 'x']);
        $this->assertNotNull(
            \App\Services\VariantRemovalPolicy::authorize($merchant, $storeless),
            'the policy allowed a removal on a product with no store, where nothing can establish'
            . ' whose it is'
        );
    }

    /** A variant of a product in a different tenant. */
    public function test_a_variant_belonging_to_another_tenants_product_is_refused(): void
    {
        [$merchant, $store, , ] = $this->arrange();
        [$other, $otherStore, , $otherVariant] = $this->arrange();

        $response = $this->api($merchant, $store->id, $otherVariant->id);

        $this->assertApiRefused($response,
            "one tenant deleted another tenant's variant");
        $this->assertTrue($this->stillExists($otherVariant->id), 'the other tenant\'s row is gone');
    }

    /** No store resolves. */
    public function test_a_caller_with_no_resolvable_store_is_refused(): void
    {
        [, , , $variant] = $this->arrange();

        $stranger = $this->makeMerchant();
        $stranger->current_store = null;
        $stranger->save();

        $response = $this->api(User::find($stranger->id), null, $variant->id);

        $this->assertApiRefused($response, 'a caller with no resolvable store deleted a variant');
        $this->assertTrue($this->stillExists($variant->id), 'the row is gone');
    }

    /** A variant id that names nothing. */
    public function test_a_variant_id_that_names_nothing_is_refused_rather_than_raising_a_500(): void
    {
        [$merchant, $store, , ] = $this->arrange();

        $response = $this->api($merchant, $store->id, 999999);

        $this->assertApiRefused($response, 'a missing variant id was not refused cleanly');
    }

    // =============================================================================================
    // THE TRASH DOOR INHERITS THE SAME POLICY
    // =============================================================================================

    public function test_the_trash_door_refuses_staff_permitted_only_in_another_store(): void
    {
        [$merchant, $store, , $variant] = $this->arrange();

        $otherStore = $this->makeStore($merchant, ['default_language' => 'en']);
        $staff = $this->staffIn($merchant, $otherStore, 'Edit Products');

        $response = $this->trash($staff, $variant->id);

        $this->assertTrue(
            $response->getStatusCode() < 200 || $response->getStatusCode() >= 300,
            'the trash door accepted a staff member whose grant belongs to another store; it came'
            . ' back as ' . $response->getStatusCode() . ', a 2xx, and both callers announce'
            . ' success on any 2xx without reading the body'
        );
        $this->assertTrue($this->stillExists($variant->id), 'the row is gone');
    }

    /**
     * THE CASE THAT ISOLATES THE PERMISSION QUESTION FROM THE OWNERSHIP ONE, and the only one that
     * can fail for the reason the owner's condition names.
     *
     * The case above is refused by the trash door's OWNERSHIP check — the staff member's
     * `current_store` is the other store, so `productIsOutsideCurrentStore()` catches them and the
     * permission check never decides anything. Here the staff member's `current_store` IS this
     * store, so ownership passes, and the only thing standing between them and the delete is the
     * permission read:
     *
     *   `isAbleTo('Edit Products')`                    -> TRUE. `allPermissions()` unions every
     *                                                     role with no store predicate, so a grant
     *                                                     in store B answers for store A.
     *   `MerchantPermissions::allowsAny(..., $storeId)` -> FALSE. The role's `store_id` is B.
     *
     * So this is exactly the difference the owner asked to be resolved, with nothing else able to
     * decide it.
     */
    public function test_staff_whose_grant_belongs_to_another_store_are_refused_on_the_trash_door(): void
    {
        [$merchant, $store, , $variant] = $this->arrange();

        $otherStore = $this->makeStore($merchant, ['default_language' => 'en']);

        /* Grant `Edit Products` while they are in the OTHER store, so the role carries that
           store_id -- then move them here, where they hold nothing. */
        $staff = $this->staffIn($merchant, $otherStore, 'Edit Products');
        $staff->current_store = $store->id;
        $staff->save();
        $staff = User::find($staff->id);

        $this->assertTrue($staff->isAbleTo('Edit Products'),
            'premise: `isAbleTo()` answers TRUE for them, because it unions across stores — if it'
            . ' did not, this case would not isolate the permission question');
        $this->assertFalse(
            \App\Services\MerchantPermissions::allowsAny($staff, ['Edit Products'], $store->id),
            'premise: the store-scoped read answers FALSE for this store'
        );

        $response = $this->trash($staff, $variant->id);

        $this->assertTrue(
            $response->getStatusCode() < 200 || $response->getStatusCode() >= 300,
            'a staff member whose only Edit Products grant belongs to ANOTHER store deleted this'
            . " store's variant through the trash button. It came back as "
            . $response->getStatusCode() . ', a 2xx, and both panels announce "Variant Deleted'
            . ' Successfully!" on any 2xx without reading the body'
        );
        $this->assertTrue($this->stillExists($variant->id), 'the row is gone');
    }

    /**
     * AND THE SAME PRINCIPAL ON THE SAVE DOOR, because `update()` reads the permission the same way.
     */
    public function test_staff_whose_grant_belongs_to_another_store_are_refused_on_the_save_door(): void
    {
        [$merchant, $store, $product, $variant] = $this->arrange();

        $otherStore = $this->makeStore($merchant, ['default_language' => 'en']);
        $staff = $this->staffIn($merchant, $otherStore, 'Edit Products');
        $staff->current_store = $store->id;
        $staff->save();
        $staff = User::find($staff->id);

        /*
         * A COMPLETE, VALID PAYLOAD — and the first version of this case did not have one, which is
         * why it could not fail. It posted `name`, `status`, `variant_product` and `price` only;
         * `category_id` is `required` in BOTH of `update()`'s rule branches, so the validator
         * refused it at `$validator->fails()` and the case was green against a live defect. The
         * mandated review caught it. `cost_price` is `required` too, and `sale_price` must be less
         * than `price`.
         */
        $categoryId = (int) \DB::table('categories')->insertGetId([
            'name' => 'Textiles',
            'slug' => 'textiles-' . uniqid(),
            'trending' => 0,
            'status' => 1,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'name' => 'F195 Scarf',
            'category_id' => $categoryId,
            'status' => 1,
            'variant_product' => 0,
            'price' => 10,
            'sale_price' => 0,
            'cost_price' => 5,
            'trending' => 0,
            'track_stock' => 1,
            'product_stock' => 7,
            'stock_status' => 'in_stock',
            'low_stock_threshold' => 0,
        ];

        /* PREMISE: the same payload from a principal who IS permitted here must succeed, or this
           case would pass for whatever other reason refuses it. */
        $permitted = $this->staffIn($merchant, $store, 'Edit Products');
        $control = $this->actingAs($permitted)->put(route('product.update', $product->id), $payload);
        $this->assertSame('success', $control->json('flag'),
            'premise: the payload is valid and a permitted principal succeeds with it — otherwise'
            . ' the refusal below proves nothing: ' . substr((string) $control->getContent(), 0, 300));

        /* After the control save, so a refused save is measurable as "nothing moved". */
        $before = (array) \DB::table('products')->where('id', $product->id)->first();

        $payload['name'] = 'RENAMED BY AN UNPERMITTED PRINCIPAL';
        $response = $this->actingAs($staff)->put(route('product.update', $product->id), $payload);

        /*
         * NOT `->json('flag')`: the refused branch answers `redirect()->back()->with('error', …)`,
         * which is HTML, and `->json()` throws "Invalid JSON was returned from the route" on it —
         * which is how this assertion first failed against a WORKING fix. The content check covers
         * both shapes: a success is always the `{"flag":"success"}` array the page's AJAX handler
         * reads.
         */
        $this->assertStringNotContainsString('"flag":"success"', (string) $response->getContent(),
            'the save succeeded for a principal whose only Edit Products grant belongs to ANOTHER'
            . ' store — `isAbleTo()` unions across stores, so nothing else would have refused them');

        $this->assertSame($before, (array) \DB::table('products')->where('id', $product->id)->first(),
            'the refusal was cosmetic: the product row moved anyway');
        $this->assertTrue($this->stillExists($variant->id),
            'the variant is gone, so the refusal was cosmetic');
    }

    /**
     * THE POLICY'S OWN TENANT LIMB, DRIVEN DIRECTLY — and this case exists because a bite found it
     * untested.
     *
     * Removing `authorize()`'s tenant-ownership check changed NOTHING in the HTTP cases: every door
     * already answers the question more narrowly before calling the policy. The API door requires
     * `$product->store_id === $store->id` where `$store` came from `resolveOwnedStore()`, and the
     * trash door compares `getCurrentStore()`. **An unbitten guard is either untested or
     * unnecessary**, so it is worth being exact about which this is.
     *
     * IT IS A DEFENSIVE BRANCH, NOT A LIVE CONTROL TODAY, and the reason is that both
     * `users.current_store` writers were already hardened — `Api\DashboardController::ChangeStore()`
     * and `StoreController` both resolve through an owned-store predicate, with the comment *"any
     * caller could point users.current_store at any store on the platform and thereby satisfy every
     * manual tenant filter as the victim"* recording why. So the state this limb guards against is
     * not reachable through a switch handler.
     *
     * IT STAYS, for one reason that is not sentiment: `MerchantPermissions` answers what a
     * principal MAY DO, never whose store it is, and a merchant's permissions come from one global
     * `store_id IS NULL` role that matches EVERY store id — including another tenant's. So a future
     * caller of this now-shared policy that has no tenant check of its own would be cross-tenant
     * open the moment it was written. This case is what keeps the limb honest in the meantime.
     */
    public function test_the_policy_refuses_another_tenants_product_on_its_own(): void
    {
        [$merchant, , , ] = $this->arrange();
        [, , $foreignProduct, ] = $this->arrange();

        $this->assertNotSame((int) $merchant->creatorId(), (int) $foreignProduct->created_by,
            'premise: the two products belong to different tenants');

        /*
         * THE MERCHANT IS GIVEN PRODUCTION'S OWN ROLE SHAPE, and without this the case cannot make
         * its point. `SecurityTestCase::grantPermission()` creates a role with
         * `store_id => current_store`, so the fixture's merchant holds a STORE-SCOPED grant and
         * `MerchantPermissions` already denies them the foreign store — the premise below failed
         * the first time for exactly that reason. Production is different: `PermissionTableSeeder`
         * creates ONE shared global `admin` role with `store_id` NULL, and both
         * `RegisteredUserController` and `StoreController` hand THAT role to every merchant for
         * every store they create. A null-store role matches every store id in
         * `MerchantPermissions::names()`, by design — it is how a merchant keeps their permissions
         * in all of their own stores.
         */
        $globalRole = \App\Models\Role::forceCreate([
            'name' => 'global-admin-' . $merchant->id,
            'display_name' => 'Admin',
            'guard_name' => 'web',
            'created_by' => $merchant->creatorId(),
            'store_id' => null,
        ]);
        $globalRole->givePermission(
            \App\Models\Permission::firstOrCreate(['name' => 'Edit Products'], ['display_name' => 'Edit Products'])
        );
        $merchant->addRole($globalRole);
        $merchant = User::find($merchant->id);

        $this->assertTrue(
            \App\Services\MerchantPermissions::allowsAny($merchant, ['Edit Products'], $foreignProduct->store_id),
            'premise: with production\'s global null-store role the PERMISSION read answers TRUE'
            . ' across tenants — which is exactly why the ownership question has to be asked'
            . ' separately'
        );

        $this->assertNotNull(
            \App\Services\VariantRemovalPolicy::authorize($merchant, $foreignProduct),
            "the policy authorised a removal on another tenant's product. Permission is not"
            . ' ownership, and anything calling this policy without its own tenant check would be'
            . ' cross-tenant open'
        );
    }

    // =============================================================================================
    // THE RENAME DOOR, which arms a later delete
    // =============================================================================================

    /**
     * `UpdateVariant` HAD NO PERMISSION CHECK AT ALL, and the field it writes is the one the
     * deletes match on.
     *
     * `POST api/admin/updatevariant` wrote `product_variants.variant` from `$request->name` after
     * resolving the row by `ProductVariant.store_id` alone — no `Edit Products`, nothing. And
     * `variant` is the key BOTH variant rebuilds match their deletes on
     * (`whereNotIn('variant', $sku_array)`, `whereNotIn('variant', $com)`), so a rename makes the
     * owning merchant's next ordinary save hard-delete that row as uncovered. A write that looks
     * cosmetic arms a destructive one in another request.
     *
     * Found by the mandated review of the F195 completion.
     */
    public function test_the_rename_door_refuses_a_principal_without_the_product_permission(): void
    {
        [$merchant, $store, , $variant] = $this->arrange();
        $staff = $this->staffIn($merchant, $store, 'Manage Product Category');

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $staff->createToken('auth_token')->plainTextToken
        )->postJson('/api/admin/updatevariant', [
            'store_id' => $store->id,
            'productvariant_id' => $variant->id,
            'name' => 'RENAMED',
        ]);

        $this->assertApiRefused($response,
            'a staff member with no product permission renamed a variant — and `variant` is the key'
            . " the rebuild deletes match on, so the rename arms the owner's next save to delete it");

        $this->assertSame('S-Red',
            (string) ProductVariant::withoutGlobalScopes()->find($variant->id)->variant,
            'the rename landed anyway');
    }

    /**
     * AND THE RENAME DOOR HAS THE SAME UNVALIDATED IDENTIFIER AS THE DELETE DOOR HAD.
     *
     * `'productvariant_id' => 'required'` accepts an array and the handler does
     * `whereKey($request->productvariant_id)`, so `whereIn(...)->first()` resolves the LOWEST
     * matching id. Review found this shape on `deletevariant`; this is its twin, found by looking
     * for the second door rather than fixing the one that was reported. It matters more here than
     * it looks: `variant` is the key BOTH variant rebuilds match their deletes on
     * (`whereNotIn('variant', …)`), so a rename landing on a row the caller did not name arms the
     * owner's next ordinary save to hard-delete it — F202's mechanism, reached through the
     * identifier instead of through the missing permission check.
     */
    public function test_the_rename_door_refuses_an_array_of_variant_ids(): void
    {
        [$merchant, $store, , $first] = $this->arrange();

        $second = ProductVariant::forceCreate([
            'product_id' => $first->product_id,
            'variant' => 'M-Blue',
            'variation_price' => 55,
            'price' => 66,
            'stock' => 7,
            'sku' => 'F195C-0002',
            'variation_option' => '',
            'store_id' => $store->id,
        ])->fresh();

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $merchant->createToken('auth_token')->plainTextToken
        )->postJson('/api/admin/updatevariant', [
            'store_id' => $store->id,
            'productvariant_id' => [$first->id, $second->id],
            'name' => 'RENAMED',
        ]);

        $this->assertApiRefused($response,
            'an array of ids was accepted, so the row renamed is chosen by caller-supplied input: '
            . json_encode($response->json()));

        $this->assertSame('S-Red',
            (string) ProductVariant::withoutGlobalScopes()->find($first->id)->variant,
            'the lowest id in the array was renamed');
        $this->assertSame('M-Blue',
            (string) ProductVariant::withoutGlobalScopes()->find($second->id)->variant,
            'the other id was renamed');
    }

    /** And the permitted principal can still rename, or the guard is a lockout. */
    public function test_the_rename_door_still_works_for_a_permitted_principal(): void
    {
        [$merchant, $store, , $variant] = $this->arrange();

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $merchant->createToken('auth_token')->plainTextToken
        )->postJson('/api/admin/updatevariant', [
            'store_id' => $store->id,
            'productvariant_id' => $variant->id,
            'name' => 'S-Crimson',
        ]);

        $this->assertApiAllowed($response, 'the owner was refused their own rename');
        $this->assertSame('S-Crimson',
            (string) ProductVariant::withoutGlobalScopes()->find($variant->id)->variant,
            'the rename did not land');
    }

    // =============================================================================================
    // A NON-`User` TOKEN REACHES THESE ROUTES, AND MUST GET NOWHERE
    // =============================================================================================

    /**
     * `custom.auth` LOGS A TOKEN'S OWNER INTO THE GUARD MATCHING ITS TOKENABLE CLASS — `Customer`
     * into `customers`, `DeliveryBoy` into `deliveryboy`, `User` into `web`. So a shopper's or a
     * driver's token PASSES the middleware on `api/admin/deletevariant` and reaches the handler
     * with `\Auth::user()` null, because the default guard is `web`.
     *
     * CLAUDE.md's standing rule is that one principal's token proves nothing about another's, and
     * the review of this slice pointed out that nothing here drove a non-`User` token. The policy is
     * independently safe — `authorize()`'s first parameter is untyped and returns a refusal for
     * anything that is not a `User`, rather than a TypeError on `isAbleTo()` — but "safe by reading"
     * is what this project keeps being wrong about.
     *
     */
    #[DataProvider('nonUserPrincipals')]
    public function test_a_non_user_token_gets_nowhere_on_the_variant_doors(string $kind): void
    {
        [$merchant, $store, , $variant] = $this->arrange();

        $principal = $kind === 'customer'
            ? $this->makeCustomerPrincipal($store->id)
            : $this->makeDeliveryBoyPrincipal($store->id);

        $token = 'Bearer ' . $principal->createToken('auth_token')->plainTextToken;

        $delete = $this->withHeader('Authorization', $token)
            ->deleteJson('/api/admin/deletevariant', [
                'store_id' => $store->id,
                'variant_id' => $variant->id,
            ]);

        $this->assertApiRefused($delete, "a {$kind} token reached the variant DELETE door");
        $this->assertTrue($this->stillExists($variant->id), 'the row is gone');

        $rename = $this->withHeader('Authorization', $token)
            ->postJson('/api/admin/updatevariant', [
                'store_id' => $store->id,
                'productvariant_id' => $variant->id,
                'name' => 'RENAMED',
            ]);

        $this->assertApiRefused($rename, "a {$kind} token reached the variant RENAME door");
        $this->assertSame('S-Red',
            (string) ProductVariant::withoutGlobalScopes()->find($variant->id)->variant,
            'the rename landed for a non-User principal');
    }

    /** @return array<string,array{0:string}> */
    public static function nonUserPrincipals(): array
    {
        return ['a shopper' => ['customer'], 'a driver' => ['deliveryboy']];
    }

    // =============================================================================================
    // §4.5 — THE REFUSAL IS CORRECT IN ARABIC AS WELL AS ENGLISH
    // =============================================================================================

    /**
     * EVERY REFUSAL THIS POLICY CAN RETURN READS IN ARABIC, not just in English.
     *
     * The owner's §4.5 requires the correct AR and EN message on a refusal. The D-20 messages were
     * written in English and **none of the five long ones had an `ar.json` entry**, so an Arabic
     * merchant — this platform's primary audience, with the admin in RTL — was told why their
     * deletion was refused in a language they may not read. `Permission denied.` and the API's own
     * two messages were already translated; these were not.
     *
     * ASSERTED BY DRIVING THE POLICY UNDER THE `ar` LOCALE and checking the returned text contains
     * Arabic script, rather than by comparing against a hardcoded translation — a test that pinned
     * the exact Arabic wording would refuse an improvement to the wording, which is the opposite of
     * what it should protect. What must not regress is that the message is translated at all.
     */
    public function test_every_refusal_this_policy_returns_reads_in_arabic(): void
    {
        [$merchant, $store, $product, $variant] = $this->arrange();

        $arabic = '/[\x{0600}-\x{06FF}]/u';

        app()->setLocale('ar');

        try {
            /* 1. the confirmation refusal (no references, nothing in order history) */
            $unconfirmed = \App\Services\VariantRemovalPolicy::refuse(
                $product, collect([$variant]), false
            );
            $this->assertNotNull($unconfirmed, 'premise: an unconfirmed removal is refused');
            $this->assertMatchesRegularExpression($arabic, (string) $unconfirmed['msg'],
                'the "confirm the removal explicitly" refusal is still English under the ar locale');

            /*
             * 2. the live-reference refusal.
             *
             * THROUGH ELOQUENT, AND THAT IS NOT A STYLE CHOICE. `Cart` is `Cachable`, and case 1
             * above has ALREADY queried it — caching the empty result. A
             * `DB::table('carts')->insert()` here is then invisible to the policy's own
             * `Cart::withoutGlobalScopes()->whereIn('variant_id', …)` read, `$blockers` comes back
             * empty, and `refuse()` returns NULL. That is exactly what happened on the first run of
             * this case, and it is the FOURTH false reading model caching has produced in this
             * work. An Eloquent write flushes the model's cache; a raw one does not.
             *
             * The HTTP cases in this file get away with a raw insert only because nothing has
             * populated the cache before the request runs.
             */
            \App\Models\Cart::forceCreate([
                'customer_id' => 1,
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'qty' => 1,
                'price' => 10,
                'store_id' => $store->id,
            ]);
            $referenced = \App\Services\VariantRemovalPolicy::refuse(
                $product, collect([$variant]), true
            );
            $this->assertNotNull($referenced, 'premise: a cart-referenced removal is refused');
            $this->assertMatchesRegularExpression($arabic, (string) $referenced['msg'],
                'the live-reference refusal is still English under the ar locale');

            /* 3. the store-less refusal */
            $storeless = \App\Services\VariantRemovalPolicy::refuse(
                new Product(['name' => 'x']), collect([$variant]), true
            );
            $this->assertNotNull($storeless, 'premise: a store-less product is refused');
            $this->assertMatchesRegularExpression($arabic, (string) $storeless['msg'],
                'the store-less refusal is still English under the ar locale');

            /* 4. the hard block: an order naming the product. Both writes through Eloquent, for
                  the reason case 2 records -- `Cart` and `Order` are both Cachable. */
            \App\Models\Cart::withoutGlobalScopes()->where('variant_id', $variant->id)->get()
                ->each(fn ($row) => $row->delete());
            \App\Models\Order::forceCreate([
                'product_order_id' => 'F195AR-' . uniqid(),
                'customer_id' => 0,
                'is_guest' => 1,
                'product_id' => $product->id,
                'delivery_id' => 0,
                'delivered_status' => 0,
                'return_status' => 0,
                'reward_points' => 0,
                'order_date' => now(),
                'payment_status' => 'Paid',
                'final_price' => 100,
                'store_id' => $store->id,
                'product_json' => json_encode([['product_id' => $product->id, 'qty' => 1]]),
            ]);
            $history = \App\Services\VariantRemovalPolicy::refuse(
                $product, collect([$variant]), true
            );
            $this->assertNotNull($history, 'premise: an order naming the product is a hard block');
            $this->assertMatchesRegularExpression($arabic, (string) $history['msg'],
                'the unverifiable-order-history refusal is still English under the ar locale');

            /* 5. and the authorization refusal */
            $denied = \App\Services\VariantRemovalPolicy::authorize($merchant, null);
            $this->assertNotNull($denied, 'premise: a null product is refused');
            $this->assertMatchesRegularExpression($arabic, (string) $denied['msg'],
                'the permission refusal is still English under the ar locale');
        } finally {
            app()->setLocale('en');
        }
    }

    // =============================================================================================
    // ONE IMPLEMENTATION, ASSERTED AGAINST EXECUTABLE CODE
    // =============================================================================================

    /**
     * The owner's condition is *"no duplicated reference-check implementation"*, and that is a
     * claim about the source rather than about any request. Comments are stripped first because
     * this project has written a test that matched its own prose six times.
     */
    public function test_the_reference_checks_have_exactly_one_implementation(): void
    {
        $policy = $this->sourceWithoutComments(app_path('Services/VariantRemovalPolicy.php'));
        $web = $this->sourceWithoutComments(app_path('Http/Controllers/ProductController.php'));
        $api = $this->sourceWithoutComments(app_path('Http/Controllers/Api/DashboardController.php'));

        /*
         * THE NEEDLES NAME THE REFERENCE QUERIES, NOT THE COLUMN. A first version used the bare
         * `whereIn('variant_id'`, which also matched `ProductImage::whereIn('variant_id', ...)` —
         * the per-variant MEDIA lookup, a different query on the same column — and so failed
         * against a correct extraction. A ratchet that cannot tell the control from its neighbour
         * is noise.
         */
        foreach ([
            "Cart::withoutGlobalScopes()->whereIn('variant_id'" => 'the cart reference query',
            "Wishlist::withoutGlobalScopes()->whereIn('variant_id'" => 'the wishlist reference query',
            "whereNotNull('product_json')" => 'the order-history scan',
        ] as $needle => $what) {
            $this->assertStringContainsString($needle, $policy, "$what must live in the policy");
            $this->assertStringNotContainsString($needle, $web,
                "$what is still implemented in ProductController — two copies of a control is how a"
                . ' guard and the delete it guards come to ask different questions');
            $this->assertStringNotContainsString($needle, $api,
                "$what is implemented in the API controller too");
        }
    }

    /**
     * AND `DeleteVariant()` DOES NOT REACH INTO A WEB CONTROLLER, which the owner forbade by name.
     *
     * SCOPED TO THIS HANDLER, and the reason is a finding rather than convenience: a first version
     * asserted the whole file contained no `ProductController`, and it failed — because
     * `Api\DashboardController` ALREADY does `new ProductController()` twice, at its two STORE
     * DELETION handlers, which loop `$productController->destroy($product)` over every product in
     * the store. Those are pre-existing, predate this work, and belong to a different concern
     * (tearing a whole store down, where the references go with it). Widening this assertion to the
     * file would either fail forever or force an unrelated refactor into a security slice. They are
     * recorded in the ledger at their own severity instead — along with the bulk
     * `ProductVariant::where('store_id', …)->delete()` beside one of them, which is a fourth path
     * to a variant delete and is NOT covered by this policy.
     */
    public function test_the_variant_delete_handler_does_not_instantiate_the_web_controller(): void
    {
        $api = $this->sourceWithoutComments(app_path('Http/Controllers/Api/DashboardController.php'));

        $start = strpos($api, 'public function DeleteVariant(');
        $this->assertNotFalse($start, 'DeleteVariant() not found');

        $next = strpos($api, "\n    public function ", $start + 1);
        $body = $next === false ? substr($api, $start) : substr($api, $start, $next - $start);

        $this->assertStringNotContainsString('ProductController', $body,
            'DeleteVariant() names the web ProductController — calling a web controller from an API'
            . ' controller is the workaround the owner ruled out');
        $this->assertStringContainsString('VariantRemovalPolicy::refuse(', $body,
            'DeleteVariant() does not apply the shared reference checks at all');
        $this->assertStringContainsString('VariantRemovalPolicy::authorize(', $body,
            'DeleteVariant() does not apply the shared authorization check');

        /*
         * BOTH PERMISSION READS MUST SURVIVE, and this assertion exists because the review of this
         * slice found the gap: the handler now holds the early
         * `MerchantPermissions::allowsAny(…, $store->id)` AND `authorize()`, which are redundant
         * once `$ssProduct->store_id === $store->id` is enforced between them. A later
         * "deduplication" that deleted the WRONG one — `authorize()` rather than the early check —
         * would keep every behavioural case green while silently removing the policy's
         * tenant-ownership limb and its product-store permission read. The early one is kept on
         * purpose: its position means a caller without the permission learns nothing about whether
         * a variant id exists.
         */
        $this->assertStringContainsString("MerchantPermissions::allowsAny(\Auth::user(), ['Edit Products'], \$store->id)", $body,
            "DeleteVariant()'s EARLY permission check is gone. It is what keeps the handler from"
            . ' telling an unpermitted caller whether a variant exists, and it must not be removed'
            . ' as a duplicate of the policy call that comes after the row is loaded');

        $this->assertStringContainsString("(int) \$ssProduct->store_id !== (int) \$store->id", $body,
            'the product-must-be-in-the-named-store check is gone, which is what keeps this door no'
            . ' wider than the `ProductVariant.store_id` predicate it replaced');
    }

    /**
     * AND THE RENAME DOOR CARRIES THE SAME TWO CHECKS. It had neither.
     */
    public function test_the_rename_handler_carries_the_shared_checks(): void
    {
        $api = $this->sourceWithoutComments(app_path('Http/Controllers/Api/DashboardController.php'));

        $start = strpos($api, 'public function UpdateVariant(');
        $this->assertNotFalse($start, 'UpdateVariant() not found');

        $next = strpos($api, "\n    public function ", $start + 1);
        $body = $next === false ? substr($api, $start) : substr($api, $start, $next - $start);

        $this->assertStringContainsString('VariantRemovalPolicy::authorize(', $body,
            'UpdateVariant() does not apply the shared authorization check — and the field it writes'
            . ' is the key the rebuild deletes match on');
        $this->assertStringContainsString('VariantRemovalPolicy::parentProduct(', $body,
            'UpdateVariant() still resolves ownership from ProductVariant.store_id rather than from'
            . ' the parent product');
    }
}
