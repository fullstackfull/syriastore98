<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'customer_id',
        'log_type',
        'remark',
        
        'store_id',
    ];

    public static function order_entry($data= [])
    {
        // order create activity log
        $ActivityLog = new ActivityLog();
        $ActivityLog->customer_id = $data['customer_id'];
        $ActivityLog->log_type = 'place order';
        $ActivityLog->remark = json_encode(
            [
                'order_id' => $data['order_id'],
                'order_date' => $data['order_date'],
                'product' => $data['products'],
                'price' => $data['final_price'],
                'payment_type' => $data['payment_type'],
            ]
        );
        $ActivityLog->store_id = $data['store_id'];
        $ActivityLog->save();
    }

    public function userinfo()
    {
        return $this->hasOne(User::class, 'id', 'customer_id');
    }

    /**
     * P2.5-PKG-G FOLLOW-UP -- `$storeId` IS NOW REQUIRED, and the lookup carries it.
     *
     * This was `Product::whereIn('id', explode(',', $id))->get()` with NO store predicate, and
     * `Product` is absent from `config/tenancy.php`'s `scoped_models`, so
     * `StoreScope::isActiveFor()` short-circuits and adds NOTHING for any principal -- not a
     * customer, not a merchant, not the operator. The same fact produced the S19 cross-tenant
     * stock drain. It therefore resolved ANY tenant's product id to that tenant's product NAME.
     *
     * TEN RENDER SITES, all in `customer/timeline.blade.php`, all fed by `remark.product`.
     *
     * THE ACTIVITY ROW IS NOT THE PROBLEM. `customerTimeline()` already scopes the feed itself
     * (F-C-09, `:570`/`:577`), so every row reaching this helper is legitimately the viewing
     * store's. What leaked is the name fetched for an id INSIDE one of those rows -- and a
     * foreign id gets in through a real writer: `WishlistController::product_wishlist()` accepted
     * `Product::find($request->product_id)` with no store predicate and wrote that id into
     * `remark` stamped with the current store. Reachable by a LOGGED-IN CUSTOMER of the victim
     * store -- that handler derives `customer_id` from `auth('customers')`, so the precondition
     * is an account there, not anonymity. Both halves are fixed; `CustomerTimelineTenancyTest`
     * and `StorefrontWishlistProductTenancyTest` fail independently.
     *
     * MEASURED before the fix: store B's product name rendered on store A's merchant's timeline
     * for a customer of store A
     * (`test_the_timeline_does_not_name_another_stores_product_from_this_stores_activity`).
     *
     * FAILS CLOSED AND LOUD. `$storeId` has no default, so a caller that forgets it is an
     * `ArgumentCountError` at the call site rather than a silent platform-wide name lookup --
     * there are no dynamic callers, so that is a compile-time-ish guarantee rather than a hope.
     * Passing null resolves NOTHING rather than everything, which is the direction a tenancy
     * helper has to fail in.
     *
     * NON-NUMERIC AND ARRAY INPUT both resolve to the empty string instead of throwing.
     * `remark.product` is caller-shaped data: `explode()` raised a TypeError on an array, and a
     * non-numeric string already matched no product, so this narrows a 500 to an empty cell and
     * changes nothing a merchant could previously read.
     *
     * @param  mixed    $id       one product id, or the comma-separated list `order_entry()` writes
     * @param  int|null $storeId  the store whose catalogue may be named; null names nothing
     */
    public static function products($id, ?int $storeId)
    {
        if ($storeId === null || $storeId <= 0) {
            return '';
        }

        if (! is_scalar($id)) {
            return '';
        }

        $productIdArray = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) $id)),
            static fn (int $productId): bool => $productId > 0
        )));

        if ($productIdArray === []) {
            return '';
        }

        return Product::whereIn('id', $productIdArray)
            ->where('store_id', $storeId)
            ->pluck('name')
            ->implode(', ');
    }

    /**
     * P2.5-PKG-G FOLLOW-UP (2) -- THE OTHER HALF OF THE SAME ROW.
     *
     * This was `ProductVariant::find($id)` with no predicate. `ProductVariant` carries no
     * `StoreScope` at all (`ProductVariant.php` uses only `HasFactory, Cachable`) and is absent
     * from `scoped_models`, so nothing filtered it for any principal. Four render sites in
     * `customer/timeline.blade.php`, fed by the same caller-shaped `remark` JSON as `products()`
     * above -- so fixing only `products()` left the row HALF resolved: the product name withheld
     * and the foreign variant label still printed beside it.
     *
     * A FOREIGN VARIANT ID REALLY REACHES IT, by a different writer than the wishlist one:
     * `CartController::cart_remove()` finds the cart row by `customer_id` + `id` with no store
     * predicate (`Cart::scopeForCustomer` adds only `customer_id`, and `Cart`'s global scope is
     * inert for a shopper -- the default guard is empty, so the tenant is unresolved and
     * `null_tenant => 'open'` adds nothing), then stamps the log with the slug store the caller
     * chose while `remark.variant` comes from that possibly-foreign row.
     *
     * EXPOSURE IS LOW AND SAID SO PLAINLY: `product_variants.variant` is an option label such as
     * `M-Natural`, which the other store already serves publicly on its product page. It is not
     * money and not personal data. It is fixed because it is the same defect shape on the same
     * row, not because of what it discloses.
     *
     * `product_id`, NOT `store_id`, AND THAT IS THE CODEBASE'S OWN REASON rather than a guess:
     * `product_variants.store_id` is NULLABLE (its migration declares it so), and
     * `CartController.php:490-496` states the rule in as many words -- "`product_id`, not
     * `store_id`: `product_variants.store_id` is nullable". A hard store predicate here would
     * drop legitimate rows, which is why the earlier report handed this to review as open rather
     * than closing it on a guess.
     *
     * FAILS CLOSED, the same shape as `products()`: `$storeId` is required, null names nothing,
     * and a variant whose product belongs to another store resolves to null -- which the four
     * call sites already render as an empty cell.
     *
     * @param  mixed    $id       one product_variants id, from `remark.variant`
     * @param  int|null $storeId  the store whose catalogue may be named; null names nothing
     */
    public static function variants($id, ?int $storeId)
    {
        if ($storeId === null || $storeId <= 0) {
            return null;
        }

        if (! is_scalar($id) || (int) $id <= 0) {
            return null;
        }

        return ProductVariant::where('id', (int) $id)
            ->whereIn('product_id', Product::where('store_id', $storeId)->select('id'))
            ->value('variant');
    }

    /**
     * P2.5-PKG-C (F-C-09). `$storeId` IS NOW REQUIRED, and the query carries it.
     *
     * This summed `Order::where('customer_id', $userId)` with NO store predicate, and `Order` is
     * absent from `config/tenancy.php`'s `scoped_models`, so Total Spend and Total Orders on the
     * customer timeline aggregated the customer's purchases across EVERY STORE on the platform --
     * one merchant's page showing another merchant's revenue.
     *
     * ALREADY DECLARED OPEN before this fix: `ResidualTenancyExposureTest`'s raw-expression test
     * recorded this exact site by path and stated the harm in as many words. Its narrative has
     * been updated in the same change, because a resolved declaration is fiction.
     *
     * MEASURED (CustomerTimelineTenancyTest): a customer with a $25 order in store A and a $775
     * order in store B returned total_orders 2 and total_spend 800 for store A's merchant.
     *
     * THE `DB::raw` STAYS. It is a SELECT EXPRESSION on an Eloquent query, not a bypass -- the
     * surrounding builder carries its scopes, and the ratchet above says so explicitly. The defect
     * was never the raw SQL; it was the missing predicate.
     *
     * FAILS CLOSED when either argument is absent. The parameter has no default, so a caller that
     * forgets it is a TypeError at the call site rather than a silent platform-wide sum -- which
     * is how this would otherwise come back. There is exactly one caller
     * (`CustomerController::customerTimeline`), so requiring it costs nothing.
     *
     * @param int|null $storeId the store whose orders may be counted; null sums nothing
     */
    public static function totalspend($userId = null, ?int $storeId = null)
    {
        $totalSpend = 0;
        $totalOrders = 0;
        if($userId && $storeId)
        {
            $results = Order::where('store_id', $storeId)
                    ->where('customer_id', $userId)
                    ->select(\DB::raw('SUM(final_price) as total_price ,COUNT(*) as order_count'))
                    ->first();
            if ($results) {
                $totalSpend = !empty($results->total_price) ? $results->total_price : '0';
                $totalOrders = $results->order_count;
            }
        }
        return ['total_spend' => $totalSpend, 'total_orders' => $totalOrders];
    }
}
