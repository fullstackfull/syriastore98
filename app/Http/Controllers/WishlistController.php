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
        if (!$customer) {
            return response()->json([
                'status' => false,
                'message' => __('You must be logged in to add items to your wishlist.'),
                'data' => []
            ], 401);
        }

        $Product = Product::find($request->product_id);
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
            $count = Wishlist::with('ProductData')->where('customer_id', $request->customer_id)->count();
            if(empty($count) && auth('customers')->check()){
                $count = Wishlist::with('ProductData')->where('customer_id', auth('customers')->id())->count();
            }
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
            $wishlists = Wishlist::where('customer_id', auth('customers')->user()->id)->get();
            $count = Wishlist::where('customer_id', auth('customers')->user()->id)->count();
    
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
