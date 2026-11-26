<?php

namespace Tests\Unit\Routing;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WITHDRAWING A ROUTE CAN HAND ITS URI TO A DIFFERENT HANDLER. FOUR OF MINE DID. `GAP-268`.
 * =================================================================================================
 *
 * THIS IS A CORRECTION, and the commit that needed it is the one that removed 55 resource actions
 * whose controller method does not exist. That change said the withdrawn URIs "answer 404". For 51
 * of the 56 removed rows that is true. For four it was NOT, and I only found out by asking the
 * router what each removed URI matches NOW instead of trusting that removing a route removes a
 * destination:
 *
 *     GET refund-request/create     was @create  ->  NOW OrderRefundController@show
 *     GET shopify_category/create   was @create  ->  NOW ShopifyCategoryController@show
 *     GET shopify_product/create    was @create  ->  NOW ShopifyProductController@show
 *     GET woocom_product/create     was @create  ->  NOW WoocomProductController@show
 *
 * WHY. `Route::resource()` declares `<resource>/create` BEFORE `<resource>/{param}`, and Laravel
 * matches in declaration order — so `create` was SHADOWING `show`. Removing it unshadowed `show`,
 * which then bound `{param} = 'create'`. Nothing was newly exposed: all three import handlers gate
 * on `isAbleTo('Create Shopify Product'/'Create Shopify Category'/'Create Woocommerce Product')`
 * first, and `OrderRefundController::show` catches `\Throwable` and redirects back. But a 500
 * becoming "the show handler with a junk id" is not the 404 that was claimed, and an outbound
 * Shopify/WooCommerce API call keyed on `ids=create` is not nothing.
 *
 * THE FIX IS A PARAMETER CONSTRAINT, NOT ANOTHER ROUTE, and it is deliberately NOT applied
 * uniformly, because measuring how each id is actually produced is the whole point:
 *
 *   - `shopify_product`, `shopify_category`, `woocom_product` take NUMERIC ids. Measured at the
 *     call sites: `route('shopify_product.show', $product_data['id'])` and `.edit` the same
 *     (resources/views/shopify/product.blade.php), `route('shopify_category.show', $cat_data['id'])`
 *     (shopify/category.blade.php), `route('woocom_product.show', $customer->id)` and `.edit`
 *     (woocommerce/product_action.blade.php). `->whereNumber()` therefore restores the 404 AND
 *     hardens the parameter, which is what CLAUDE.md asks for ("parse identifiers strictly";
 *     MySQL coerces `'5abc'` to row 5).
 *
 *   - `refund-request` DOES NOT. Its id is an ENCRYPTED BLOB:
 *     `route('refund-request.show', Crypt::encrypt($refund_request->order_id))`
 *     (resources/views/order/refund-action.blade.php:2), and the handler opens with
 *     `$id = Crypt::decrypt($id)`. `->whereNumber()` there would 404 EVERY REAL REFUND LINK — a
 *     live regression introduced to tidy a URL nobody requests. So it is left alone and its
 *     behaviour is pinned below as what it is, not as what would read better.
 *
 * `refund-request/create` consequently still reaches `show`, where `Crypt::decrypt('create')`
 * throws `DecryptException`, `catch (\Throwable $th)` catches it and the response is
 * `redirect()->back()->with('error', ...)`. Not a 404, not a crash, and not a disclosure. Asserted
 * as such so nobody "fixes" it with a numeric constraint and breaks the refund screen.
 */
class ResourceWithdrawalDoesNotRedirectTrafficTest extends TestCase
{
    /** @return array{0: string, 1: string} action name, matched uri — or ['(none)', '(404)'] */
    private function resolve(string $verb, string $uri): array
    {
        try {
            $route = Route::getRoutes()->match(Request::create('/' . ltrim($uri, '/'), $verb));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return ['(none)', '(' . $e->getStatusCode() . ')'];
        }

        return [$route->getActionName(), $route->uri()];
    }

