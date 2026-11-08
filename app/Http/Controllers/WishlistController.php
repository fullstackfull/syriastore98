<?php

namespace App\Http\Controllers;

use App\Models\{ActivityLog, Cart, Customer, Store, Wishlist};
use App\Models\ProductVariant;
use App\Models\Utility;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use App\Models\Product;
use Illuminate\Support\Facades\Crypt;
use App\DataTables\WishlistDataTable;
use Illuminate\Support\Facades\Cache;

class WishlistController extends Controller
{
    public function index(WishlistDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Wishlist')) {
            return $dataTable->render('wishlist.index');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Wishlist  $wishlist
     * @return \Illuminate\Http\Response
     */
    public function show(Wishlist $wishlist)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Show Wishlist')) {
            // P2.5-PKG-B (F-SEC-09). TWO defects here, and fixing either alone leaves the other.
            //
            // (1) THE BOUND ROW. `Wishlist` is absent from `scoped_models`, so binding resolved
            // ANY store's row. The permission above says what the caller may do to a wishlist,
            // never WHICH wishlist -- and `Show Wishlist` is in the default grant list at
            // `Utility.php:2959` that `addNewData()` gives every merchant on every login, so this
            // was live platform-wide, not a rare configuration. MEASURED at 200 with the victim's
            // product name in the body before this guard existed.
            //
            // (2) THE QUERY BELOW had no store predicate, so it collected every row carrying that
            // customer id in ANY store. Guarding the bound row does NOT close it: ids collide
            // across tenants in a shared database -- `customers` is store-scoped precisely because
            // the id space is reused -- so a store-B row sharing a customer id rendered on store
            // A's page. `WishlistShowCrossTenantTest` asserts the two separately, because one test
            // could not tell me which of them I had actually repaired.
            //
            // Found by the tenant-authz review of F-SEC-08, which guarded `edit` and `update` on
            // either side of this method and left the one method in the file that returns bytes.
            // The empty-body census could not see it, the structural ratchet does not look at
            // non-empty bodies by design, and F-SEC-08's feature test had no `show` case.
            $storeId = getCurrentStore();

            if (empty($storeId) || empty($wishlist->store_id)
                || (int) $wishlist->store_id !== (int) $storeId) {
                abort(404);
            }

            $wish_id = $wishlist->customer_id;
            $wishlist_product = Wishlist::where('customer_id', $wish_id)
                ->where('store_id', $storeId)
                ->get();

            return view('wishlist.show', compact('wishlist_product'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Wishlist  $wishlist
     * @return \Illuminate\Http\Response
     */
    public function edit(Wishlist $wishlist)
    {
        // P2.5-PKG-B. AN EMPTY BODY IS STILL AN EXISTENCE ORACLE: binding resolves the row before
        // this method runs and `Wishlist` is absent from `scoped_models`, so the response was an
        // empty 200 for a row in ANY store and 404 only for one existing nowhere.
        // Wishlist rows are CUSTOMER data belonging to a store; the predicate matches the one
        // the list paths already use.
        $storeId = getCurrentStore();
        if (empty($storeId) || empty($wishlist->store_id)
            || (int) $wishlist->store_id !== (int) $storeId) {
            abort(404);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Wishlist  $wishlist
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Wishlist $wishlist)
    {
        // P2.5-PKG-B. AN EMPTY BODY IS STILL AN EXISTENCE ORACLE, and this one survived the hand
        // census for a SECOND structural reason: that regex required the model to be the FIRST
        // parameter, so `(Request $request, Wishlist $x)` was never examined. Found only by
        // `OwnedResourceMethodsAreGuardedTest`, which walks the real route table by reflection.
        //
        // The method is an unimplemented resource stub and stays inert -- the guard changes
        // nothing for a caller who owns the row, and removes the oracle for one who does not.
        $storeId = getCurrentStore();
        if (empty($storeId) || empty($wishlist->store_id)
            || (int) $wishlist->store_id !== (int) $storeId) {
            abort(404);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Wishlist  $wishlist
     * @return \Illuminate\Http\Response
     */
    public function destroy($wish_id)
    {

        if (auth()->user() && auth()->user()->isAbleTo('Delete Wishlist')) {
            $store = Store::find(getCurrentStore());
            $wishlists = Wishlist::where('customer_id', $wish_id)->where('store_id', $store->id)->get();
            foreach ($wishlists as $wishlist) {
                $wishlist->delete();
            }
            return redirect()->back()->with('success', __('Wishlist delete successfully.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function abandon_wish_emailsend(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Abandon Wishlist')) {
            /*
             * P2.5-PKG-C -- F-SEC-09 AGAIN, in the sibling method. `Wishlist` is absent from
             * `config/tenancy.php`'s `scoped_models` ENTIRELY, so unlike `Cart` there is NO global
             * scope to fall back on for anybody: a bare `Wishlist::find()` resolved any tenant's
             * row for an ORDINARY merchant holding `Abandon Wishlist` -- a name
             * `Utility::addNewData()` grants on every login. F-SEC-09 was closed on
             * `WishlistController::show` earlier in this phase and these two were left, which is
             * the same way F-SEC-09 itself survived F-SEC-08: the fix went to the method under
             * review and the siblings that return no HTML were not looked at.
             *
             * The second predicate is not redundant with the first. It is keyed on `customer_id`,
             * and customer ids are reused across tenants in a shared database -- `customers` is
             * store-scoped precisely because the id space repeats -- so a correctly resolved row
             * can still collect another store's wishlist lines for the same id.
             *
             * AND A MISSING ROW NOW REFUSES. `$wish->customer_id` on null was a 500, which reached
             * the merchant as a toast reading `Attempt to read property "customer_id" on null`
             * once this screen's actions grew an error handler. Found exactly that way.
             */
            $storeId = getCurrentStore();
            if (empty($storeId)) {
                return response()->json(['is_success' => false, 'message' => __('Data not found.')], 404);
            }

            $wish = Wishlist::where('id', $request->wish_id)->where('store_id', $storeId)->first();
            if (! $wish) {
                return response()->json(['is_success' => false, 'message' => __('Data not found.')], 404);
            }

            $customer_id = $wish->customer_id;
            $wish_product = Wishlist::where('customer_id', $customer_id)->where('store_id', $storeId)->get();
            $email = $wish->UserData->email ?? null;

            $store = getStoreById($storeId);
            $owner = $store ? User::find($store->created_by) : null;
            $product_id = Crypt::encrypt($wish->product_id);


            try {
                $dArr = Wishlist::where('customer_id', $customer_id)->where('store_id', $storeId)->get();

                $order_id = 1;
                $resp = Utility::sendEmailTemplate('Abandon Wishlist', $email, $dArr, $owner, $store, $product_id, $customer_id);
                // $return = 'Mail send successfully';
                if ($resp['is_success'] == false) {
                    return response()->json(
                        [
                            'is_success' => false,
                            'message' => $resp['error'],
                        ]
                    );
                } else {
                    return response()->json(
                        [
                            'is_success' => true,
                            'message' => 'Mail send successfully',
                        ]
                    );
                }

            } catch (\Exception $e) {
                $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
                return response()->json(
                    [
                        'is_success' => false,
                        'message' => $smtp_error,
                    ]
                );
            }
        } else {
            /*
             * P2.5-PKG-C. THIS ANSWERED A REDIRECT TO AN AJAX POST. `redirect()->back()` is a 302,
             * so jQuery followed it and received the list page's HTML -- the refusal never reached
             * the caller as a refusal, and with no `error` leg on the button it was silent twice
             * over. The screen's new error handler relays `message` for a 4xx, so this is what
             * makes "Permission denied." visible.
             *
             * AN EARLIER DRAFT OF THIS COMMENT SAID the cart twin "already answers 403", citing
             * `CartController::abandon_carts_emailsend`. It did NOT -- only
             * `abandon_carts_messsend` did; the email handler was still redirecting, so the two
             * cart handlers disagreed with each other. Caught by the tenant-authz review of this
             * change, and the cart one is corrected in the same commit, so all four members of
             * the family now answer a refusal that a caller can actually read.
             */
            return response()->json(['is_success' => false, 'message' => __('Permission denied.')], 403);
        }
    }

    public function product_wishlist(Request $request, $storeSlug)
    {
        $slug = $storeSlug;
        $store = getStore($slug);
        if (empty($store)) {
            return response()->json([
                'status' => false,
                'message' => __('Store not found.'),
                'data' => []
            ], 404);
        }

        $customer_id = auth('customers')->user()->id ?? null;

        $request->merge([
            'customer_id' => $customer_id,
        ]);

        // Validation
        $validator = \Validator::make($request->all(), [
            'customer_id' => 'required',
            'product_id' => 'required',
            'wishlist_type' => 'required|in:add,remove',
        ], [
            'customer_id.required' => __('You must be logged in to add items to your wishlist.')
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'data' => []
            ], 422);
        }
        
        $customer = Customer::find($request->customer_id);

        /*
         * P2.5-PKG-G FOLLOW-UP (2) -- THE CUSTOMER MUST BELONG TO THE STORE BEING ACTED ON.
         *
         * The handler picked its tenant from the caller's own URL segment (`getStore($storeSlug)`,
         * :253) and its principal from the session, and then never compared the two. So a
         * logged-in customer of store A could POST to store B's slug and write a `Wishlist` row
         * and an `ActivityLog` row into STORE B under their own store-A customer id.
         *
         * THE `Customer` GLOBAL SCOPE DOES NOT STOP IT. `Customer` is allowlisted in
         * `config/tenancy.php`, but a shopper authenticates on the `customers` guard, which
         * leaves the DEFAULT guard empty -- `StoreScope::resolveTenant()` then resolves nothing
         * and `null_tenant => 'open'` adds NO predicate. Relying on the scope here is the mistake
         * CLAUDE.md names outright: never let the scope be the only tenant check.
         *
         * MEASURED, not inferred. Before this check, a store-1 shopper posting store 2's product
         * to store 2's slug got HTTP 200 and left `wishlists(store_id=2, customer_id=1)` in the
         * table. That probe is what turned this from a review claim into a fact; an advisory note
         * had warned the test would instead pass because of the scope, and it did not.
         *
         * SAME COMPARISON AS `ApiController::resolveStoreCustomer()` (:7828), null branch
         * included: a customer row with a NULL `store_id` is tolerated deliberately, because that
         * helper's docblock records the legacy-row count as a measurement still owed rather than a
         * closed question. Narrowing it here would be a different decision taken quietly.
         *
         * SAME 401 AS THE LINE ABOVE, deliberately. It is the refusal this route already emits and
         * the one the API twin emits for this exact case, so no new status reaches any client --
         * and the storefront JS already renders `responseJSON.message`.
         */
        if (
            !$customer
            || ($customer->store_id !== null && (int) $customer->store_id !== (int) $store->id)
        ) {
            return response()->json([
                'status' => false,
                'message' => __('You must be logged in to add items to your wishlist.'),
                'data' => []
            ], 401);
        }

        /*
         * P2.5-PKG-G FOLLOW-UP -- THE PRODUCT MUST BE THIS STORE'S.
         *
         * This was `Product::find($request->product_id)`. `Product` carries StoreScope but is NOT
         * in `config/tenancy.php`'s `scoped_models`, so `StoreScope::isActiveFor()` short-circuits
         * and NO predicate is added for any principal -- the fact behind the S19 stock drain. An
         * unscoped find() therefore accepted ANY tenant's product id.
         *
         * TWO ROWS CARRIED IT ACROSS THE BOUNDARY: a `Wishlist` row (store A, store B's product)
         * and an `ActivityLog` row whose `remark` holds the foreign id stamped `store_id` = A. The
         * second is the one that is READ BACK -- `ActivityLog::products()` resolved it to store
         * B's product NAME on store A's merchant's customer timeline.
         *
         * REACHABLE BY A LOGGED-IN CUSTOMER OF THIS STORE, not by an anonymous caller. The route
         * (`routes/customer.php:178`) sits in the group at `:49` whose middleware is only
         * `[ActiveTheme, 'themelanguage']`, but the handler takes `customer_id` from
         * `auth('customers')` and MERGES OVER anything posted (`:262-266`), so a missing session
         * is refused by the validator. The precondition is an account on the victim store.
         *
         * SAME PREDICATE AS ITS API TWIN, which already carried it: `ApiController:2071`. This web
         * copy did not, and the two handlers write the same two tables.
         */
        $Product = Product::where('id', $request->product_id)
            ->where('store_id', $store->id)
            ->first();
        if (!$Product) {
            return response()->json([
                'status' => false,
                'message' => __('Product not found.'),
                'data' => []
            ], 404);
        }
        
        if ($request->wishlist_type == 'add') {
            $exists = Wishlist::where('customer_id', $request->customer_id)
                              ->where('product_id', $request->product_id)
                              ->where('store_id', $store->id)
                              ->exists();
    
            if ($exists) {
                return response()->json([
                    'status' => false,
                    'message' => __('Product already added in Wishlist.'),
                    'data' => []
                ], 409);
            }
    
            $Wishlist = new Wishlist();
            $Wishlist->customer_id = $request->customer_id;
            $Wishlist->product_id = $request->product_id;
            $Wishlist->status = 1;
            $Wishlist->store_id = $store->id;
            $Wishlist->save();
    
            // Log activity
            ActivityLog::create([
                'customer_id' => $request->customer_id,
                'log_type' => 'add wishlist',
                'remark' => json_encode(['product' => $request->product_id]),
                'store_id' => $store->id
            ]);
    
            $Wishlist_count = Wishlist::where('customer_id', $request->customer_id)
                                      ->where('store_id', $store->id)->count();
    
            $cart = auth('customers')->check()
                ? Cart::where('customer_id', auth('customers')->id())->where('store_id', $store->id)->count()
                : 0;
    
            return response()->json([
                'status' => true,
                'message' => __($Product->name . ' has been added successfully to your wishlist.'),
                'data' => ['count' => $Wishlist_count],
                'cart' => $cart
            ], 200);
        } 
        elseif ($request->wishlist_type == 'remove') {
            Wishlist::where('customer_id', $request->customer_id)
                    ->where('product_id', $request->product_id)
                    ->where('store_id', $store->id)
                    ->delete();
    
            // Log activity
            ActivityLog::create([
                'customer_id' => $request->customer_id,
                'log_type' => 'delete wishlist',
                'remark' => json_encode(['product' => $request->product_id]),
                'store_id' => $store->id
            ]);
    
            $Wishlist_count = Wishlist::where('customer_id', $request->customer_id)
                                      ->where('store_id', $store->id)->count();
    
            $cart = auth('customers')->check()
                ? Cart::where('customer_id', auth('customers')->id())->where('store_id', $store->id)->count()
                : 0;
    
            return response()->json([
                'status' => true,
                'message' => __($Product->name . ' removed successfully from wishlist.'),
                'data' => ['count' => $Wishlist_count],
                'cart' => $cart
            ], 200);
        }
    
        // Invalid type
        return response()->json([
            'status' => false,
            'message' => __('Invalid wishlist action.'),
            'data' => []
        ], 400);
    }

    public function wishlistCount(Request $request)
    {
        // Initialize $response
        $count = 0;

        if (auth('customers')->guest()) {
            return response()->json([
                'count' => 0,
                'status' => true,
                'message' => __('Wishlist count.'),
            ]);
        } else {
            /*
             * P2.5-PKG-G FOLLOW-UP (2) -- THE COUNT CAME FROM REQUEST INPUT.
             *
             * This was `where('customer_id', $request->customer_id)` with NO store predicate and
             * `Wishlist` in no allowlist, so any logged-in shopper could post anyone's customer id
             * and read that person's platform-wide wishlist size. CLAUDE.md names this shape
             * outright: derive the target from the authenticated principal, never from request
             * input. It is a count-only oracle rather than a content leak, which is why it is
             * small -- not why it is acceptable.
             *
             * AND IT NEEDED A HAND-CRAFTED REQUEST, which is worth stating rather than leaving the
             * severity sounding worse than it is: the shipped storefront never posts the field.
             * `get_wishlist()` (public/assets/js/front-theme.js:1090-1093) sends `_token` and
             * `wishlist` ONLY, so `$request->customer_id` was null on every real call, the count
             * came back 0, and the fallback below recounted with the authenticated id. The UI's
             * effective behaviour is therefore UNCHANGED by deriving the id properly; what changes
             * is that a crafted request can no longer name someone else.
             *
             * THE RIGHT IDENTIFIER WAS ALREADY HERE. The old `if (empty($count) && ...)` fallback
             * recounted with `auth('customers')->id()`, so the handler half-knew: it reached for
             * the authenticated id only when the caller-supplied one happened to return nothing.
             * Deriving it unconditionally makes the fallback redundant, so it goes.
             *
             * THE STORE PREDICATE IS A BEHAVIOUR CHANGE, STATED PLAINLY. A customer with rows in
             * two stores previously saw a PLATFORM-WIDE number in this store's header. Its sibling
             * already disagreed: `product_wishlist()` returns `customer_id + store_id` counts
             * (:356-357), so adding one item made the header jump from the platform total to the
             * store total. They now agree, and the number a merchant's storefront shows is that
             * storefront's.
             *
             * THE SLUG IS NOT BOUND INTO THIS HANDLER -- the route declares `{storeSlug?}` but the
             * signature is `(Request $request)` -- so it is read from the route and the predicate
             * is applied only when a store really resolves. No store resolved means the count is
             * left unscoped rather than silenced: this endpoint feeds a header badge, and blanking
             * it is the storefront-blanking failure this project has already shipped twice.
             */
            $customerId = auth('customers')->id();
            $slug = $request->route('storeSlug');
            $countStore = $slug ? getStore($slug) : null;

            $countQuery = Wishlist::where('customer_id', $customerId);

            if (!empty($countStore)) {
                $countQuery->where('store_id', $countStore->id);
            }

            $count = $countQuery->count();
        }

     
        // Prepare the return array
        $return = [
            'count' => $count,
            'status' => true,
            'message' => __('Wishlist count.'),
        ];

        // Return the response as JSON
        return response()->json($return);
    }


    public function abandonWishlistMsgSend(Request $request)
    {
        /*
         * P2.5-PKG-C. FOUR DEFECTS IN FIVE LINES, and the ORDERING was the one that hid the rest:
         * `Wishlist::find()`, `$cart->customer_id` and `$cart->UserData` all ran BEFORE
         * `isAbleTo()`, so a caller without the permission still drove the lookup and the relation
         * load -- and a missing row answered 500 ahead of the refusal, which is also how a caller
         * holding nothing learns whether a row exists. The cart twin had precisely this corrected
         * under P1-SEC; this one was left. Authorization now runs first.
         *
         * Then the store predicate, because `Wishlist` is absent from `scoped_models` and there is
         * no scope behind it for any caller; and a null guard, because the 500 reached the
         * merchant as a toast reading `Attempt to read property "customer_id" on null`.
         *
         * THE FOURTH IS NOT FIXED HERE, ON PURPOSE: this is a WISHLIST action gating on
         * `Abandon Cart`. Correcting the name changes who may do what -- a role holding
         * `Abandon Cart` without `Abandon Wishlist` can send these today and could not afterwards
         * -- and §8 of the owner directive reserves permission changes. Recorded, not changed, and
         * `AbandonedWishlistSendCrossTenantTest` asserts the permission actually checked rather
         * than the one that ought to be, so this comment cannot quietly become false.
         */
        if (! auth()->user() || ! auth()->user()->isAbleTo('Abandon Cart')) {
            return response()->json(['is_success' => false, 'message' => __('Permission denied.')], 403);
        }

        $storeId = getCurrentStore();
        if (empty($storeId)) {
            return response()->json(['is_success' => false, 'message' => __('Data not found.')], 404);
        }

        $cart = Wishlist::where('id', $request->wish_id)->where('store_id', $storeId)->first();
        if (! $cart) {
            return response()->json(['is_success' => false, 'message' => __('Data not found.')], 404);
        }

        $customer_id = $cart->customer_id;
        $mobile = $cart->UserData;
        try {
            $dArr = Wishlist::where('customer_id', $customer_id)->where('store_id', $storeId)
                ->pluck('product_id')->toArray();

            $product = [];
            foreach ($dArr as $item) {
                $product[] = Product::where('id', $item)->pluck('name')->first();
            }
            $product_name = implode(',', $product);
            $store = getStoreById(getCurrentStore());
            $msg = __("We noticed that you have been browsing our site and have added some fantastic items to your wishlist. Hurry, some of these items are selling out fast. With limited stock and high demand, now is the perfect time to make your dream purchases, Added Product name : $product_name");
            $resp = Utility::SendMsgs('Abandon Cart', $mobile, $msg);

            // $return = 'Mail send successfully';
            if ($resp == false) {
                return response()->json(
                    [
                        'is_success' => false,
                        'message' => __("Invalid Auth access token - Cannot parse access token"),
                    ]
                );
            } else {
                return response()->json(
                    [
                        'is_success' => true,
                        'message' => __('Message send successfully'),
                    ]
                );
            }
        } catch (\Exception $e) {

            $smtp_error = __('Invalid Auth access token - Cannot parse access token');
            return response()->json(
                [
                    'is_success' => false,
                    'message' => $smtp_error,
                ]
            );
        }
    }

    // wishlist start
    public function wishlist(Request $request)
    {
        $slug = !empty($request->route('storeSlug')) ? $request->route('storeSlug') : '';
        $store = getStore($slug);
    
        if (empty($store)) {
            return response()->json([
                'count' => 0,
                'status' => false,
                'html' => null,
                'message' => __('Store not found'),
            ]);
        }
        if (auth('customers')->user()) {
            /*
             * P2.5-PKG-G FOLLOW-UP (2) -- THE LIST IS THIS STORE'S, and this had to change WITH
             * the badge or the two would have started disagreeing.
             *
             * Both queries were `where('customer_id', ...)` with no store predicate, while
             * `$store` was already resolved four lines above and even handed to the view. So a
             * customer who had shopped at two stores saw store A's products inside store B's
             * storefront, with a platform-wide count beside them.
             *
             * WHY IT IS IN THIS CHANGE AND NOT DEFERRED: scoping `wishlistCount()` (the header
             * badge) without scoping this would have left the badge reading 2 and this page
             * reading 3 for the same customer -- an inconsistency introduced by a fix. An advisory
             * review classed this one as hardening rather than a hole, which is fair on its own
             * (the disclosure is to the data's own subject); it stops being optional once the
             * badge beside it is scoped.
             *
             * ONE QUERY, NOT TWO: the count is taken from the collection just fetched. It is the
             * same number by construction, and it removes the possibility of the two predicates
             * drifting apart -- which is precisely how this surface got here.
             */
            $wishlists = Wishlist::where('customer_id', auth('customers')->user()->id)
                ->where('store_id', $store->id)
                ->get();
            $count = $wishlists->count();
    
            $return['html'] = view('front_end.common.wish_list', compact('slug', 'wishlists', 'store'))->render();
            $return['count'] = $count;
            $return['status'] = true;
            $return['message'] = __('Wishlist.');
            return response()->json($return);
        } else {
            $wishlists = collect(); // Create an empty collection instead of an empty array
            $count = 0;
            $return['count'] = 0;
            $return['status'] = true;
            $return['message'] = __('kindly please login  and explore our website.');
            $return['html'] = view('front_end.common.wish_list', compact('slug', 'wishlists', 'store'))->render();
            return response()->json($return);
        }
    }
}
