<?php

namespace App\Http\Controllers;

use App\Models\Store;

use App\Models\AccountProfile;
use App\Models\AppSetting;
use App\Models\Country;
use App\Models\State;
use App\Models\City;
use App\Models\Category;
use App\Models\Page;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\Product;
use App\Models\Customer;
use App\Models\DeliveryAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Api\ApiController;
use App\Models\Newsletter;
use App\Models\Order;
use App\Models\Utility;
use App\Models\SupportConversion;
use App\Models\SupportTicket;
use App\Mail\ProdcutMail;
use App\Models\OrderRefund;
use App\Models\OrderRefundSetting;
use App\Models\ProductVariant;
use App\Models\Setting;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Crypt;
use Qirolab\Theme\Theme;
use App\Models\OrderNote;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
class AccountProfileController extends Controller
{

    public function index(Request $request)
    {
        if (auth('customers')->user()) {
            $store = getStore($request->route('storeSlug'));            
            $recent_orders = Order::where('customer_id', auth('customers')->user()->id)->take(10)->orderBy('created_at', 'desc')->get();
            return view('front_end.pages.account', compact('store', 'recent_orders'));
        } else {
            return redirect()->back()->with('error', __('kindly please login  and explore our website'));
        }
    }

    public function create()
    {
    }

    /**
     * A SIGNED-IN SHOPPER EDITS THEIR OWN ACCOUNT. Nothing else.
     *
     * THE DEFECT THIS REPLACES was the third instance of one pattern, after
     * Api\ApiController::change_password and Api\DeliveryBoyApiController::resetPassword, and it
     * was the worst of the three because it took the E-MAIL as well as the password. The body was:
     *
     *     $customer = Customer::Where('id', $request->customer_id)->first();   // request input
     *     if ($request->old_password && !Hash::check($request->old_password, $customer->password))
     *         return ... error ...                                            // SKIPPED if absent
     *     $customer->email    = $request->email;
     *     if ($request->new_password) $customer->password = Hash::make($request->new_password);
     *
     * Three separate failures, each sufficient on its own:
     *
     *   1. THE TARGET CAME FROM THE REQUEST. `customer_id` was read straight off the body, with no
     *      store predicate and never compared to auth('customers')->id(). The route carries
     *      `auth:customers` (routes/customer.php:202-204) -- authentication, not authorization --
     *      and the `customers` guard is session-only with no store binding, so a session from any
     *      storefront satisfies it for every storefront.
     *   2. THE OLD-PASSWORD CHECK WAS OPTIONAL. Both the rule at the top and the Hash::check were
     *      guarded by `if ($request->old_password ...)`, so OMITTING the field skipped verification
     *      entirely rather than failing. A rule that is only applied when the attacker supplies the
     *      field it validates is not a rule.
     *   3. $store WAS RESOLVED AND NEVER READ, so the handler was indifferent to which storefront
     *      it was called through.
     *
     * Together: any shopper with any account could POST their own session plus
     * `customer_id=<victim>` and take over any customer in any store -- and because it rewrote the
     * e-mail too, the attacker then owned the address that every recovery flow trusts. The slug is
     * optional in the route, so it could be omitted altogether.
     *
     * A null $customer was also an unguarded dereference, so an unknown id was a 500.
     *
     * COMPATIBILITY. The account form at resources/views/front_end/pages/account.blade.php:162-211
     * posts `customer_id` as a hidden field holding the signed-in customer's OWN id, along with
     * first_name, last_name, email, mobile and the three password fields. It still posts all of
     * them and every legitimate submission still succeeds -- `customer_id` is simply no longer
     * trusted. A submission that changes only the profile fields, with the password boxes left
     * empty, still saves, which is the common case.
     */
    public function store(Request $request)
    {
        // THE PRINCIPAL IS THE AUTHENTICATED SESSION, never the request body.
        $customer = auth('customers')->user();

        if (! $customer instanceof Customer) {
            return redirect()->back()->with('error', __('Please login to continue.'));
        }

        // Defence in depth: the slug was resolved and never read. A shopper reaching this check is
        // only ever editing their own account, so this closes no demonstrated hole -- but a
        // handler that ignores its own tenant parameter is how the next defect gets in.
        $store = getStore($request->route('storeSlug'));

        if (! empty($store) && (int) $customer->store_id !== (int) $store->id) {
            return redirect()->back()->with('error', __('Please login to continue.'));
        }

        $rule = [
            'first_name' => 'required',
            // Scoped to this customer's own store and excluding themselves, matching the rule
            // registration already enforces (Customer\Auth\CustomerLoginController:728-737).
            // Without it a shopper could take over another shopper's address in the same store and
            // break both accounts' login and recovery.
            'email' => [
                'required',
                'email',
                Rule::unique('customers', 'email')
                    ->where(fn ($query) => $query->where('store_id', $customer->store_id))
                    ->ignore($customer->id),
            ],
        ];

        // REQUIRED WHENEVER A NEW PASSWORD IS ASKED FOR, not merely when the caller chose to send
        // it. This is the whole of failure 2 above.
        if ($request->filled('new_password')) {
            $rule['old_password'] = 'required';
            $rule['new_password'] = 'required|confirmed';
        }

        $validator = Validator::make($request->all(), $rule);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        if ($request->filled('new_password')
            && ! Hash::check($request->old_password, (string) $customer->password)) {
            return redirect()->back()->with('error', __("Old Password Does not match!"));
        }

        $customer->first_name   = $request->first_name;
        $customer->last_name    = $request->last_name;
        $customer->email        = $request->email;
        $customer->mobile       = $request->mobile;

        if ($request->filled('new_password')) {
            $customer->password = Hash::make($request->new_password);
        }
        $customer->save();

        return redirect()->back()->with('success', __('Account details saved successfully.'));
    }


    public function profile_update(Request $request, $slug)
    {
        $store = getStore($slug);
        
        $customer = auth('customers')->user();

        if (! $customer instanceof Customer) {
            return redirect()->back()->with('error', __('Please login to continue.'));
        }

        $rule['first_name'] = 'required';
        // Same store-scoped uniqueness as store() above and as registration: without it a shopper
        // can take over another shopper's address in their store and break both accounts' login
        // and recovery. This handler already derived its target from the session, so that was its
        // only gap.
        $rule['email'] = [
            'required',
            'email',
            Rule::unique('customers', 'email')
                ->where(fn ($query) => $query->where('store_id', $customer->store_id))
                ->ignore($customer->id),
        ];

        $validator = \Validator::make($request->all(), $rule);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        $user = $customer;
        $user->first_name   = $request->first_name;
        $user->last_name    = $request->last_name;
        $user->email        = $request->email;
        $user->mobile       = $request->mobile;
        $user->save();

        return redirect()->back()->with('success', __('Contact successfully created.'));
    }

    public function password_change(Request $request, $slug = '')
    {

        $store = getStore($slug);
        
        # Validation
        $rule['old_password'] = 'required';
        $rule['new_password'] = 'required|confirmed';

        $validator = \Validator::make($request->all(), $rule);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        if (!empty($request->type) && ($request->type != 'admin' || $request->type != 'superadmin')) {
            #Match The Old Password
            if (!Hash::check($request->old_password, auth('customers')->user()->password)) {
                return redirect()->back()->with('error', __("Old Password Does not match!"));
            }

            #Update the new Password
            Customer::whereId(auth('customers')->user()->id)->update([
                'password' => Hash::make($request->new_password)
            ]);
        } else {
            #Match The Old Password
            if (!Hash::check($request->old_password, auth()->user()->password)) {
                return redirect()->back()->with('error', __("Old Password Does not match!"));
            }

            #Update the new Password
            User::whereId(auth()->user()->id)->update([
                'password' => Hash::make($request->new_password)
            ]);
        }


        return redirect()->back()->with('success', __('Password update succefully.'));
    }

