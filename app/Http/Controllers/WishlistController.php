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
            $wish_id = $wishlist->customer_id;
            $wishlist_product = Wishlist::where('customer_id', $wish_id)->get();

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
        //
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
        //
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
            $wish = Wishlist::find($request->wish_id);
            $customer_id = $wish->customer_id;
            $wish_product = Wishlist::where('customer_id', $customer_id)->get();
            $email = $wish->UserData->email ?? null;

            $store = getStoreById(getCurrentStore());
            $owner = User::find($store->created_by);
            $product_id = Crypt::encrypt($wish->product_id);


            try {
                $dArr = Wishlist::where('customer_id', $customer_id)->get();

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
            return redirect()->back()->with('error', __('Permission denied.'));
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
        $cart = Wishlist::find($request->wish_id);
        $customer_id = $cart->customer_id;
        $mobile = $cart->UserData;
        if (auth()->user() && auth()->user()->isAbleTo('Abandon Cart')) {
            try {
                $dArr = Wishlist::where('customer_id', $customer_id)->pluck('product_id')->toArray();

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
        } else {
             return response()->json(
                [
                    'is_success' => false,
                    'message' => __('Permission denied.'),
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
