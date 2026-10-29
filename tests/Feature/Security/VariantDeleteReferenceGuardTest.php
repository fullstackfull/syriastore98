<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StripsSourceComments;

/**
 * F195 — THE D-20 VARIANT-REMOVAL BLOCK HAD A TWO-CLICK BROWSER BYPASS.
 * =============================================================================================
 *
 * `ProductController::ssRefuseVariantRemoval()` holds every reference check D-20 requires — a
 * shopper cart line, a wishlist entry, an order line carrying a `variant_id`, and the
 * unverifiable-order-history block for any order naming the product — and it was reached from
 * exactly two call sites, both inside `update()`. Two other routed paths reached
 * `ProductVariant::delete()` without it:
 *
 *   `DELETE product-attribute-delete/{id}`  ProductController::product_attribute_delete()
 *   `DELETE api/admin/deletevariant`        Api\DashboardController::DeleteVariant()
 *
 * The first is wired to a **per-variant trash button in BOTH variant panels**
 * (`attribute_combinations_data.blade.php` `.delete-option-comment`, and
 * `attribute_combinations_edit.blade.php` `.delete-comment`), and **neither button carries any
 * confirmation**. So a merchant holding `Edit Products` on their own product could delete, in two
 * clicks and with no dialog, the exact variant the Save path hard-blocks. The second had **no
 * permission check at all**.
 *
 * F184 — the missing confirmation UI on the Save path — is the mirror of this and is **fail-safe**:
 * its failure mode is that nothing is deleted. This one is fail-OPEN, which is why it is fixed
 * first and why F184 now depends on it: a confirmation dialog on one door while the other deletes
 * unguarded is a lock on one door of two.
 *
 * WHY 409 AND NOT THE GUARD'S OWN SHAPE. `update()` returns the guard's refusal array with HTTP
 * 200 and `flag: 'error'`, because the product form reads `data.flag`. Both callers of the TRASH
 * endpoint announce *"Variant Deleted Successfully!"* and remove the row from the DOM on **any
 * 2xx without reading the body** — the handler's own comment says so and it is still true. So the
 * guard's shape here would tell the merchant the variant was deleted, hide it from the panel, and
 * leave it in the database. That is worse than the bypass. 409 Conflict is the honest code, and
 * because neither caller had an `error:` handler at all, adding them is part of this change rather
 * than a follow-up: without them a non-2xx is silent and the merchant clicks again.
 */
class VariantDeleteReferenceGuardTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    /** A product with two variants, nothing referencing either. */
    private function arrange(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Products');

        $product = Product::forceCreate([
            'name' => 'F195 Scarf',
            'slug' => 'f195-scarf-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'track_stock' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 7,
            'product_type' => null,
            'status' => 1,
            'variant_product' => 1,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);

        $variants = [];
        foreach (['S-Red', 'M-Blue'] as $i => $name) {
            $variants[$name] = ProductVariant::forceCreate([
                'product_id' => $product->id,
                'variant' => $name,
                'variation_price' => 100 + $i,
                'price' => 200 + $i,
                'stock' => 30 + $i,
                'sku' => sprintf('F195-%04d', $i),
                'variation_option' => '',
                'store_id' => $store->id,
            ]);
        }

        return [$merchant, $product, $variants];
    }

    private function trashDelete($merchant, ProductVariant $variant)
    {
        return $this->actingAs($merchant)
            ->delete(route('product.attribute.delete', $variant->id));
    }

    /** `ProductVariant` carries no StoreScope, but read without scopes anyway — the project's rule. */
    private function stillExists(int $id): bool
    {
        return ProductVariant::withoutGlobalScopes()->whereKey($id)->exists();
    }

    public function test_the_trash_path_refuses_a_variant_a_shopper_cart_references(): void
    {
        [$merchant, $product, $variants] = $this->arrange();
        $doomed = $variants['S-Red'];

        \DB::table('carts')->insert([
            'customer_id' => 1,
            'product_id' => $product->id,
            'variant_id' => $doomed->id,
            'qty' => 1,
            'price' => 10,
            'store_id' => $product->store_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->trashDelete($merchant, $doomed);

        $this->assertSame(409, $response->getStatusCode(),
            'the trash button deleted a variant sitting in a shopper cart — the D-20 block the Save'
            . ' path applies was bypassed by a button in the same panel');
        $this->assertTrue($this->stillExists($doomed->id),
            'the row is gone, so the refusal was cosmetic');
    }

    /**
     * THE HARD BLOCK: an order naming the product at all.
     *
     * This is the one the guard calls `unverifiable_order_history` — the storefront's order-line
     * shape writes `product_id` on every line but no `variant_id`, so "no order used this variant"
     * is unprovable while "no order named this product" is provable. It refuses unconditionally and
     * no confirmation can carry a delete past it; this case proves the trash path inherits that.
     */
    public function test_the_trash_path_refuses_when_an_order_names_the_product(): void
    {
        [$merchant, $product, $variants] = $this->arrange();
        $doomed = $variants['M-Blue'];

        \DB::table('orders')->insert([
            'product_order_id' => 'F195-' . uniqid(),
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
            'store_id' => $product->store_id,
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

        $response = $this->trashDelete($merchant, $doomed);

        $this->assertSame(409, $response->getStatusCode(),
            'an order names this product, so D-20 hard-blocks the removal on the Save path — the'
            . ' trash button must not be a way around it');
        $this->assertTrue($this->stillExists($doomed->id), 'the row is gone');
    }

    /**
     * THE ASSERTION THAT MATTERS MOST FOR HONESTY, and it is about the status code rather than the
     * body.
     *
     * Both callers announce success and remove the row from the DOM on ANY 2xx without reading the
     * response. A refusal served as 200 — which is the shape `update()` uses — would therefore tell
     * the merchant the variant was deleted while it is still in the database. Pinning "not 2xx"
     * separately from "409" means a future change to a different non-2xx code still passes, while
     * a slide back to the guard's own 200 shape fails here with the reason attached.
     */
    public function test_the_refusal_is_not_a_2xx_because_both_callers_announce_success_on_any_2xx(): void
    {
        [$merchant, $product, $variants] = $this->arrange();
        $doomed = $variants['S-Red'];

        \DB::table('wishlists')->insert([
            'customer_id' => 1,
            'product_id' => $product->id,
            'variant_id' => $doomed->id,
            'store_id' => $product->store_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $status = $this->trashDelete($merchant, $doomed)->getStatusCode();

        $this->assertTrue(
            $status < 200 || $status >= 300,
            'the refusal came back as ' . $status . ', a 2xx. Both panels announce "Variant Deleted'
            . ' Successfully!" and remove the row from the DOM on any 2xx without reading the body,'
            . ' so this tells the merchant the variant is gone while it is still in the database —'
            . ' worse than the bypass this fix closes.'
        );

        /*
         * A 500 IS NOT A REFUSAL, and the first cut of this case accepted one. `< 200 || >= 300`
         * is satisfied by a thrown exception, so a change that broke the endpoint outright would
         * have read as the guard working. Exception handling is on by default in this suite, so a
         * throw really does arrive here as 500.
         */
        $this->assertLessThan(500, $status,
            'the endpoint answered ' . $status . '. That is a thrown exception, not a refusal: the'
            . ' guard is not refusing this delete, the handler is broken.');

        /*
         * AND THE ROW HAS TO SURVIVE. Its two sibling cases assert this and this one did not —
         * which made it the only wishlist-blocker case in the file and the only one that could
         * pass while the variant was deleted.
         */
        $this->assertTrue($this->stillExists($doomed->id),
            'the refusal was reported and the row is gone, so it was cosmetic');
    }

    /**
     * THE COMPATIBILITY HALF. Without this the fix is a regression: the ordinary case is a merchant
     * tidying a variant nothing references, and that must still work in one click.
     *
     * It is also what rules out the naive implementation. `ssRefuseVariantRemoval()`'s last
     * condition refuses unless a confirmation flag is set, so passing the request's (absent) flag
     * through here would fail every ordinary trash click. The trash endpoint IS D-20 condition 3's
     * separate deliberate operation, so it passes `true` and takes the blocks only.
     */
    public function test_the_trash_path_still_deletes_a_variant_nothing_references(): void
    {
        [$merchant, , $variants] = $this->arrange();
        $spare = $variants['M-Blue'];

        $response = $this->trashDelete($merchant, $spare);

        $this->assertTrue(
            $response->getStatusCode() >= 200 && $response->getStatusCode() < 300,
            'a variant nothing references can no longer be deleted (status '
            . $response->getStatusCode() . '). The guard was bolted on without distinguishing the'
            . ' blocks from the confirmation refusal, which breaks the common case.'
        );
        $this->assertFalse($this->stillExists($spare->id), 'the row survived a permitted delete');
    }

    /**
     * The guard has to run BEFORE the delete, or it is decoration.
     *
     * TWO THINGS WERE WRONG WITH THE FIRST CUT, AND THE REVIEW CAUGHT BOTH. It searched the raw
     * method body for `ssRefuseVariantRemoval(` — which matches the PROSE in this very fix's own
     * docblock ("checks lived only in `ssRefuseVariantRemoval()`...") several lines ABOVE the real
     * call. So deleting the call and keeping the comment passed all three assertions. That is the
     * sixth time this repository has made that exact mistake: `Tests\Support\StripsSourceComments`
     * exists for it and names the previous five in its own docblock. It is used here, and the
     * needle now carries `$this->`, which appears on the real call and in no comment.
     */
    public function test_the_guard_runs_before_the_delete_on_the_trash_path(): void
    {
        $m = new \ReflectionMethod(\App\Http\Controllers\ProductController::class, 'product_attribute_delete');

        /* The whole file with comments stripped, then narrowed to this method by its signature.
           Stripping first is what makes the narrowing safe: a comment cannot carry the signature. */
        $code = $this->sourceWithoutComments('app/Http/Controllers/ProductController.php');
        $open = strpos($code, 'public function product_attribute_delete(');
        $this->assertNotFalse($open, 'the trash handler is gone or renamed; re-derive this case');

        $body = substr($code, $open);

        $guard = strpos($body, '$this->ssRefuseVariantRemoval(');
        $delete = strpos($body, '$attribute->delete();');

        $this->assertNotFalse($guard, 'the trash handler no longer consults the reference guard at'
            . ' all, which is F195 exactly');
        $this->assertNotFalse($delete, 'the delete is gone; re-derive this case');
        $this->assertLessThan($delete, $guard,
            'the guard is asked AFTER the row is deleted, so it cannot refuse anything');
        /* Arguments this way round: expected-less-than first. The first cut had them reversed and
           asserted `4000 < distance`, which the real 787 characters failed. */
        $this->assertLessThan(4000, $delete - $guard,
            'the guard match is thousands of characters from the delete, which suggests this is'
            . ' matching a different method further down the file rather than this one');
    }

    /**
     * THE REFACTOR IS WHAT LETS A SECOND CALLER IN, so it is pinned.
     *
     * `ssRefuseVariantRemoval()` used to take a `Request` and read the confirmation off it, which
     * tied it to a path that has one. `product_attribute_delete($id)` has no request parameter.
     * Reverting the signature would make the trash path unable to call the guard — so this is the
     * structural fact the fix rests on.
     */
    public function test_the_guard_takes_the_confirmation_as_a_parameter(): void
    {
        $m = new \ReflectionMethod(\App\Http\Controllers\ProductController::class, 'ssRefuseVariantRemoval');
        $names = array_map(fn ($p) => $p->getName(), $m->getParameters());

        $this->assertContains('confirmed', $names,
            'the guard no longer takes the confirmation as a parameter, so a caller with no Request'
            . ' — which is every caller but update() — cannot use it');
        $this->assertNotContains('request', $names,
            'the guard takes a Request again. It used it for exactly one boolean, and taking it is'
            . ' what kept the trash path from reusing this guard for as long as F195 stood.');
    }

    /**
     * A NON-2XX IS SILENT WITHOUT AN `error:` HANDLER, and neither panel had one.
     *
     * jQuery calls `success:` only on 2xx. Both delete buttons passed `success:` alone, so a 409
     * would fade no loader, show no toast, and leave the row in place — the merchant clicks again
     * and concludes the button is broken. Asserted on the views because the behaviour is in them.
     */
    public function test_both_variant_panels_surface_a_refusal_to_the_merchant(): void
    {
        foreach ([
            'product/attribute_combinations_data.blade.php' => '.delete-option-comment',
            'product/attribute_combinations_edit.blade.php' => '.delete-comment',
        ] as $view => $trigger) {
            $source = file_get_contents(resource_path('views/' . $view));

            $this->assertStringContainsString($trigger, $source,
                'premise: ' . $view . ' still carries the ' . $trigger . ' trigger');
            $this->assertStringContainsString('error:', $source,
                $view . ' has no `error:` handler on its delete request, so the 409 this fix'
                . ' introduces is invisible: no toast, no loader reset, and the row stays. A silent'
                . ' failure on a destructive button is worse than the old silent success.');

            /*
             * THE HANDLER HAS TO SURFACE THE SERVER'S MESSAGE, not merely exist. Review's point:
             * `error:` is a six-character file-wide match that stops being a ratchet the first
             * time either file gains any other error handler, and it says nothing about the
             * merchant being told anything. These two needles tie it to reading the refusal body
             * and showing it.
             */
            $this->assertStringContainsString('responseJSON', $source,
                $view . "'s error handler does not read the response body, so the server's reason"
                . ' for refusing never reaches the merchant');
            $this->assertStringContainsString("show_toastr('{{ __('Error') }}'", $source,
                $view . "'s error handler does not show an error toast, so a refused delete is"
                . ' still silent');
        }
    }

    /**
     * THE ADMIN API PATH HAD NO PERMISSION CHECK AT ALL — not `Edit Products`, not anything.
     *
     * Its web sibling requires `Edit Products` for a stated reason: the row carries stock. Nothing
     * in the Flutter admin app calls `deletevariant`, so adding the check takes no operation away
     * from a shipped client.
     *
     * TWO WAYS THE FIRST CUT OF THIS CASE PASSED FOR THE WRONG REASON, both caught by biting it:
     *
     *  1. It used `actingAs()`. The route carries `custom.auth`, which resolves a SANCTUM BEARER
     *     TOKEN and refuses a request without one before the handler runs — so the request never
     *     reached the code under test, and removing the permission check left the case green.
     *     A real token is minted here, the way every sibling admin-API test does it.
     *  2. It asserted only `status === 0`. Every refusal on this path answers that — the
     *     `custom.auth` 401, `resolveOwnedStore()`'s "Store not found!", the validator, and the
     *     permission check — so the shape alone cannot say WHICH guard fired. The message is
     *     asserted too, and the permitted case below is what proves the check is the thing
     *     refusing rather than something upstream.
     */
    public function test_the_api_path_now_requires_edit_products(): void
    {
        [$merchant, , $variants] = $this->arrange();
        $doomed = $variants['S-Red'];

        $staff = $this->makeStaff($merchant);
        $staff->forceFill(['current_store' => $doomed->store_id])->save();
        $this->grantPermission(\App\Models\User::find($staff->id), 'Manage Products');

        $refused = $this->apiDelete($staff, $doomed);

        $this->assertSame(0, $refused['status'] ?? null,
            'a staff member without `Edit Products` still deletes a variant and the stock it'
            . ' carries through the admin API, which had no permission check at all');
        /*
         * `data.message`, NOT `message`. `ApiResponser::error()` answers
         * `{status: 0, message: 'fail', data: {message: '<the real one>'}}` — the top-level
         * `message` is the literal string "fail" for every refusal on this path, so reading it
         * tells you nothing. A first cut asserted on it and failed against a working fix.
         */
        $this->assertSame('Permission denied.', $refused['data']['message'] ?? null,
            'the refusal came from somewhere OTHER than the permission check — most likely'
            . ' `custom.auth` or `resolveOwnedStore()` — so this case is not measuring the fix.'
            . ' Got: ' . json_encode($refused));
        $this->assertTrue($this->stillExists($doomed->id), 'the row is gone');
    }

    /**
     * THE COMPATIBILITY HALF OF THE SAME CHECK, and it is what makes the case above meaningful.
     *
     * Without it, a check that refused EVERYONE would pass the refusal case. This also proves the
     * principal really does clear `custom.auth` and `resolveOwnedStore()`, so the refusal above is
     * attributable to the permission and nothing upstream.
     */
    public function test_the_api_path_still_permits_a_caller_who_holds_edit_products(): void
    {
        [$merchant, , $variants] = $this->arrange();
        $doomed = $variants['M-Blue'];

        $staff = $this->makeStaff($merchant);
        $staff->forceFill(['current_store' => $doomed->store_id])->save();
        $this->grantPermission(\App\Models\User::find($staff->id), 'Edit Products');

        $allowed = $this->apiDelete($staff, $doomed);

        $this->assertSame(1, $allowed['status'] ?? null,
            'a staff member holding `Edit Products` can no longer delete a variant through the admin'
            . ' API, so the new check is a lockout rather than a tightening. Got: '
            . json_encode($allowed));
        $this->assertFalse($this->stillExists($doomed->id),
            'the call reported success and the row is still there');
    }

    private function apiDelete(\App\Models\User $user, ProductVariant $variant): array
    {
        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $user->createToken('auth_token', ['merchant'])->plainTextToken
        )->json('DELETE', '/api/admin/deletevariant', [
            'store_id' => $variant->store_id,
            'variant_id' => $variant->id,
        ]);

        return json_decode($response->getContent(), true) ?? [];
    }
}