    public function states_list(Request $request, $slug)
    {
        $store = getStore($slug);
       
        $country_id = $request->country_id;

        $state_list = State::orderBy('name','ASC')->where('country_id', $country_id)->pluck('name', 'id')->prepend(__('Select State'), '')->toArray();

        return response()->json($state_list);
    }


public function city_list(Request $request, $slug)
{



    $store = getStore($slug);
    $secret = User::find($store->created_by);

    // 1. محاولة جلب البيانات من الـ API الخارجي إذا كان التوكن موجوداً
    if ($secret && $secret->deliverysyria_secret && $request->shipping_label == 'Delivery Syria') {
        try {
            $response = Http::post('https://deliverysyria.com/api/secret/state', [
                'secret' => $secret->deliverysyria_secret,
                'state'  => $request->state_id, // القيمة القادمة من الفيو
            ]);

            // إذا نجح الاتصال بالـ API
            if ($response->successful()) {
                // جلب الداتا المرجعة كـ Array (مثل {"8": "damascusetest"})
                $remoteCities = $response->json();

                // دمج الخيار الافتراضي "Select City" مع القائمة المرجعة
                $city_list = ['' => __('Select City')] + $remoteCities;

                return response()->json($city_list);
            }
        } catch (\Exception $e) {
            // في حال حدوث خطأ بالاتصال، يمكنك تسجيل الخطأ أو التجاوز لجلب المدن من القاعدة المحلية
            \Log::error('DeliverySyria API Connection Error: ' . $e->getMessage());
        }
    }

    // 2. Fallback (الخيار البديل): في حال فشل الـ API أو عدم وجود secret، نجلب من القاعدة المحلية
    $state_id = $request->state_id;
    
    $city_list = City::where('state_id', $state_id)
                     ->orderBy('name', 'ASC')
                     ->pluck('name', 'id')
                     ->prepend(__('Select City'), '')
                     ->toArray();

    return response()->json($city_list);
}


    public function city_list00(Request $request, $slug)
    {



        $store = getStore($slug);
       
        $state_id = $request->state_id;
        $city_list = City::where('state_id', $state_id)->orderBy('name','ASC')->pluck('name', 'id')->prepend(__('Select City'), '')->toArray();
        return response()->json($city_list);
    }

    // Addressbook start
    public function add_address(Request $request, $slug)
    {
        $store = getStore($slug);
       
        $request->request->add(['store_id' => $store->id, 'slug' => $slug]);
        $api = new ApiController();
        $request['customer_id'] = auth('customers')->user()->id ?? null;
        $data = $api->add_address($request, $slug);
        $response = $data->getData();
        if ($response->status == 1) {
            return redirect()->back()->with('success', $response->data->message);
        } else {
            return redirect()->back()->with('error', $response->data->message);
        }
    }

    public function address(Request $request, $slug)
    {
        // if (auth('customers')->user()) {
            $store = getStore($slug);

            if (auth('customers')->user()) {
                $addresses = DeliveryAddress::where('customer_id', auth('customers')->user()->id)->orderBy('id', 'desc')->get();
            } else {
                $addresses = collect();
            }

            return view('front_end.pages.address', compact('store', 'addresses'));
        // } else {
        //     return redirect()->back()->with('error', __('kindly please login  and explore our website'));
        // }
    }

    public function addressForm(Request $request)
    {
        $store = getStore($request->route('storeSlug'));
        $address = null;
        $id = $request->route('id');
        
        $countries = Country::get();
        if ($id) {
            $address = $this->ownedAddress($store, $this->shopper($store), $id);
            if (! $address) {
                return $this->notFoundJson(__('Address not found'));
            }
        }

        $return['html'] = view('front_end.pages.address-form', compact('store', 'address', 'countries'))->render();
        return response()->json($return);
    }

    public function saveAddress(Request $request)
    {
        if (!$request->has('is_default')) {
            $validator = \Validator::make($request->all(), [
                'address_type' => 'required',
                'full_name' => 'required',
                'phone' => 'required',
                'address' => 'required',
                'city_id' => 'required',
                'state_id' => 'required',
                'postcode' => 'required',
                'country_id' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => $validator->errors()->first()
                ]);
            }
        }

        // P1-SEC-12: the owner and the store come from the session and the route, never from the post
        $store = getStore($request->route('storeSlug'));
        $customer = $this->shopper($store);
        if (! $customer) {
            return response()->json(['status' => false, 'message' => __('You must be logged in to do that.')], 403);
        }