    /**
     * THE DEFECT. A withdrawn `create` URI must not be absorbed by the resource's `show` route --
     * except for the one where constraining the parameter would break a live link.
     */
    #[Test]
    public function test_a_withdrawn_create_uri_is_not_absorbed_by_the_show_route(): void
    {
        $observed = [];

        foreach ([
            'shopify_product/create',
            'shopify_category/create',
            'woocom_product/create',
            'refund-request/create',
        ] as $uri) {
            [$action, $matched] = $this->resolve('GET', $uri);
            $observed[$uri] = $action . '  @  ' . $matched;
        }

        $this->assertSame(
            [
                'shopify_product/create' => '(none)  @  (404)',
                'shopify_category/create' => '(none)  @  (404)',
                'woocom_product/create' => '(none)  @  (404)',
                /*
                 * DOCUMENTED, NOT DESIRED. See the class docblock: this resource's id is
                 * `Crypt::encrypt`ed, so `->whereNumber()` would 404 every real refund link. The
                 * handler catches \Throwable and redirects back with an error.
                 */
                'refund-request/create' => \App\Http\Controllers\OrderRefundController::class
                    . '@show  @  refund-request/{refund_request}',
            ],
            $observed,
            'a withdrawn `create` URI is resolving somewhere unexpected. If one of the three 404s '
            . 'has become a `@show`, its `->whereNumber()` constraint was dropped. If '
            . '`refund-request/create` has become a 404, check HOW: a `->whereNumber()` on that '
            . 'resource also 404s every `Crypt::encrypt`ed refund link, which is a live regression.'
        );
    }

    /**
     * THE OTHER DIRECTION, and the one that matters more: the constraint must not have broken the
     * routes it was added to. A numeric id must still reach each handler.
     */
    #[Test]
    public function test_the_constrained_resources_still_answer_a_numeric_id(): void
    {
        $observed = [];

        foreach ([
            'shopify_product/7' => \App\Http\Controllers\ShopifyProductController::class . '@show',
            'shopify_product/7/edit' => \App\Http\Controllers\ShopifyProductController::class . '@edit',
            'shopify_category/7' => \App\Http\Controllers\ShopifyCategoryController::class . '@show',
            'shopify_category/7/edit' => \App\Http\Controllers\ShopifyCategoryController::class . '@edit',
            'woocom_product/7' => \App\Http\Controllers\WoocomProductController::class . '@show',
            'woocom_product/7/edit' => \App\Http\Controllers\WoocomProductController::class . '@edit',
        ] as $uri => $_) {
            [$action] = $this->resolve('GET', $uri);
            $observed[$uri] = $action;
        }

        $this->assertSame(
            [
                'shopify_product/7' => \App\Http\Controllers\ShopifyProductController::class . '@show',
                'shopify_product/7/edit' => \App\Http\Controllers\ShopifyProductController::class . '@edit',
                'shopify_category/7' => \App\Http\Controllers\ShopifyCategoryController::class . '@show',
                'shopify_category/7/edit' => \App\Http\Controllers\ShopifyCategoryController::class . '@edit',
                'woocom_product/7' => \App\Http\Controllers\WoocomProductController::class . '@show',
                'woocom_product/7/edit' => \App\Http\Controllers\WoocomProductController::class . '@edit',
            ],
            $observed,
            'a numeric id no longer reaches the handler, so the `->whereNumber()` constraint has '
            . 'broken the working routes it was added to -- the import screens link with '
            . '`$product_data[\'id\']`, `$cat_data[\'id\']` and `$customer->id`.'
        );
    }

    /** And the encrypted refund link, which must keep working. */
    #[Test]
    public function test_the_encrypted_refund_link_still_reaches_the_handler(): void
    {
        $token = \Illuminate\Support\Facades\Crypt::encrypt(7);

        [$action] = $this->resolve('GET', 'refund-request/' . urlencode($token));

        $this->assertSame(
            \App\Http\Controllers\OrderRefundController::class . '@show',
            $action,
            'a Crypt::encrypt()ed refund id no longer reaches OrderRefundController@show. That is '
            . 'every refund link in resources/views/order/refund-action.blade.php.'
        );
    }
}