        try {
            $data = $request->all();
            $data['customer_id'] = $customer->id;   // the owner and the store are assigned AFTER the post is read: nothing posted can name them
            $data['store_id'] = $store->id;
            $data['default_address'] = $request->has('default_address') ? 1 : 0;

            // If setting as default, update all other addresses to not default
            if ($data['default_address'] == 1) {
                DeliveryAddress::where('customer_id', $data['customer_id'])
                    ->where('id', '!=', $request->id)
                    ->update(['default_address' => 0]);
            }

            if ($request->has('id')) {
                // Update existing address
                $address = $this->ownedAddress($store, $customer, $request->id);
                
                if (!$address) {
                    return response()->json([
                        'status' => false,
                        'message' => __('Address not found')
                    ]);
                }

                $address->update($data);
                $message = __('Address updated successfully');
                $address_id = $address->id;
            } else {
                // Create new address
                $address = DeliveryAddress::create($data);
                $message = __('Address added successfully');
                $address_id = $address->id;
            }

            return response()->json([
                'status' => true,
                'message' => $message,
                'address_id' => $address_id
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => __('Something went wrong!')
            ]);
        }
    }
    
    public function remove_address(Request $request)
    {
        $store = getStore($request->route('storeSlug'));
        $address = $this->ownedAddress($store, $this->shopper($store), $request->id);
        if (! $address) {
            return $this->notFoundJson(__('Address not found'));
        }
        $address->delete();

         return response()->json([
            'status' => true,
            'message' =>__('Address removed successfully.')
        ]);
    }

    public function get_addressbook_data(Request $request, $slug)
    {
        $store = getStore($slug);
        if (!$store) {
            $return['html_data'] = null;
            $return['message'] = __('Store not found.');
            return response()->json($return);
        }
        
        $country_option = Country::orderBy('name','ASC')->pluck('name', 'id');
        $DeliveryAddress = $this->ownedAddress($store, $this->shopper($store), $request->get('id'));
        $return['html'] = '';
        if (empty($DeliveryAddress)) {
            $DeliveryAddress = [];
            $state_option = State::where('country_id',1)->orderBy('name','ASC')->pluck('name', 'id');
            $city_option = City::where('country_id',1)->orderBy('name','ASC')->pluck('name', 'id');
        } else {
            $state_option = State::where('country_id',$DeliveryAddress->country_id)->orderBy('name','ASC')->pluck('name', 'id');
            $city_option = City::where('state_id',$DeliveryAddress->state_id)->where('country_id',$DeliveryAddress->country_id)->orderBy('name','ASC')->pluck('name', 'id');
            
            $DeliveryAddress->country = $DeliveryAddress->country_id;
            $DeliveryAddress->state = $DeliveryAddress->state_id;
            $DeliveryAddress->city = $DeliveryAddress->city_id;


            $return['html'] = view('front_end.sections.addressbook_edit', compact('slug', 'DeliveryAddress', 'country_option','state_option','city_option'))->render();
        }

        $return['addressbook_checkout_edit'] = view('front_end.sections.addressbook_checkout_edit', compact('DeliveryAddress', 'country_option','state_option','city_option'))->render();
        $return['form_title'] = '<h2>' . __('Edit address') . '</h2>';
        return response()->json($return);
    }

    public function update_addressbook_data(Request $request,$slug, $id)
    {
        $store = getStore($slug);
        
        $request->merge(['address_id' => $id, 'slug' => $slug, 'store_id' => $store->id]);
        $api = new ApiController();
        $data = $api->update_address($request, $slug);
        $response = $data->getData();
        if ($response->status == 1) {
            return redirect()->back()->with('success', $response->data->message);
        } else {
            return redirect()->back()->with('error', $response->data->message);
        }
    }

    public function delete_addressbook(Request $request, $slug)
    {
        $store = getStore($slug);
        $this->ownedAddress($store, $this->shopper($store), $request->id)?->delete();
    }
    // Addressbook end

    // Newsletter start
    public function add_newsletter(Request $request)
    {
        $validator = \Validator::make(
            $request->all(),
            [
                'email' => ['required', 'unique:newsletters'],
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            $return['status'] = 'error';
            $return['message'] = $messages->first();
            return response()->json($return);
        }

        $newsletter                 = new Newsletter();
        $newsletter->email         = $request->email;
        if (auth()->user()) {
            $newsletter->user_id         = auth('customers')->user()->id;
        } else {
            $newsletter->user_id         = '0';
        }
        $newsletter->save();

        $return['status'] = 'success';
        $return['message'] = __('Newsletter successfully subscribe.');
        return response()->json($return);
    }
    // Newsletter end

    // order list start
    public function order_list(Request $request, $slug)
    {
        // if (auth('customers')->user()) {
            $store = getStore($slug);
            $filter_order = $request->get('filter_order') !== 'undefined' ?  $request->get('filter_order') : 'all';
			if (auth('customers')->user()) {
                $orders = Order::with(['refund'])->where('customer_id', auth('customers')->user()->id)->orderBy('id', 'desc')->where('store_id', $store->id)->paginate(10);
			} else {
				$orders = collect(); // Create an empty collection instead of an empty array
			}

            return view('front_end.pages.order', compact('orders','store', 'filter_order'));
        // } else {
        //     return redirect()->back();
        // }
    }
    // order list end

    public function order_page_filter(Request $request, $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();
        $store_id = $store->id;;

        $page = $request->page ?? 1;

        // Clean parameters
        $filter_value = $request->filter_order !== 'undefined' ? $request->filter_order : 'all';

        $orders_query = Order::with(['refund'])->where('customer_id', auth('customers')->user()->id)->orderBy('id', 'desc')->where('store_id', $store->id);

        if (!empty($filter_value)) {
            switch ($filter_value) {
                case 'processing':
                    $orders_query->where('delivered_status', 0);
                    break;
                case 'delivered':
                    $orders_query->where('delivered_status', 1);
                    break;
                case 'cancelled':
                    $orders_query->where('delivered_status', 2);
                    break;
                case 'return':
                    $orders_query->where('delivered_status', 3);
                    break;
                case 'confirmed':
                    $orders_query->where('delivered_status', 4);
                    break;
                case 'picked':
                    $orders_query->where('delivered_status', 5);
                    break;
                case 'shipped':
                    $orders_query->where('delivered_status', 6);
                    break;
                case 'partiallyPaid':
                    $orders_query->where('delivered_status', 7);
                    break;
                case 'preOrder':
                    $orders_query->where('delivered_status', 8);
                    break;
            }
        }
        $orders = $orders_query->paginate(5);

        $data['html'] = view('front_end.pages.order_list_filter', compact(
            'storeSlug',
            'orders',
        ))->render();
        return $data;
    }

    // reward list start
    public function reward_list(Request $request, $slug)
    {
        // if (auth('customers')->user()) {
            $store = getStore($slug);
			if (auth('customers')->user()) {
				 $orders = Order::where('customer_id', auth('customers')->user()->id)->where('store_id',$store->id)->orderBy('id', 'desc')->paginate(10);
			} else {
				$orders = collect(); // Create an empty collection instead of an empty array
			}

            $return['html'] = view('front_end.sections.reward_list', compact('orders'))->render();
            return response()->json($return);
        // } else {
        //     return redirect()->back()->with('error', __('kindly please login  and explore our website'));
        // }
    }
    // reward list end

    // order return list start
    public function order_return_list(Request $request, $slug)
    {
        // if (auth('customers')->user()) {
            $store = getStore($slug);

            if (! $store) {
                abort(404);
            }

            // P1-ORD-07. This listed EVERY refund request in the store -- the store predicate was
            // there, a CUSTOMER predicate was not, so one shopper saw every other shopper's refund
            // reasons, statuses and order numbers. The shopper's own order ids are resolved in a
            // separate query rather than as a subquery: Order is Cachable, and a whereIn() over a
            // Cachable model's builder has already 500'd once in this codebase.
            $ownOrderIds = [];
            $customer = auth('customers')->user();

            if ($customer) {
                $ownOrderIds = Order::where('store_id', $store->id)
                    ->where('customer_id', $customer->id)
                    ->pluck('id')
                    ->all();
            }

            $order_refunds = OrderRefund::where('store_id', $store->id)
                ->whereIn('order_id', $ownOrderIds)
                ->paginate(10);


            $orders = collect();

            $return['html'] = view('front_end.sections.order_return_list', compact('orders', 'order_refunds', 'store'))->render();
            return response()->json($return);
        // } else {
        //     return redirect()->back()->with('error', __('kindly please login  and explore our website'));
        // }
    }
    

    public function delete_wishlist(Request $request, $slug)
    {
        Wishlist::where('id', $request->id)->delete();
    }
    // wishlist end

    //support-ticket start
    public function support_ticket(Request $request, $slug)
    {
        $store = getStore($slug);
        if (auth('customers')->user()) {
            $tickets = SupportTicket::where('customer_id', auth('customers')->user()->id)->orderBy('id', 'desc')->paginate(10);
        } else {
             $tickets = collect();
        }
        return view('front_end.pages.support_ticket', compact('store', 'slug', 'tickets'));
        // $return['html'] = view('front_end.pages.support_ticket', compact('slug', 'tickets'))->render();
        // return response()->json($return);
    }

    public function add_support_ticket(Request $request, $slug)
    {
        $store = getStore($slug);
        $order_id = 0;
        if(isset($request->order_id) && !empty($request->order_id)){
            $order_id = $request->order_id;
        }
        $orders = Order::where('customer_id', auth('customers')->user()->id)->pluck('product_order_id', 'id');

        $return['html'] = view('front_end.pages.add_tickets', compact('orders', 'slug', 'order_id'))->render();
        return response()->json($return);
    }

    public function support_ticket_store(Request $request, $slug)
    {
        $store = getStore($slug);

        $validator = \Validator::make(
            $request->all(),
            [
                'title' => 'required',
                'description' => 'required',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }
        $customer = $this->shopper($store);
        if (! $customer) {
            return redirect()->back()->with('error', __('You must be logged in to do that.'));
        }
        // P1-SEC-12: a ticket may name only one of THIS shopper's orders in THIS store (the order number is rendered back on the ticket page)
        if (! empty($request->order_id) && ! $this->ownedOrder($store, $customer, $request->order_id)) {
            return redirect()->back()->with('error', __('Order not found.'));
        }
        $tickets                 = new SupportTicket();
        $tickets->title          = $request->title;
        $tickets->order_id          = $request->order_id;
        $tickets->ticket_id = time();
        $tickets->description    = $request->description;
        $tickets->status        = 'open';
        $tickets->customer_id       = $customer->id;
        $tickets->store_id      = $store->id;
        $tickets->created_by    = $customer->id;
        $tickets->save();

        $data              = [];
        if($request->hasfile('attachments'))
        {
            $errors=[];
                 foreach($request->file('attachments') as $filekey => $file)
                {
                    $file_size = $file->getSize();
                    $result = Utility::updateStorageLimit(auth('customers')->user()->creatorId(), $file_size);
                    if($result==1)
                    {
                        $imageName = $file->getClientOriginalName();
                        $dir        = 'uploads/' . $store->id .'/tickets';
                        $path = Utility::keyWiseUpload_file($request,'attachments',$imageName,$dir,$filekey,[]);

                        if($path['flag'] == 1){
                            $data[] = $path['url'];

                        }
                        else{
                            $errors = __($path['msg']);
                        }
                    }
                    else
                    {
                        return redirect()->back()->with('error', $result);
                    }
                }


                $file   = 'tickets/' . $imageName;
                $tickets->attachment    =  json_encode($data);
                $tickets->save();

        }

        if ($request->attachment) {
            $fileName = rand(10, 100) . '_' . time() . "_" . $request->attachment->getClientOriginalName();
            $path = Utility::upload_file($request, 'attachment', $fileName, $dir, []);
            if ($path['flag'] == '0') {
                return redirect()->back()->with('error', $path['msg']);
            }
            $tickets->attachment    = $path['url'];
            $tickets->save();
        }

        return redirect()->back()->with('success', __('Ticket successfully created.'));
    }

    public function edit_support_ticket(SupportTicket $tickets, $slug, $id)
    {
        $store = getStore($slug);
        $tickets = $this->ownedTicket($store, $this->shopper($store), $id);
        if (! $tickets) {
            return $this->notFoundJson(__('Ticket not found'));
        }
        $orders = Order::where('customer_id', auth('customers')->user()->id)->pluck('product_order_id', 'id');
        $return['html'] = view('front_end.pages.support_ticket_edit', compact('tickets', 'slug', 'orders'))->render();
        return response()->json($return);
    }

    public function update_support_ticket(Request $request, $slug, $id)
    {

        $store = getStore($slug);

        $validator = \Validator::make(
            $request->all(),
            [
                'title' => 'required',
                'description' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }
        $customer = $this->shopper($store);
        $ticket = $this->ownedTicket($store, $customer, $id);
        if (! $ticket) {
            return redirect()->back()->with('error', __('Ticket not found'));
        }
        if (! empty($request->order_id) && ! $this->ownedOrder($store, $customer, $request->order_id)) {
            return redirect()->back()->with('error', __('Order not found.'));
        }
        if ($request->attachment) {
            $dir        = 'uploads/' . getCurrentStore() . '/tickets';
            $fileName = rand(10, 100) . '_' . time() . "_" . $request->attachment->getClientOriginalName();
            $path = Utility::upload_file($request, 'attachment', $fileName, $dir, []);
            if ($path['flag'] == '0') {
                return redirect()->back()->with('error', $path['msg']);
            }
            $ticket->attachment      = $path['url'];
        }

        // the existing attachments are kept: `$data` started as [] and the save below wrote json_encode($data), so editing a ticket's title wiped them
        $data              = json_decode((string) $ticket->attachment, true);
        $data              = is_array($data) ? $data : [];
        if ($request->hasfile('attachments')) {

            foreach ($request->file('attachments') as $filekey => $file) {
                $file_size = $file->getSize();
                $result = Utility::updateStorageLimit(auth('customers')->user()->creatorId(), $file_size);
                if ($result == 1) {
                    $imageName = $file->getClientOriginalName();
                    $dir        = 'uploads/' . getCurrentStore() . '/tickets';
                    $path = Utility::keyWiseUpload_file($request, 'attachments', $imageName, $dir, $filekey, []);
                    if ($path['flag'] == 1) {
                        $data[] = $path['url'];
                    } else {
                        $errors = __($path['msg']);
                    }
                } else {
                    return redirect()->back()->with('error', $result);
                }
                $file   = 'tickets/' . $imageName;
            }
        }
        $ticket->attachment      = json_encode($data);
        $ticket->title     = $request->title;
        $ticket->order_id     = $request->order_id;
        $ticket->description   = $request->description;
        // no `customer_id` write: the owner of a ticket is never taken from the post (it re-parented the ticket to any customer id)
        $ticket->save();

        return redirect()->back()->with('success', __('Ticket successfully updated.'));
    }

    public function destroy_support_ticket(Request $request, $slug, $id)
    {
        $store = getStore($slug);
        $ticket = $this->ownedTicket($store, $this->shopper($store), $id);
        if (! $ticket) {
            return redirect()->back()->with('error', __('Ticket not found'));
        }
        $ticket->delete();

        return redirect()->back()->with('error', __('Ticket successfully deleted.'));
    }

    public function attachmentDestroy($slug, $ticket_id, $id)
    {

        $store = getStore($slug);
        $customer = $this->shopper($store);
        $ticket = $this->ownedTicket($store, $customer, $ticket_id);
        if (! $ticket) {
            return redirect()->back()->with('error', __('Ticket not found'));
        }
        $attachments = json_decode($ticket->attachment);
        if (isset($attachments[$id])) {
            $file_path = $attachments[$id];
            // the shopper's session is the `customers` guard: auth()->user() is the merchant guard and is null here (it crashed)
            $result = Utility::changeStorageLimit($customer->creatorId(), $file_path);
            $file_path = '/tickets/' . $ticket->ticket_id . '/' . $attachments[$id];
            unset($attachments[$id]);
            $ticket->attachment = json_encode(array_values($attachments));

            $ticket->save();

            return redirect()->back()->with('success', __('Attachment deleted successfully'));
        } else {
            return redirect()->back()->with('error', __('Attachment is missing'));
        }
    }

    public function reply_support_ticket(Request $request, $slug, $id)
    {
        $store = getStore($slug);
        $ticket = $this->ownedTicket($store, $this->shopper($store), $id);
        if ($ticket) {
            $return['html'] = view('front_end.pages.reply_support_ticket', compact('ticket', 'slug'))->render();
            return response()->json($return);
        } else {
            return redirect()->back()->with('error', __('Some thing is wrong'));
        }
    }

    public function ticket_reply(Request $request, $slug, $id)
    {
        $store = getStore($slug);
        if (!$store) {
            return redirect()->back()->with('error', __('Something went wrong!'));
        }
        $customer = $this->shopper($store);
        $ticket = $this->ownedTicket($store, $customer, $id);
        if (!$ticket) {
            return redirect()->back()->with('error', __('Something went wrong!'));
        }
        $user = auth()->user();
        if ($ticket) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'reply_description' => 'required',
                ]
            );

            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            $post                = [];
            $post['sender']      = 'user';
            $post['ticket_id']   = $ticket->id;
            $post['description'] = $request->reply_description;
            $data                = [];
            if ($request->hasfile('reply_attachments')) {


                foreach ($request->file('reply_attachments') as $filekey => $file) {
                    $imageName = $file->getClientOriginalName();
                    $dir    = 'uploads/' . getCurrentStore() . '/reply_tickets';
                    $path = Utility::keyWiseUpload_file($request, 'reply_attachments', $imageName, $dir, $filekey, []);
                    if ($path['flag'] == 1) {
                        $data[] = $path['url'];
                    } elseif ($path['flag'] == 0) {
                        $errors = __($path['msg']);
                    }
                }
            }
            $post['attachments'] = json_encode($data);
            $post['customer_id'] = $customer->id;
            $post['store_id'] = $store->id;
            $conversion          = SupportConversion::create($post);
            $ticket->status = 'In Progress';
            $ticket->update();

            return redirect()->back()->with('success', __('Reply added successfully') . ((isset($error_msg)) ? '<br> <span class="text-danger">' . $error_msg . '</span>' : '') . ((isset($result) && $result != 1) ? '<br> <span class="text-danger">' . $result . '</span>' : ''));
        } else {
            return redirect()->back()->with('error', __('Something is wrong'));
        }
    }

    public function customerorder($slug, $order_id)
    {
        $id = Crypt::decrypt($order_id);
        $store = getStore($slug);
        if (empty($store)) {
            return redirect()->back()->with('error', __('Store not available'));
        }
        $order = Order::order_detail($id);
        $order_note = OrderNote::where('order_id', $id)
        ->where('note_type', 'to_customer')
        ->get();
        return view('front_end.sections.order_view', compact('slug', 'store', 'order','order_note'));
    }

    public function downloadable_prodcut(Request $request, $slug)
    {
        $store = getStore($slug);
        if (empty($store)) {
            return response()->json(
                [
                    'status' => __('error'),
                    'message' => __('Page Not Found.'),
                ]
            );
        }
        // P1-ORD-10. The order is the signed-in shopper's own, in THIS store (id + store + customer, the predicate every handler of this
        // group uses -- ownedOrder()). It used to be a raw request integer: a shopper of any store could name any store's order and have
        // that store's SMTP relay mail the order's customer a link the shopper chose. The refusal keeps the handler's own error shape.
        $customer = $this->shopper($store);
        $o_data = $this->ownedOrder($store, $customer, $request->order_id);

        // The link is derived from the ORDER'S OWN product rows, never from the request.
        $downloadLink = $o_data === null ? null : $this->downloadLinkFor($o_data);

        if ($o_data === null || $downloadLink === null) {
            return response()->json(
                [
                    'status' => __('error'),
                    'msg' => __('Please contact your shop owner'),
                    'message' => $o_data === null ? __('Order Not Found.') : __('There is nothing to download on this order.'),
                ]
            );
        }

        $order = Order::order_detail($o_data->id);

        $settings = Setting::where('store_id', $o_data->store_id)->pluck('value', 'name')->toArray();
        if ($o_data->delivered_status == 1) {

            if (isset($settings['MAIL_DRIVER']) && !empty($settings['MAIL_DRIVER'])) {
                try {
                    config(
                        [
                            'mail.driver' => $settings['MAIL_DRIVER'],
                            'mail.host' => $settings['MAIL_HOST'],
                            'mail.port' => $settings['MAIL_PORT'],
                            'mail.encryption' => $settings['MAIL_ENCRYPTION'],
                            'mail.username' => $settings['MAIL_USERNAME'],
                            'mail.password' => $settings['MAIL_PASSWORD'],
                            'mail.from.address' => $settings['MAIL_FROM_ADDRESS'],
                            'mail.from.name' => $settings['MAIL_FROM_NAME'],
                        ]
                    );


                    Mail::to(
                        [
                            $order['billing_informations']['email'],
                        ]
                    )->send(new ProdcutMail($order, $downloadLink, $store));

                    return response()->json(
                        [
                            'status' => __('success'),
                            'msg' => __('Please check your email'),
                            'message' => __('successfully send'),
                        ]
                    );
                } catch (\Exception $e) {
                    return response()->json(
                        [
                            'status' => __('error'),
                            'msg' => __('Please contact your shop owner'),
                            'message' => __('E-Mail has been not sent due to SMTP configuration'),
                        ]
                    );
                }
            } else {
                return response()->json(
                    [
                        'status' => __('error'),
                        'msg' => __('Please contact your shop owner'),
                        'message' => __('E-Mail has been not sent due to SMTP configuration'),
                    ]
                );
            }
        }
    }

    /**
     * The download link for an order, from its own product rows: the first line whose variant (or, failing that, product) of THIS store carries
     * a `downloadable_product` file. Null when nothing on the order is downloadable. P1-ORD-10.
     */
    private function downloadLinkFor(Order $order): ?string
    {
        $lines = json_decode((string) $order->product_json, true);

        foreach (is_array($lines) ? $lines : [] as $line) {
            if (! is_array($line) || empty($line['product_id'])) {
                continue;
            }

            $path = null;

            if (! empty($line['variant_id'])) {
                $path = \App\Models\ProductVariant::where('id', $line['variant_id'])->where('product_id', $line['product_id'])
                    ->where('store_id', $order->store_id)->value('downloadable_product');
            }

            if (empty($path)) {
                $path = Product::where('id', $line['product_id'])->where('store_id', $order->store_id)->value('downloadable_product');
            }

            if (! empty($path)) {
                return get_file($path);
            }
        }

        return null;
    }

    /**
     * The order this shopper may act on: this id, in THIS store, owned by THIS customer -- the
     * three-column predicate OrderController::status_cancel() already uses on this route group.
     *
     * P1-ORD-07. `EnsureCustomerAuthenticated` asks only whether SOME customer is signed in, and
     * registration is free, so on its own it is not an ownership check. `Order` is in no
     * `scoped_models` list, so `Order::find($id)` reaches every tenant for every principal.
     */
    private function ownedOrder($store, $customer, $orderId): ?Order
    {
        if (! $store || ! $customer || ! is_scalar($orderId)) {
            return null;
        }

        return Order::where('id', $orderId)
            ->where('store_id', $store->id)
            ->where('customer_id', $customer->id)
            ->first();
    }

    /**
     * The signed-in shopper of THIS store, or null. Mirrors ApiController::resolveStoreCustomer(): the `customers` guard only (a merchant or
     * driver session leaves it null), and a customer bound to another store is not this store's shopper.
     *
     * P1-SEC-12. `EnsureCustomerAuthenticated` asks only whether SOME customer is signed in, and registration is free.
     */
    private function shopper($store): ?\App\Models\Customer
    {
        $customer = auth('customers')->user();

        if (! $customer instanceof \App\Models\Customer || ! $store) {
            return null;
        }

        if ($customer->store_id !== null && (int) $customer->store_id !== (int) $store->id) {
            return null;
        }

        return $customer;
    }

    /** The address this shopper may act on: this id AND this customer (and this store where the row records one). P1-SEC-12. */
    private function ownedAddress($store, $customer, $id): ?DeliveryAddress
    {
        if (! $store || ! $customer || ! is_scalar($id)) {
            return null;
        }

        return DeliveryAddress::withoutGlobalScopes()->where('id', $id)
            ->where('customer_id', $customer->id)
            ->where(fn ($q) => $q->where('store_id', $store->id)->orWhereNull('store_id'))
            ->first();
    }

    /** The support ticket this shopper may act on: this id AND this customer (and this store where the row records one). P1-SEC-12. */
    private function ownedTicket($store, $customer, $id): ?SupportTicket
    {
        if (! $store || ! $customer || ! is_scalar($id)) {
            return null;
        }

        // withoutGlobalScopes: the isolation is THESE predicates, not the StoreScope (which is absent wherever no tenant resolves)
        return SupportTicket::withoutGlobalScopes()->where('id', $id)
            ->where('customer_id', $customer->id)
            ->where(fn ($q) => $q->where('store_id', $store->id)->orWhereNull('store_id'))
            ->first();
    }

    private function notFoundJson(string $message)
    {
        // 403/404, never 401: the shipped clients log the user out on a 401
        return response()->json(['status' => false, 'message' => $message], 404);
    }

    public function order_refund(Request $request, $slug, $id)
    {
        $order_refunds = null;
        $store = getStore($slug);

        if (! $store) {
            abort(404);
        }

        if(isset($request->refund) && $request->refund == true)
        {
            // Guarded by OrderDocumentAccessGuard::TARGETS (`{id}` IS an order id on this branch),
            // proven by OrderDocumentAccessTest. Deliberately not repeated here: a second copy of a
            // control that cannot be isolated by a test is only a second place to drift.
            $order = Order::order_detail($id);
        }
        else
        {
            // `{id}` is a REFUND id on this branch, and the guard resolves it as `orders.id`
            // whatever the query string says -- so it asks "does order #<refundId> belong to me?",
            // which an attacker answers by choosing a refund id equal to an order they own.
            // OrderRefund carries a store scope and no customer scope, so the owner is derived
            // here, from the refund's own order. A foreign, unknown and unowned refund answer
            // identically (404): this branch used to dereference null for an unknown id.
            $customer = auth('customers')->user();

            $order_refunds = OrderRefund::where('id', $id)
                ->where('store_id', $store->id)
                ->first();

            if (! $order_refunds || ! $this->ownedOrder($store, $customer, $order_refunds->order_id)) {
                abort(404);
            }

            $order = Order::order_detail($order_refunds->order_id);
        }
        $pages = Page::where('store_id', $store->id)->get();
        $RefundStatus = OrderRefundSetting::where('store_id',$store->id)
                    ->pluck('is_active', 'name')->toArray();
        $refund_order = OrderRefund::RefundReason();
        $return['html'] = view('front_end.pages.refund_order', compact('order', 'store', 'order_refunds', 'pages', 'RefundStatus', 'refund_order'))->render();
        return response()->json($return);
    }


    public function order_refund_request(Request $request, $slug, $id)
    {
        $validator = \Validator::make(
            $request->all(),
            [
                'product_refund_id' => 'required',
                'order_id' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }
        // Every list this handler indexes is cast ONCE, here, and only the casts are used below.
        // The first version validated the casts and then wrote from `$request->quantity[...]`,
        // which is null when the field is omitted and a string when it is scalar: a TypeError or an
        // "Undefined array key" raised AFTER the order had been saved.
        $productRefundIds = (array) $request->product_refund_id;
        $quantities = (array) $request->quantity;
        $returnPrices = (array) $request->return_price;

        // P1-ORD-07. THE ORDER WRITTEN IS THE ORDER RECORDED IS THE ORDER THIS SHOPPER OWNS.
        //
        // This handler took `order_id` from the BODY, loaded it with `Order::find()` on a model no
        // global scope filters, and rewrote final_price / product_price / product_json -- while the
        // refund RECORD below was written against the PATH `$id`. MEASURED (P1-SEC-03 round 2, and
        // again by this task's own RED run): a store-A shopper naming a store-B order in the body
        // took it from 440 to 140, and the victim merchant had no refund row to explain the drop.
        //
        // So: the path id is the ONLY order this request may name, the body must agree with it
        // (the real form posts the same value in both), and the order must belong to THIS store AND
        // THIS shopper. Everything else answers 404, uniformly, as status_cancel does.
        $store = getStore($slug);
        $customer = auth('customers')->user();

        if (! $store) {
            abort(404);
        }

        if (! $customer) {
            abort(403);
        }

        // is_scalar() first: `order_id[]=5` satisfies `required` and then stringifies to a 500.
        if (! is_scalar($request->order_id) || (string) $request->order_id !== (string) $id) {
            abort(404);
        }

        $order = $this->ownedOrder($store, $customer, $id);

        if (! $order) {
            abort(404);
        }

        /*
         * OWNER DECISION D3 -- ELIGIBILITY, ENFORCED HERE AND NOT ONLY IN BLADE. P1-ORD-11.
         *
         * D3: "Prevent duplicated refunds and stock restoration." Until this block the POST
         * enforced NONE of what the storefront enforces when it decides whether to OFFER a refund.
         * `resources/views/front_end/pages/order_list_filter.blade.php:173-198` shows the *Order
         * Refund* button only when `delivered_status == 1` AND the order has no refund at all;
         * where one exists it renders that refund's status and no button, `Cancel` included. So the
         * product's workflow is ONE REFUND PER DELIVERED ORDER, ever -- and the POST accepted a
         * request on a Pending, a Cancelled and a Confirmed order, and wrote a SECOND
         * `order_refunds` row on a repeat.
         *
         * THE DATA MODEL AGREES WITH THE BLADE, which is what makes this a correctness fix rather
         * than a policy choice imposed from outside: `Order::refund()` is a **hasOne**
         * (app/Models/Order.php:881). With two rows `$order->refund` resolves by default ordering,
         * so the merchant's screen, the shopper's return list and the Blade branch above each start
         * reading an arbitrary one of them.
         *
         * THE CANCELLED CASE IS THE ONE JUDGEMENT CALL, and it is settled the same way: the Blade
         * renders "Refund request was cancelled." with no button, so a shopper is never offered a
         * second attempt and the server now says the same. Re-opening a cancelled refund would be a
         * product decision.
         *
         * WHY A CANCELLED ORDER MATTERS MOST among the ineligible statuses: `status_cancel` has
         * already restored that order's stock, so a refund accepted on it reaches
         * `RefundStock` and restocks the same units a SECOND time.
         *
         * NO STORE PREDICATE ON THE REFUND LOOKUP, deliberately. The rule exists because
         * `$order->refund` is ambiguous with more than one row, and that relation filters on
         * `order_id` alone -- so this must see exactly what it sees. The order itself is already
         * proven to be this store's and this shopper's by `ownedOrder()` above.
         *
         * RAW, because `OrderRefund` is `Cachable` (app/Models/OrderRefund.php:12): an Eloquent
         * read could miss a row written moments earlier and admit the duplicate this refuses.
         *
         * A FLASH ERROR RATHER THAN A 404: this is not an ownership failure. `ownedOrder()` answers
         * those, uniformly, above.
         */
        if ((int) $order->delivered_status !== 1) {
            return redirect()->back()->with('error', __('A refund can only be requested for a delivered order.'));
        }

        if (\DB::table('order_refunds')->where('order_id', $order->id)->exists()) {
            return redirect()->back()->with('error', __('A refund has already been requested for this order.'));
        }

        $productJson = json_decode($order->product_json, true);

        if (! is_array($productJson)) {
            return redirect()->back()->with('error', __('This order has no refundable items.'));
        }

        // WHAT IS BEING REFUNDED, decided in ONE pass over the ORDER'S OWN LINES and BEFORE anything
        // is written (review E1). A ticked box is a product id; a line is a position in
        // `product_json`; `quantity[]` and `return_price[]` are posted for EVERY line, so they are
        // indexed by LINE. The first version bounded `$quantities[$line]` and then recorded
        // `$request->quantity[$tick]` -- a position in the list of TICKED boxes -- so with one of
        // two lines ticked the record carried the other line's number, and a hostile client chose
        // what went in the slot the bound never read. `OrderRefundController::RefundStock`
        // restocks the recorded quantity. So the record below is built from `$units` and nothing
        // else: the same numbers that were bounded are the numbers that are applied and recorded.
        $tickedIds = [];
        foreach ($productRefundIds as $ticked) {
            if (! is_scalar($ticked)) {
                return redirect()->back()->with('error', __('Invalid refund item.'));
            }

            $tickedIds[] = (string) $ticked;
        }

        // QUANTITY BOUNDS, enforced BEFORE anything is written. The totals below are recomputed from
        // the CURRENT row on every call, and `$quantities` was trusted verbatim, so MEASURED on this
        // head: a quantity above the line's remaining qty drove the order NEGATIVE (qty -1, total
        // -60); a NEGATIVE quantity INFLATED it (440 -> 640); and a replay of a full refund divided
        // by the line's now-zero qty -- a DivisionByZeroError, 500. The form's own input is
        // `min="1" max="{item qty}"`, so [0, remaining qty] refuses nothing a real form can send.
        $units = [];
        $matchedIds = [];
        foreach ($productJson as $key => $line) {
            if (! is_array($line) || ! isset($line['product_id']) || ! in_array((string) $line['product_id'], $tickedIds, true)) {
                continue;
            }

            $matchedIds[] = (string) $line['product_id'];

            // A ticked line must come with its quantity. The real form posts one for every line; a
            // missing entry was silently read as 0 and then crashed the record loop.
            $requested = array_key_exists($key, $quantities) ? $quantities[$key] : null;

            // is_numeric() + a whole-number test rather than FILTER_VALIDATE_INT, which rejects a
            // LEADING ZERO -- and a number input hands back exactly what was typed, so a shopper
            // entering `03` would have been refused a valid refund.
            if (! is_numeric($requested)
                || (float) $requested != floor((float) $requested)
                || (float) $requested < 0
                || (float) $requested > (float) ($line['qty'] ?? 0)) {
                return redirect()->back()->with('error', __('Invalid refund quantity.'));
            }

            $units[$key] = (int) (float) $requested;
        }

        // A ticked product that is on NO line of this order: the old record would carry it, and
        // RefundStock would restock `Product where id = <that>`.
        if (array_diff($tickedIds, $matchedIds) !== []) {
            return redirect()->back()->with('error', __('Invalid refund item.'));
        }

        // A request that removes nothing is not a refund request, and would give the merchant a row
        // to action for an order that never changed.
        if (array_sum($units) <= 0) {
            return redirect()->back()->with('error', __('Invalid refund quantity.'));
        }

        // The recorded figures that are the SHOPPER'S OWN display strings (`return_price[]`,
        // `product_sub_total`) stay as posted -- the merchant's payout is typed by the merchant and
        // capped server-side by RefundIntegrityService -- but they are type-guarded and capped at 32
        // CHARACTERS. That bounds a padded string; it does not guarantee the record fits
        // `order_refunds.product_refund_id`, a VARCHAR(255) holding this JSON, because json_encode
        // escapes non-ASCII as \uXXXX (six bytes each) and three or more lines overflow it on MySQL
        // whatever is typed -- recorded under P1-ORD-11. mb_substr also substitutes '?' for invalid
        // UTF-8, which is what keeps the encode below from failing on shopper text (measured).
        $display = static fn ($value): string => is_scalar($value) ? mb_substr((string) $value, 0, 32) : '';

        $total_product_price = 0.0;
        $product_refund_data = [];
        foreach ($productJson as $key => &$product)
        {
            $old_qty = $product['qty'];
            if (array_key_exists($key, $units))
            {
                $product['qty'] = $product['qty'] - $units[$key];

                if ($units[$key] > 0) {
                    // P1-ORD-14: what this line has LOST (qty + refunded_qty is what it started with). The merchant side gives back at most
                    // this, onto the line that lost it, so a record read by position, a hand-written one or two variants of one product
                    // cannot move the wrong units.
                    $product['refunded_qty'] = (int) ($product['refunded_qty'] ?? 0) + $units[$key];

                    $product_refund_data[] = [
                        'product_refund_id' => $product['product_id'],
                        'return_price' => $display($returnPrices[$key] ?? null),
                        'quantity' => $units[$key],
                    ];
                }
            }
            // A line already at qty 0 has nothing left to scale; dividing by it was the replay 500.
            if ((float) $old_qty != 0.0) {
                $priceBefore = $product['final_price'];
                $product['final_price'] = ($product['final_price'] * $product['qty']) / $old_qty;

                // P1-ORD-14: the VALUE the line lost, so cancelling the request can put a fully refunded line's price back exactly
                if (isset($product['refunded_qty']) && ($units[$key] ?? 0) > 0) {
                    $product['refunded_value'] = (float) ($product['refunded_value'] ?? 0) + ((float) $priceBefore - (float) $product['final_price']);
                }
            }

            $total_product_price = $total_product_price + $product['final_price'];
        }
        unset($product);
        $grand_total_price = ($total_product_price + $order->delivery_price + $order->tax_price) - $order->coupon_price;

        // ONE TRANSACTION for the order's new totals and the row that records the request.
        // `$order->save()` used to run first and the refund row second with nothing joining them, so
        // anything that failed in between (an omitted field, a rejected insert) left a shrunken
        // order and NO refund row for the merchant to see. (Whether the merchant's CANCEL then
        // restores the right line is a separate defect on the merchant side, P1-ORD-14: it reads this
        // record by line position.) Uploads wait for the commit: a rolled-back request must not
        // leave files behind.
        try {
            $order_refund = \DB::transaction(function () use ($order, $store, $request, $productJson, $total_product_price, $grand_total_price, $product_refund_data, $display) {
                // FRESHNESS (review F2). Everything above was computed from the row `ownedOrder()` read
                // BEFORE this transaction, with no lock, so two identical requests passing together both
                // clear the quantity bound against the same snapshot: the order is written once, last
                // write wins, while two refund rows each record the units. Re-read the row FOR UPDATE --
                // the second request waits here on MySQL -- and refuse if the lines are no longer what
                // this request was computed from. A raw read, not the Cachable model, so a cached copy
                // can never make a stale row look current. SQLite ignores FOR UPDATE, so the PHPUnit test pins
                // the COMPARISON with a deterministic interleave; the lock itself -- the second request
                // blocks, then reads the committed row while a plain read in an older snapshot would not --
                // was executed against a real InnoDB server by
                // tests/Support/Security/bin/verify-refund-freshness-lock-and-limiter-keys-on-mysql.php.
                $current = \DB::table('orders')
                    ->where('id', $order->id)
                    ->where('store_id', $store->id)
                    ->where('customer_id', $order->customer_id)
                    ->lockForUpdate()
                    ->first(['product_json', 'delivery_price', 'tax_price', 'coupon_price']);

                // The lines AND the three components the new total is computed from: a concurrent change to
                // delivery, tax or coupon with the lines untouched would otherwise be overwritten by a total
                // built on the values read before the lock (review round 3).
                if (! $current
                    || $current->product_json !== $order->getRawOriginal('product_json')
                    || (float) $current->delivery_price !== (float) $order->getRawOriginal('delivery_price')
                    || (float) $current->tax_price !== (float) $order->getRawOriginal('tax_price')
                    || (float) $current->coupon_price !== (float) $order->getRawOriginal('coupon_price')) {
                    return null;
                }

                /*
                 * OWNER DECISION D3 -- THE TOTALS ARE NOT WRITTEN HERE ANY MORE. P1-ORD-11.
                 *
                 * "Never overwrite original order totals... Track approval, execution and financial
                 * adjustment separately. Adjust only on reliable execution."
                 *
                 * `final_price` and `product_price` ARE the financial adjustment, and they used to
                 * move the moment a shopper submitted -- refund still `Processing`, no merchant
                 * having acted, recorded revenue already reduced. They are now written by
                 * `OrderRefundController::RefundAmonut` on the `Refunded` transition, which is the
                 * only point at which money has actually moved: `RefundIntegrityService::
                 * resolveRefundStatus()` returns `Accept` rather than `Refunded` for an online
                 * gateway payment, because no gateway refund driver exists anywhere in this
                 * platform -- so an unsettled refund now leaves the order's totals alone, which is
                 * exactly what D3 asks for.
                 *
                 * `$grand_total_price` and `$total_product_price` are still computed above and
                 * still used: the computation is what the quantity bounds and the freshness check
                 * are built on, and the lines it produces are written below.
                 *
                 * `product_json` IS STILL WRITTEN HERE, deliberately, and the reason is a measured
                 * control rather than convenience. The per-line `refunded_qty` counter it carries
                 * is what bounds `RefundStock`'s clamp (`unitsLostByProduct`) and
                 * `restorationPlan`'s bound on cancel -- and that clamp is NULL-PERMISSIVE: with no
                 * line carrying a counter it is skipped entirely and the recorded quantity restocks
                 * unbounded. Move the counter to execution and every new refund becomes an
                 * all-legacy order, turning P1-ORD-14's documented edge-case residual into the
                 * default for every refund. Pinned from both sides by
                 * StorefrontOrderRefundOwnershipTest::test_a_refund_request_does_not_touch_the_orders_totals.
                 *
                 * THE RESIDUAL, recorded rather than hidden: between request and execution the
                 * order's totals are the ORIGINAL ones while its lines sum to less. The totals are
                 * what D3 protects and what `RefundAmonut` caps against, so this is the safe
                 * direction, but it is an inconsistency a merchant can see.
                 */
                $order->product_json = json_encode($productJson, JSON_THROW_ON_ERROR);
                $order->save();

                // The `refund_status     = 'Processing'` spelling is asserted verbatim by
                // RefundStateMachineTest::test_the_state_vocabulary_is_the_one_production_code_writes
                // (it reads this file), so the first four lines keep the original column alignment.
                $order_refund                    = new OrderRefund();
                $order_refund->order_id          = $order->id;
                $order_refund->refund_status     = 'Processing';
                $order_refund->product_refund_id = json_encode($product_refund_data, JSON_THROW_ON_ERROR);
                $order_refund->store_id          = $store->id;
                // Passed through as before. A non-scalar value fails the INSERT, and the transaction
                // makes that a handled refusal with the order untouched instead of a half-write.
                $order_refund->refund_reason = $request->refund_reason;
                $order_refund->custom_refund_reason = $request->custom_refund_reason;
                $order_refund->product_refund_price = str_replace('$', '', $display($request->product_sub_total));
                $order_refund->save();

                return $order_refund;
            });
        } catch (\Throwable $e) {
            \Log::error('Storefront refund request rolled back', [
                'order_id' => $order->id,
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->with('error', __('Something Went wrong.'));
        }

        // A concurrent request changed this order's lines between the read and the lock: nothing was
        // written. The shopper reloads and sees the order as it now is.
        if ($order_refund === null) {
            return redirect()->back()->with('error', __('This order was just updated. Please review it and try again.'));
        }

        $data              = [];
        if ($request->hasfile('attachments')) {
            foreach ($request->file('attachments') as $filekey => $file) {
                $imageName = $file->getClientOriginalName();
                $dir        = 'uploads/' . $store->id . '/order_refund';
                $path = Utility::keyWiseUpload_file($request, 'attachments', $imageName, $dir, $filekey, []);

                if ($path['flag'] == 1) {
                    $data[] = $path['url'];
                } else {
                    $errors = __($path['msg']);
                }
                $file   = 'order_refund/' . $imageName;
                $order_refund->attachments    =  json_encode($data);
                $order_refund->save();
            }
        }

        return redirect()->back()->with('success', __('Refund Request Send successfully!'));
    }

    public function change_refund_cart(Request $request, $slug)
    {
        $quantity = $request->quantity;
        $CURRENCY = \App\Models\Utility::GetValueByName('CURRENCY', getCurrentStore());
        $final_price = 0;

        $product = Product::find($request->product_id);
        if ($product->variant_product == 0) {
            $product_orginal_price = $product->price - $product->discount_amount;
            $final_price = $product_orginal_price * $quantity;
        } else {
            $product = ProductVariant::where('id', $product->id)->first();
            $final_price += $product->price * $quantity;
        }
        $order = Order::order_detail($request->order_id);
        if (!$order) {
            return response()->json(['error' => __('Order or product not found')], 404);
        }

        $return['product_price'] = currency_format_with_sym( $final_price, getCurrentStore()) ?? SetNumberFormat($final_price);
        $return['CURRENCY'] = $CURRENCY;
        $return['tax_price'] = currency_format_with_sym( $order['tax_price'], getCurrentStore()) ?? SetNumberFormat($order['tax_price']);
        $return['discount_price'] = currency_format_with_sym( (($order['coupon_info']) ?  $order['coupon_info']['discount_amount'] : 0), getCurrentStore()) ?? SetNumberFormat(($order['coupon_info']) ?  $order['coupon_info']['discount_amount'] : 0);
        $return['delivered_charge'] = currency_format_with_sym( $order['delivered_charge'], getCurrentStore()) ?? SetNumberFormat($order['delivered_charge']);

        return response()->json($return);
    }

    public function add_address_form(Request $request, $slug) {
        $store = getStore($slug);
        $country_option = Country::orderBy('name', 'ASC')->pluck('name', 'id')->toArray();
        return view('front_end.sections.addressbook_add', compact('country_option', 'slug'));
    }

    public function edit_address_form(Request $request, $slug)
    {
        // P1-SEC-12. `auth('customers')->user()` is a PRESENCE test, not an identity test: it proves some
        // customer of some store is signed in and nothing more, and `DeliveryAddress::find($request->id)`
        // then rendered any address row on the platform -- no customer predicate, no store predicate, and
        // the model carries no global scope and is absent from `scoped_models`, so nothing supplied one.
        // The first sweep missed this one by keying on handler names instead of on the routed actions.
        //
        // HOW IT IS REACHED, corrected after review. An earlier version of this comment said this is the
        // handler the shipped address book's Edit control calls. That is FALSE, and measured:
        // `front_end/sections/addressbook.blade.php` is the only view naming `edit.address.form`, and it is
        // rendered from nowhere -- no `@include`, no `view()` call -- and could not render if it were,
        // because its `route('add.address.form', ...)` names a route declared in no route file. The LIVE
        // address page is `front_end/pages/address.blade.php`, which wires Edit to `address-form`
        // (`addressForm`), one of the handlers already guarded.
        //
        // The hole was real and live regardless: the route is declared, `EnsureCustomerAuthenticated`
        // admits any `customers` session, and an attacker issues `GET /{slug}/edit-address-form?id=N`
        // directly. It needs no UI. But "the path a shopper exercises normally" was an overclaim.
        //
        // `input()` rather than `get()`: Symfony 7.4 deprecates `Request::get()`, and on this route the two
        // are equivalent (there is no `{id}` route segment, and route parameters never reach `get()`).
        // If an `{id}` segment is ever added, read `$request->route('id')` as `addressForm` does.
        $store = getStore($slug);
        $DeliveryAddress = $this->ownedAddress($store, $this->shopper($store), $request->input('id'));
        if (empty($DeliveryAddress)) {
            // The same uniform refusal the other six handlers give: a redirect-with-error, never a 401
            // (the shipped clients log the user out on a 401) and never a message that distinguishes
            // "not yours" from "no such row", so the id space cannot be enumerated. Review confirmed the
            // two cases are indistinguishable in status, target, flash key, message and query count.
            return redirect()->back()->with('error', __('Address not found.'));
        }

        $country_option = Country::orderBy('name','ASC')->pluck('name', 'id');
        $DeliveryAddress->country = $DeliveryAddress->country_id;
        $DeliveryAddress->state = $DeliveryAddress->state_id;
        $DeliveryAddress->city = $DeliveryAddress->city_id;

        return view('front_end.sections.addressbook_edit', compact('slug', 'DeliveryAddress', 'country_option'));
    }

    public function getStates(Request $request)
    {
        $countryId = $request->country_id;
        $states = State::where('country_id', $countryId)
            ->orderBy('name')
            ->get(['id', 'name']);
        
        return response()->json($states);
    }

    public function getCities(Request $request)
    {
        $stateId = $request->state_id;
        $cities = City::where('state_id', $stateId)
            ->orderBy('name')
            ->get(['id', 'name']);
        
        return response()->json($cities);
    }
}