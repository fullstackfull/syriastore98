<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ProductController;
use Illuminate\Http\Request;
use App\Traits\ApiResponser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;
use App\Models\Store;
use App\Models\Product;
use App\Models\Order;
use App\Models\Utility;
use App\Models\Coupon;
use App\Models\Category;
use App\Models\Shipping;
use App\Models\Tax;
use App\Models\ProductVariant;
use App\Models\Plan;
use App\Models\ProductImage;

use App\Models\Cart;
use App\Models\Wishlist;
use App\Models\OrderTaxDetail;
use App\Models\OrderCouponDetail;
use App\Models\OrderBillingDetail;
use App\Models\User;
use App\Models\Setting;
use App\Models\UserCoupon;
use App\Models\UserStore;
use App\Models\Page;
use App\Models\AppSetting;
use App\Models\Blog;
use App\Models\Contact;
use App\Models\Newsletter;
use App\Models\PlanOrder;
use App\Models\DeliveryBoy;
use DB;
use Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\TestMail;
use App\Models\ProductBrand;
use App\Models\ProductLabel;
use App\Models\Testimonial;
use Illuminate\Support\Facades\Validator;



use App\Models\OrderNote;


use App\Events\OrderPaymentStatus;

use Illuminate\Http\JsonResponse;






use Illuminate\Support\Facades\Log;

use App\Models\StoreNotification;




class DashboardController extends Controller
{
    use ApiResponser;








public function AddOrderNote(Request $request)
{
    $validator = \Validator::make($request->all(), [
        'store_id' => 'required|integer',
        'order_id' => 'required|integer',
        'note' => 'required|string|max:5000',
        'note_type' => 'required|string|in:private_note,to_customer',
    ]);

    if ($validator->fails()) {
        return $this->error([
            'message' => $validator->errors()->first(),
        ]);
    }

    $store = Store::find($request->store_id);

    if (!$store) {
        return $this->error([
            'message' => __('Store not found!'),
        ]);
    }

    /*
     * منع إضافة ملاحظة إلى طلب تابع لمتجر آخر.
     */
    $order = Order::query()
        ->where('id', $request->order_id)
        ->where('store_id', $store->id)
        ->first();

    if (!$order) {
        return $this->error([
            'message' => __('Order not found!'),
        ]);
    }

    $note = new OrderNote();
    $note->order_id = $order->id;
    $note->customer_id = $order->customer_id ?? 0;
    $note->notes = trim((string) $request->note);
    $note->note_type = $request->note_type;

    /*
     * ملاحظات الإدارة/العميل ليست تغيير حالة الطلب.
     */
    $note->status = null;
    $note->store_id = $store->id;
    $note->save();

    return $this->success([
        'message' => __('Order note created successfully.'),
        'note' => [
            'id' => $note->id,
            'order_id' => $note->order_id,
            'customer_id' => $note->customer_id,
            'note' => $note->notes,
            'note_type' => $note->note_type,
            'status' => $note->status,
            'created_at' => $note->created_at?->toISOString(),
        ],
    ]);
}


public function DeleteOrderNote(Request $request)
{
    $validator = \Validator::make($request->all(), [
        'store_id' => 'required|integer',
        'order_id' => 'required|integer',
        'note_id' => 'required|integer',
    ]);

    if ($validator->fails()) {
        return $this->error([
            'message' => $validator->errors()->first(),
        ]);
    }

    $store = Store::find($request->store_id);

    if (!$store) {
        return $this->error([
            'message' => __('Store not found!'),
        ]);
    }

    $order = Order::query()
        ->where('id', $request->order_id)
        ->where('store_id', $store->id)
        ->first();

    if (!$order) {
        return $this->error([
            'message' => __('Order not found!'),
        ]);
    }

    $note = OrderNote::query()
        ->where('id', $request->note_id)
        ->where('order_id', $order->id)
        ->where('store_id', $store->id)
        ->first();

    if (!$note) {
        return $this->error([
            'message' => __('Order note not found!'),
        ]);
    }

    $note->delete();

    return $this->success([
        'message' => __('Order note deleted successfully.'),
        'note_id' => (int) $request->note_id,
    ]);
}


















 public function updateStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_order_id' => [
                'required',
                'string',
                'max:191',
            ],

            /*
             * يمكن إرسال delivered_status وحده،
             * أو payment_status وحده،
             * أو الاثنين معًا.
             */
            'delivered_status' => [
                'nullable',
                'integer',
                'in:0,1,2,3,4,5,6',
                'required_without:payment_status',
            ],

            'payment_status' => [
                'nullable',
                'string',
                'in:Paid,Unpaid',
                'required_without:delivered_status',
            ],

            'note' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        /*
         * المتجر الحالي.
         *
         * هذه الدالة موجودة أصلًا في مشروعك وتستخدمها
         * في بقية الكنترولرات.
         */
        $storeId = getCurrentStore();

        if (empty($storeId)) {
            return response()->json([
                'success' => false,
                'message' => 'Current store could not be resolved.',
            ], 403);
        }
$storeId = getCurrentStore();
        /*
         * نبحث بالـproduct_order_id وضمن المتجر الحالي فقط.
         * هذا يمنع أدمن متجر من تعديل طلب تابع لمتجر آخر.
         */
        $order = Order::query()
            ->where('product_order_id', trim(
                (string) $request->input('product_order_id')
            ))
            ->where('store_id', $storeId)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        $oldDeliveredStatus = (int) $order->delivered_status;
        $oldPaymentStatus = (string) $order->payment_status;

        $hasDeliveredStatus = $request->has('delivered_status')
            && $request->input('delivered_status') !== null;

        $hasPaymentStatus = $request->has('payment_status')
            && $request->input('payment_status') !== null;

        $newDeliveredStatus = $hasDeliveredStatus
            ? (int) $request->input('delivered_status')
            : $oldDeliveredStatus;

        $newPaymentStatus = $hasPaymentStatus
            ? (string) $request->input('payment_status')
            : $oldPaymentStatus;

        /*
         * إذا لم يتغير شيء، نعيد نجاح بدون تنفيذ حفظ غير ضروري.
         */
        if (
            $oldDeliveredStatus === $newDeliveredStatus
            && $oldPaymentStatus === $newPaymentStatus
            && !$request->filled('note')
        ) {
            return response()->json([
                'success' => true,
                'message' => 'Order already has the requested statuses.',
                'data' => $this->orderResponse($order),
            ]);
        }

        try {
            DB::transaction(function () use (
                $request,
                $order,
                $hasDeliveredStatus,
                $newDeliveredStatus,
                $hasPaymentStatus,
                $newPaymentStatus
            ) {
                if ($hasDeliveredStatus) {
                    $order->delivered_status = $newDeliveredStatus;

                    /*
                     * تحديث التاريخ المرتبط بالحالة.
                     */
                    switch ($newDeliveredStatus) {
                        case 0:
                            // Pending
                            break;

                        case 1:
                            // Delivered
                            $order->delivery_date = now();
                            break;

                        case 2:
                            // Cancel
                            $order->cancel_date = now();
                            break;

                        case 3:
                            // Return
                            $order->return_date = now();

                            /*
                             * 0 = none
                             * 1 = request
                             * 2 = approve
                             * 3 = cancel
                             */
                            $order->return_status = 2;
                            break;

                        case 4:
                            // Confirmed
                            $order->confirmed_date = now();
                            break;

                        case 5:
                            // Picked Up
                            $order->picked_date = now();
                            break;

                        case 6:
                            // Shipped
                            $order->shipped_date = now();
                            break;
                    }
                }

                if ($hasPaymentStatus) {
                    $order->payment_status = $newPaymentStatus;
                }

                if ($request->filled('note')) {
                    $order->delivery_comment = trim(
                        (string) $request->input('note')
                    );
                }

                $order->save();
            });

            $order->refresh();

            /*
             * الحدث الموجود أصلًا عندك.
             */
            event(new OrderPaymentStatus($order));

            Log::info('Admin API updated order statuses', [
                'order_id' => $order->id,
                'product_order_id' => $order->product_order_id,
                'store_id' => $order->store_id,
                'admin_id' => auth()->id(),

                'old_delivered_status' => $oldDeliveredStatus,
                'new_delivered_status' => $order->delivered_status,

                'old_payment_status' => $oldPaymentStatus,
                'new_payment_status' => $order->payment_status,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order updated successfully.',
                'data' => [
                    'order_id' => $order->id,
                    'product_order_id' => $order->product_order_id,

                    'previous_delivered_status' => $oldDeliveredStatus,
                    'previous_delivered_status_name' =>
                        $this->deliveredStatusName($oldDeliveredStatus),

                    'delivered_status' => (int) $order->delivered_status,
                    'delivered_status_name' =>
                        $this->deliveredStatusName(
                            (int) $order->delivered_status
                        ),

                    'previous_payment_status' => $oldPaymentStatus,
                    'payment_status' => $order->payment_status,

                    'updated_at' => $order->updated_at?->toISOString(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Admin API failed to update order statuses', [
                'order_id' => $order->id,
                'product_order_id' => $order->product_order_id,
                'store_id' => $order->store_id,
                'admin_id' => auth()->id(),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not update the order.',
            ], 500);
        }
    }

    private function deliveredStatusName(int $status): string
    {
        return match ($status) {
            0 => 'Pending',
            1 => 'Delivered',
            2 => 'Cancel',
            3 => 'Return',
            4 => 'Confirmed',
            5 => 'Picked Up',
            6 => 'Shipped',
            default => 'Unknown',
        };
    }

    private function orderResponse(Order $order): array
    {
        return [
            'order_id' => $order->id,
            'product_order_id' => $order->product_order_id,
            'delivered_status' => (int) $order->delivered_status,
            'delivered_status_name' => $this->deliveredStatusName(
                (int) $order->delivered_status
            ),
            'payment_status' => $order->payment_status,
            'updated_at' => $order->updated_at?->toISOString(),
        ];
    }
















public function noti(Request $request)
{
  $user = Auth::user();
    $storeId = $user->current_store;

    $notifications = StoreNotification::where('store_id', $storeId)
        ->latest()
        ->paginate(20);



   $count= StoreNotification::where('store_id', $storeId)->count();

    return response()->json([
        'status' => true,
        'data' => $notifications,
        'count' => $count, 
    ]);
}




    public function base_url(Request $request)
    {
        $img_url = get_file('themes');
        $data =  explode('themes', $img_url);

        return $this->success(['image_url' => $data[0], 'payment_url' => url('/')]);
    }

    public function currency(Request $request)
    {
        $user = Auth::user();
        $currentstore = $user->current_store;

        $store = Store::find($currentstore);
        
        $array['currency'] = \App\Models\Utility::GetValByName('CURRENCY', $store->id);
        $array['currency_name'] = \App\Models\Utility::GetValByName('CURRENCY_NAME', $store->id);

        return $this->success($array);
    }

    public function login(Request $request)
    {
        $rules = [
            'email' => 'required|email',
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        if (!empty($request->password)) {
            $user = Auth::attempt(['email' => $request->email, 'password' => $request->password]);
            if (!$user) {
                return $this->error(['message' => __('Invalid login details')]);
            }
            $user = Auth::user();

            $user = User::find($user->id);
        } else {
            return $this->error(['message' => __('Invalid login details')]);
        }

        $user_data = User::find($user->id);

        $user_array['id'] = $user_data->id;
        $user_array['name'] = $user_data->name;
        $user_array['email'] = $user_data->email;
        $user_array['image'] = !empty($user_data->profile_image) ? $user_data->profile_image : "themes/style/uploads/require/user.png";
        $user_array['mobile'] = $user_data->mobile;
        $user_array['current_store'] = $user_data->current_store;

        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;
        $user_array['token'] = $token;
        $user_array['token_type'] = 'Bearer';
        return $this->success($user_array);
    }





public function logouto(Request $request)
{
    $authorization = $request->header('Authorization');

    if (!$authorization || !str_starts_with($authorization, 'Bearer ')) {
        return $this->error(
            ['message' => __('Unauthenticated.')],
            __('Unauthenticated.'),
            401
        );
    }

    $plainTextToken = substr($authorization, 7);
    $parts = explode('|', $plainTextToken, 2);

    $tokenId = $parts[0] ?? null;
    $tokenValue = $parts[1] ?? null;

    if (!$tokenId || !$tokenValue) {
        return $this->error(
            ['message' => __('Unauthenticated.')],
            __('Unauthenticated.'),
            401
        );
    }

    $token = \DB::table('personal_access_tokens')
        ->where('id', $tokenId)
        ->first();

    if (
        !$token ||
        !hash_equals($token->token, hash('sha256', $tokenValue))
    ) {
        return $this->error(
            ['message' => __('Unauthenticated.')],
            __('Unauthenticated.'),
            401
        );
    }

    \DB::table('personal_access_tokens')
        ->where('id', $tokenId)
        ->delete();

    return $this->success([
        'message' => __('Logged out successfully.')
    ]);
}


    public function dashboard(Request $request)
    {
        $user = Auth::user();
        $currentstore = $user->current_store;

        $DashboardData = [];
        //user info
        $users = [];
        $userdata = User::find($user->id);
        if (!empty($userdata)) {
            $users['name'] = $userdata->name;
            $users['image'] = $userdata->profile_image;
        }
        $DashboardData['user'] = $users;

        //Storedata dropdown
        $storearray = [];
        $Allstore = Store::where('created_by', $user->id)->get();

        if (!empty($Allstore)) {
            foreach ($Allstore as $key => $value) {

                $storearray[$key]['id'] = $value->id;
                $storearray[$key]['name'] = $value->name;
            }
        }
        $DashboardData['store'] = $storearray;

        //statics
        $statics = [];
        $store = Store::find($currentstore);
        $theme_url = route('landing_page', $store->slug);
        $statics['url'] = $theme_url;
        $statics['name'] = $store->name;

        $DashboardData['qr-code'] = $statics;

        //product
        $TotalProducts = Product::where('store_id', $store->id)->count();
        $TotalSales = Order::where('store_id', $store->id)->where('delivered_status', 1)->count();
        $TotalOrder = Order::where('store_id', $store->id)->count();

        $DashboardData['products'] = $TotalProducts;
        $DashboardData['sales'] = $TotalSales;
        $DashboardData['order'] = $TotalOrder;

        //top products
        $topproducts = [];
        $Products = Product::orderBy('created_at', 'Desc')->where('store_id', $store->id)->where('variant_product', 0)->limit('2')->get();
        if (!empty($Products)) {
            $DashboardData['product'] = $Products;
        } else {
            return $this->error(['message' => __('Product Data not found!')]);
        }
        //recent order
        $recentOrder = [];
        $orders = Order::orderBy('id', 'DESC')->where('store_id', $store->id)->limit(4)->get();
        if (!empty($orders)) {
            foreach ($orders as $key => $value) {
                $recentOrder[$key]['id'] = $value->id;
                $recentOrder[$key]['product_order_id'] = $value->product_order_id;
                $recentOrder[$key]['user_name'] = $value->user_name;
                $recentOrder[$key]['order_date'] = date('M d, Y', strtotime($value->order_date));
                $recentOrder[$key]['final_price'] = $value->final_price;
                $recentOrder[$key]['delivery_status'] = $value->delivered_status_string;
                $recentOrder[$key]['currency'] = Utility::GetValByName('CURRENCY_NAME', $store->id);
            }
            $DashboardData['recent_order'] = $recentOrder;
        } else {
            return $this->error(['message' => __('Order Data not found!')]);
        }

        //coupons
        $recentcoupon = [];
        $coupons = Coupon::orderBy('id', 'DESC')->where('store_id', $store->id)->limit(3)->get();
        if (!empty($coupons)) {
            foreach ($coupons as $key => $value) {

                $recentcoupon[$key]['id'] = $value->id;
                $recentcoupon[$key]['coupon_name'] = $value->coupon_name;
                $recentcoupon[$key]['coupon_code'] = $value->coupon_code;
                $recentcoupon[$key]['coupon_type'] = $value->coupon_type;
                $recentcoupon[$key]['discount_amount'] = $value->discount_amount;
            }
            $DashboardData['coupons'] = $recentcoupon;
        }

        //order chart
        $arrDuration = [];
        $arrParam = ['duration' => 'week'];


        if ($arrParam['duration']) {
            if ($arrParam['duration'] == 'week') {
                $previous_week = strtotime("-1 week +1 day");

                for ($i = 0; $i < 7; $i++) {
                    $arrDuration[date('Y-m-d', $previous_week)] = date('d-M', $previous_week);
                    $previous_week = strtotime(date('Y-m-d', $previous_week) . " +1 day");
                }
            }
        }
        $arrTask = [];
        $i = 0;
        $arrTask[$i]['date'] = [];
        $arrTask[$i]['order'] = [];
        foreach ($arrDuration as $date => $label) {
            $data = Order::select(\DB::raw('count(*) as total'))->where('store_id', $store->id)->whereDate('created_at', '=', $date)->first();


            $arrTask[$i]['date'] = $label;
            $arrTask[$i]['order'] = $data->total;
            $i++;
        }

        $DashboardData['orderchart'] = $arrTask;

        //visitor chart
        $visistorchart = [];
        $arrDuration = [];
        $arrParam = ['duration' => 'month'];
        if ($arrParam['duration']) {

            if ($arrParam['duration'] == 'month') {
                $previous_month = strtotime("-2 week +0 day");
                for ($i = 0; $i < 15; $i++) {
                    $arrDuration[date('Y-m-d', $previous_month)] = date('d-M', $previous_month);
                    $previous_month                              = strtotime(date('Y-m-d', $previous_month) . " +1 day");
                }
            }
        }
        $arrTask          = [];
        $i = 0;
        $arrTask[$i]['date'] = [];
        $arrTask[$i]['pageview']  = [];

        foreach ($arrDuration as $date => $label) {
            $data['visitor'] = \DB::table('shetabit_visits')->select(\DB::raw('count(*) as total'))->where('store_id', $store->id)->whereDate('created_at', '=', $date)->first();
            $uniq            = \DB::table('shetabit_visits')->select('ip')->distinct()->where('store_id', $store->id)->whereDate('created_at', '=', $date)->get();

            $data['unique']           = $uniq->count();
            $arrTask[$i]['date']      = $label;
            $arrTask[$i]['pageview']        = $data['visitor']->total;
            $arrTask[$i]['unique_pageview'] = $data['unique'];
            $i++;
        }

        $DashboardData['visitorchart'] = $arrTask;

        return $this->success($DashboardData);
    }

    public function CategoryList(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $Category = Category::where('store_id', $store->id)->OrderBy('id', 'desc')->paginate(10);

        if (!empty($Category)) {
            return $this->success($Category);
        } else {
            return $this->error(['message' => __('Category Data not found.')]);
        }
    }

    public function ProductList(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $Products = Product::where('store_id', $store->id)->where('product_type', null)->OrderBy('id', 'desc')->paginate(10);

        if (!empty($Products)) {
            return $this->success($Products);
        } else {
            return $this->error(['message' => __('Product Data not found.')]);
        }
    }

    public function VariantList(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $ProductVariant = ProductVariant::where('store_id', $store->id)->OrderBy('id', 'desc')->paginate(10);

        if (!empty($ProductVariant)) {
            return $this->success($ProductVariant);
        } else {
            return $this->error(['message' => __('Product Variant Data not found.')]);
        }
    }





public function OrderList(Request $request)
{
    $request->validate([
        'store_id' => ['required', 'integer'],
        // تحديث القيم المقبولة لتشمل 4, 5, 6
        'delivered_status' => ['nullable', 'integer', 'in:0,1,2,3,4,5,6'],
        'payment_status' => ['nullable', 'string'],
        'from_date' => ['nullable', 'date'],
        'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
    ]);

    $store = Store::find($request->store_id);

    if (!$store) {
        return $this->error([
            'message' => __('Store not found!'),
        ]);
    }

    $query = Order::where('store_id', $store->id);

    // فلترة حسب حالة التوصيل (0, 1, 2, 3, 4, 5, 6)
// فلترة حسب حالة التوصيل (0, 1, 2, 3, 4, 5, 6)
if ($request->has('delivered_status') && $request->delivered_status !== null && $request->delivered_status !== '') {
    $query->where('delivered_status', (int) $request->delivered_status);
}

    // فلترة حسب حالة الدفع
    if ($request->filled('payment_status')) {
        $query->where(
            'payment_status',
            $request->payment_status
        );
    }

    // فلترة من تاريخ الطلب
    if ($request->filled('from_date')) {
        $query->whereDate(
            'order_date',
            '>=',
            $request->from_date
        );
    }

    // فلترة إلى تاريخ الطلب
    if ($request->filled('to_date')) {
        $query->whereDate(
            'order_date',
            '<=',
            $request->to_date
        );
    }

    $orders = $query
        ->orderByDesc('id')
        ->paginate($request->input('per_page', 10));

    return $this->success($orders);
}






    public function OrderList_backup(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $orders = Order::where('store_id', $store->id)->OrderBy('id', 'desc')->paginate(10);

        if (!empty($orders)) {
            return $this->success($orders);
        } else {
            return $this->error(['message' => __('Order Data not found.')]);
        }
    }

    public function CouponList(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $coupons = Coupon::where('store_id', $store->id)->OrderBy('id', 'desc')->paginate(10);

        if (!empty($coupons)) {
            return $this->success($coupons);
        } else {
            return $this->error(['message' => __('Coupon Data not found.')]);
        }
    }

    public function ShippingList(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $shipping = Shipping::where('store_id', $store->id)->OrderBy('id', 'desc')->paginate(10);

        if (!empty($shipping)) {
            return $this->success($shipping);
        } else {
            return $this->error(['message' => __('Shipping Data not found.')]);
        }
    }

    public function TaxList(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $tax = Tax::where('store_id', $store->id)->OrderBy('id', 'desc')->paginate(10);

        if (!empty($tax)) {
            return $this->success($tax);
        } else {
            return $this->error(['message' => __('Tax Data not found.')]);
        }
    }

    public function AddCategory(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required',
                'image' => 'required',
                'icon_image' => 'required',
                'trending' => 'required',
                'status' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $dir = 'uploads/' . $store->id;
        if ($request->image) {
            $fileName = rand(10, 100) . '_' . time() . "_" . $request->image->getClientOriginalName();
            $path = Utility::upload_file($request, 'image', $fileName, $dir, []);
            if ($path['flag'] == 0) {
                $msg['flag'] = 'error';
                $msg['msg'] =  $path['msg'];

                return $msg;
            }
        }

        if ($request->icon_image) {
            $fileName = rand(10, 100) . '_' . time() . "_" . $request->icon_image->getClientOriginalName();
            $paths = Utility::upload_file($request, 'icon_image', $fileName, $dir, []);
            if ($paths['flag'] == 0) {
                $msg['flag'] = 'error';
                $msg['msg'] =  $paths['msg'];

                return $msg;
            }
        }

        $Category = new Category();
        $Category->name         = $request->name;
        $Category->image_url    = $path['full_url'];
        $Category->image_path   = $path['url'];
        $Category->icon_path    = $paths['url'];
        $Category->trending     = $request->trending;
        $Category->status       = $request->status;
        $Category->store_id     = $request->store_id;
        $Category->save();

        return $this->success(['message' => __('Maincategory added successfully!')]);
    }

    public function UpdateCategory(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'category_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $Category = Category::find($request->category_id);
        if (!empty($Category)) {
            $dir = 'uploads/' . $store->id;
            if ($request->image) {
                $fileName = rand(10, 100) . '_' . time() . "_" . $request->image->getClientOriginalName();
                $path = Utility::upload_file($request, 'image', $fileName, $dir, []);
                if ($path['flag'] == 0) {
                    $msg['flag'] = 'error';
                    $msg['msg'] =  $path['msg'];

                    return $msg;
                }
                $Category->image_url    = $path['full_url'];
                $Category->image_path   = $path['url'];
            }

            if ($request->icon_image) {
                $fileName = rand(10, 100) . '_' . time() . "_" . $request->icon_image->getClientOriginalName();
                $paths = Utility::upload_file($request, 'icon_image', $fileName, $dir, []);
                if ($paths['flag'] == 0) {
                    $msg['flag'] = 'error';
                    $msg['msg'] =  $paths['msg'];

                    return $msg;
                }
                $Category->icon_path    = $paths['url'];
            }
            if (!empty($request->name)) {
                $Category->name         = $request->name;
            }
            if (!empty($request->trending)) {
                $Category->trending     = $request->trending;
            }
            $Category->save();

            return $this->success(['message' => __('Maincategory Updated successfully!')]);
        } else {
            return $this->error(['message' => __('Maincategory not found!')]);
        }
    }

    public function DeleteCategory(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'category_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $Category = Category::find($request->category_id);

        if (!empty($Category)) {
            if (File::exists(base_path($Category->image_path))) {
                File::delete(base_path($Category->image_path));
            }
            if (File::exists(base_path($Category->icon_path))) {
                File::delete(base_path($Category->icon_path));
            }

            $Category->delete();
            return $this->success(['message' => __('Maincategory deleted successfully!.')]);
        } else {
            return $this->error(['message' => __('Maincategory data not found.')]);
        }
    }

    public function AddCoupon(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'coupon_name' => 'required',
                'coupon_type' => 'required',
                'discount_amount' => 'required',
                'coupon_limit' => 'required',
                'coupon_expiry_date' => 'required',
                'coupon_code' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }


        $coupon                    = new Coupon();
        $coupon->coupon_name       = $request->coupon_name;
        $coupon->coupon_type       = $request->coupon_type;
        $coupon->discount_amount   = $request->discount_amount;
        $coupon->coupon_limit      = $request->coupon_limit;
        $coupon->coupon_expiry_date = $request->coupon_expiry_date;
        $coupon->coupon_code       = trim($request->coupon_code);
        $coupon->status            = $request->status;
        $coupon->store_id          = $request->store_id;
        $coupon->save();

        return $this->success(['message' => __('Coupon added successfully!')]);
    }

    public function UpdateCoupon(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'coupon_id' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $coupon = Coupon::find($request->coupon_id);
        if (!empty($request->coupon_name)) {
            $coupon->coupon_name       = $request->coupon_name;
        }
        if (!empty($request->coupon_type)) {
            $coupon->coupon_type       = $request->coupon_type;
        }
        if (!empty($request->discount_amount)) {
            $coupon->discount_amount   = $request->discount_amount;
        }
        if (!empty($request->coupon_limit)) {
            $coupon->coupon_limit      = $request->coupon_limit;
        }
        if (!empty($request->coupon_expiry_date)) {
            $coupon->coupon_expiry_date = $request->coupon_expiry_date;
        }
        if (!empty(trim($request->coupon_code))) {
            $coupon->coupon_code       = trim($request->coupon_code);
        }
        if (!empty($request->status)) {
            $coupon->status            = $request->status;
        }
        $coupon->save();


        return $this->success(['message' => __('Coupon Updated successfully!')]);
    }

    public function DeleteCoupon(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'coupon_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $coupon = Coupon::find($request->coupon_id);
        if (!empty($coupon)) {
            $coupon->delete();
            return $this->success(['message' => __('Coupon deleted successfully!.')]);
        } else {
            return $this->error(['message' => __('Coupon data not found.')]);
        }
    }

    public function GenerateCode(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $length = 10;
        $result = '';
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $charactersLength = strlen($characters);
        for ($i = 0; $i < $length; $i++) {
            $result .= $characters[rand(0, $charactersLength - 1)];
        }

        return $this->success($result);
    }

    public function AddShipping(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required',
                'description' => 'required',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $shipping                   = new Shipping();
        $shipping->name             = $request->name;
        $shipping->description      = $request->description;
        $shipping->slug             = $store->slug;
        $shipping->store_id         = $request->store_id;
        $shipping->save();

        return $this->success(['message' => __('Shipping added successfully!')]);
    }

    public function UpdateShipping(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'shipping_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $shipping = Shipping::find($request->shipping_id);

        if (!empty($shipping)) {
            if (!empty($request->name)) {
                $shipping->name         = $request->name;
            }
            if (!empty($request->description)) {
                $shipping->description      = $request->description;
            }
            $shipping->save();

            return $this->success(['message' => __('Shipping Updated successfully!')]);
        } else {
            return $this->error(['message' => __('Shipping not found!')]);
        }
    }

    public function DeleteShipping(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'shipping_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $shipping = Shipping::find($request->shipping_id);

        if (!empty($shipping)) {
            $shipping->delete();
            return $this->success(['message' => __('Shipping deleted successfully!.')]);
        } else {
            return $this->error(['message' => __('Shipping data not found.')]);
        }
    }

    public function AddTax(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $tax           = new Tax();
        $tax->name     = $request->name;
        $tax->store_id = $request->store_id;
        $tax->save();

        return $this->success(['message' => __('Tax added successfully!')]);
    }

    public function UpdateTax(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'tax_id' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $tax = Tax::find($request->tax_id);

        if (!empty($request->name)) {
            $tax->name     = $request->name;
        }
        $tax->save();
        return $this->success(['message' => __('Tax Updated successfully!')]);
    }

    public function DeleteTax(Request $request)
    {
        $store = Store::find($request->store_id);

        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'tax_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $tax = Tax::find($request->tax_id);

        if (!empty($tax)) {
            $tax->delete();
            return $this->success(['message' => __('Tax deleted successfully!.')]);
        } else {
            return $this->error(['message' => __('Tax data not found.')]);
        }
    }

    public function AddVariant(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required',
                'type' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $ProductVariant             = new ProductVariant();
        $ProductVariant->name       = $request->name;
        $ProductVariant->type       = $request->type;
        $ProductVariant->store_id   = $request->store_id;
        $ProductVariant->save();

        return $this->success(['message' => __('Product Variant added successfully!')]);
    }

    public function UpdateVariant(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'productvariant_id' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $ProductVariant = ProductVariant::find($request->productvariant_id);

        if (!empty($request->name)) {
            $ProductVariant->variant       = $request->name;
        }
        if (!empty($request->type)) {
            $ProductVariant->type       = $request->type;
        }
        $ProductVariant->save();

        return $this->success(['message' => __('Product Variant Updated successfully!')]);
    }

    public function DeleteVariant(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'variant_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $ProductVariant = ProductVariant::find($request->variant_id);
        if (!empty($ProductVariant)) {
            $ProductVariant->delete();
            return $this->success(['message' => __('Product Variant deleted successfully!.')]);
        } else {
            return $this->error(['message' => __('Product Variant data not found.')]);
        }
    }

    public function CreateProduct(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $ProductData = [];

        $categoryarray = [];
        $maincategory = Category::where('store_id', $store->id)->get()->toArray();
        if (!empty($maincategory)) {
            foreach ($maincategory as $key => $value) {
                $categoryarray[$key]['id'] = $value['id'];
                $categoryarray[$key]['name'] = $value['name'];

                $ProductData['category'] = $categoryarray;
            }
        } else {
            return $this->error(['message' => __('Category data not found')]);
        }

        $variantarray = [];
        $variant = ProductVariant::where('store_id', $store->id)->get()->toArray();
        if (!empty($variant)) {
            foreach ($variant as $key => $value) {
                $variantarray[$key]['id'] = $value['id'];
                $variantarray[$key]['name'] = $value['variant'] ?? ($value['name'] ?? '-');

                $ProductData['variant'] = $variantarray;
            }
        }
        // else {
        //     return $this->error(['message' => 'Variant data not found']);
        // }

        $product_details_json_path = base_path('theme_json/product-detail.json');;
        if (file_exists($product_details_json_path)) {
            $product_detail_json = json_decode(file_get_contents($product_details_json_path), true);
            $product_detail_array = [];
            foreach ($product_detail_json as $key => $value) {
                foreach ($value['inner-list'] as $key1 => $val) {
                    $product_detail_array[$value['section_slug']][$val['field_slug']] = $val['field_default_text'];
                }
            }
            $ProductData['details'] = $product_detail_array;
        } else {
            return $this->error(['message' => __('Product Details not found.')]);
        }

        return $this->success($ProductData);
    }





public function ViewOrder(Request $request)
{
    $validator = \Validator::make($request->all(), [
        'store_id' => 'required|integer',
        'order_id' => 'required|integer',
    ]);

    if ($validator->fails()) {
        return $this->error([
            'message' => $validator->errors()->first(),
        ]);
    }

    $store = Store::find($request->store_id);

    if (!$store) {
        return $this->error([
            'message' => __('Store not found!'),
        ]);
    }

    /*
     * التأكد أن الطلب موجود وتابع لنفس المتجر.
     */
    $orderModel = Order::query()
        ->where('id', $request->order_id)
        ->where('store_id', $store->id)
        ->first();

    if (!$orderModel) {
        return $this->error([
            'message' => __('Order not found!'),
        ]);
    }

    $order = Order::order_detail($orderModel->id);

    if (!empty($order['message'])) {
        return $this->error($order);
    }

    /*
     * جلب ملاحظات الطلب فقط.
     */
    $notes = OrderNote::query()
        ->where('order_id', $orderModel->id)
        ->where('store_id', $store->id)
        ->latest('id')
        ->get()
        ->map(function (OrderNote $note) {
            return [
                'id' => $note->id,
                'order_id' => $note->order_id,
                'customer_id' => $note->customer_id,
                'note' => $note->notes,
                'note_type' => $note->note_type,
                'status' => $note->status,
                'created_at' => $note->created_at?->toISOString(),
                'updated_at' => $note->updated_at?->toISOString(),
            ];
        });

    $order['notes'] = $notes;

    return $this->success($order);
}










    public function ViewOrder_backup(Request $request)
    {
        $store = Store::find($request->store_id);

        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'order_id' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $order = Order::order_detail($request->order_id);

        if (!empty($order['message'])) {
            return $this->error($order);
        } else {
            return $this->success($order);
        }
    }











private function deleteProductFile(?string $path): void
{
    if (empty($path)) {
        return;
    }

    /*
     * إذا كانت المسارات مخزنة مثل:
     * public/uploads/products/image.jpg
     * فاستخدام base_path صحيح.
     *
     * أما إذا كانت مخزنة مثل:
     * uploads/products/image.jpg
     * فغالبًا تحتاج public_path بدل base_path.
     */
    $fullPath = base_path(ltrim($path, '/'));

    if (File::isFile($fullPath)) {
        File::delete($fullPath);
    }
}





public function DeleteProduct(Request $request)
{
    $validator = Validator::make($request->all(), [
        'store_id'   => ['required', 'integer'],
        'product_id' => ['required', 'integer'],
    ]);

    if ($validator->fails()) {
        return $this->error([
            'message' => $validator->errors()->first(),
        ]);
    }

    $store = Store::find($request->store_id);

    if (!$store) {
        return $this->error([
            'message' => __('Store not found!'),
        ]);
    }

    // مهم: تأكد أن المنتج تابع لنفس المتجر
    $product = Product::where('id', $request->product_id)
        ->where('store_id', $store->id)
        ->first();

    if (!$product) {
        return $this->error([
            'message' => __('Product not found.'),
        ]);
    }

    DB::beginTransaction();

    try {
        $productImages = ProductImage::where(
            'product_id',
            $product->id
        )->get();

        foreach ($productImages as $productImage) {
            $this->deleteProductFile($productImage->image_path ?? null);
        }

        // حذف الصور الموجودة داخل JSON
        $descriptions = json_decode(
            $product->other_description_api ?? '',
            true
        );

        if (json_last_error() === JSON_ERROR_NONE && is_array($descriptions)) {
            $items = $descriptions['product-other-description'] ?? [];

            if (is_array($items)) {
                foreach ($items as $item) {
                    if (
                        is_array($item) &&
                        ($item['field_type'] ?? null) === 'photo upload'
                    ) {
                        $this->deleteProductFile($item['value'] ?? null);
                    }
                }
            }
        }

        // حذف صورة الغلاف
        $this->deleteProductFile($product->cover_image_path ?? null);

        ProductImage::where('product_id', $product->id)->delete();

        Cart::where('product_id', $product->id)->delete();
        Wishlist::where('product_id', $product->id)->delete();

        $product->delete();

        DB::commit();

        return $this->success([
            'message' => __('Product deleted successfully!'),
        ]);
    } catch (\Throwable $exception) {
        DB::rollBack();

        Log::error('Delete product failed', [
            'product_id' => $request->product_id,
            'store_id'   => $request->store_id,
            'message'    => $exception->getMessage(),
            'file'       => $exception->getFile(),
            'line'       => $exception->getLine(),
            'trace'      => $exception->getTraceAsString(),
        ]);

        return $this->error([
            'message' => __('Unable to delete the product.'),
        ]);
    }
}








    public function DeleteProduct_backup(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'product_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $ProductImages = ProductImage::where('product_id', $request->product_id)->get();

        if (!empty($ProductImages)) {
            // image remove from product variant image
            foreach ($ProductImages as $key => $ProductImage) {
                if (File::exists(base_path($ProductImage->image_path))) {
                    File::delete(base_path($ProductImage->image_path));
                }
            }
        }

        ProductImage::where('product_id', $request->product_id)->delete();



        $Product = Product::find($request->product_id);
        if (!empty($Product)) {
            // image remove from description json
            $description_json = $Product->other_description_api;
            if (!empty($description_json)) {
                $description_json = json_decode($Product->other_description_api, true);
                foreach ($description_json['product-other-description'] as $key => $value) {
                    if ($value['field_type'] == 'photo upload') {
                        if (File::exists(base_path($value['value']))) {
                            File::delete(base_path($value['value']));
                        }
                    }
                }
            }

            // image remove from cover image
            if (File::exists(base_path($Product->cover_image_path))) {
                File::delete(base_path($Product->cover_image_path));
            }

            Cart::where('product_id', $request->product_id)->delete();
            Wishlist::where('product_id', $request->product_id)->delete();

            Product::where('id', $request->product_id)->delete();
        } else {
            return $this->error(['message' => __('Product not found.')]);
        }

        return $this->success(['message' => __('Product deleted successfully!.')]);
    }

    public function AddProduct(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required',
                'category_id' => 'required',
                'description' => 'required',
                'cover_image' => 'required',
                'product_image' => 'required',
                'price' => 'required',
                'discount_type' => 'required',
                'discount_amount' => 'required',
                'product_stock' => 'required',
                'trending' => 'required',
                'variant_product' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $user = \Auth::user();
        $total_products = $user->countProducts();
        $plan = Plan::find($user->plan_id);
        $dir = 'uploads/' . $store->id;


        // description array
        $array = !empty($request->array) ? $request->array : [];
        $array_api = [];
        foreach ($array as $array_key => $slug) {
            foreach ($slug['inner-list'] as $slug_key => $value) {

                $array_api[$slug['section_slug']][$slug_key]['field_type'] = $value['field_type'];
                $array_api[$slug['section_slug']][$slug_key]['name'] = $value['field_name'];
                $array_api[$slug['section_slug']][$slug_key]['value'] = !empty($value['value']) ? $value['value'] : '';

                if ($value['field_type'] == 'photo upload') {
                    $theme_image = !empty($value['value']) ? $value['value'] : '';
                    $upload_image_path = !empty($value['value']) ? $value['value'] : '';
                    if (gettype($theme_image) == 'object') {

                        $fileName = rand(10, 100) . '_' . time() . "_" . $theme_image->getClientOriginalName();
                        $upload = Utility::jsonUpload_file($request, 'value', $theme_image, $fileName, $dir, []);

                        if ($upload['flag'] == true) {
                            $upload_image_path = $upload['image_path'];
                        }
                    }

                    $array[$array_key]['inner-list'][$slug_key]['image_path'] = $upload_image_path;
                    $array[$array_key]['inner-list'][$slug_key]['value'] = $upload_image_path;

                    $array_api[$slug['section_slug']][$slug_key]['field_type'] = $value['field_type'];
                    $array_api[$slug['section_slug']][$slug_key]['name'] = $value['field_name'];
                    $array_api[$slug['section_slug']][$slug_key]['value'] = $upload_image_path;
                }
            }
        }
        
        if ($total_products < $plan->max_products || $plan->max_products == -1) {
            if ($request->cover_image) {
                $fileName = rand(10, 100) . '_' . time() . "_" . $request->cover_image->getClientOriginalName();
                $path = Utility::upload_file($request, 'cover_image', $fileName, $dir, []);
                if ($path['flag'] == 0) {
                    $msg['flag'] = 'error';
                    $msg['msg'] =  $path['msg'];

                    return $msg;
                }
            }

            $Product = new Product();
            $Product->name = $request->name;
            $Product->description = $request->description;
            // $Product->other_description = !empty($array) ? json_encode($array) : '';
            // $Product->other_description_api = !empty($array_api) ? json_encode($array_api) : '';

            // $Product->tag = !empty($product_tag_json) ? json_encode($product_tag_json) : '';
            // $Product->tag_api = $request->tag;

            $Product->category_id = $request->category_id;
            $Product->cover_image_path = $path['url'];
            $Product->cover_image_url = $path['full_url'];
            $Product->price = $request->price;
            // $Product->discount_type = $request->discount_type;
            // $Product->discount_amount = $request->discount_amount;
            $Product->product_stock = $request->product_stock;
            $Product->variant_product = $request->variant_product;
            $Product->trending = $request->trending;
            $Product->slug = str_replace(' ', '_', strtolower($request->name));
            $Product->status = $request->status;
            $Product->store_id     = $request->store_id;
            $Product->created_by   = $user->id;
            $Product->save();

            foreach ($request->product_image as $key => $image) {
                $theme_image = $image;
                $fileName = rand(10, 100) . '_' . time() . "_" . $image->getClientOriginalName();
                $pathss = Utility::keyWiseUpload_file($request, 'product_image', $fileName, $dir, $key, []);

                $ProductImage = new ProductImage();
                $ProductImage->product_id = $Product->id;
                $ProductImage->image_path = $pathss['url'];
                $ProductImage->image_url  = $pathss['full_url'];
                $ProductImage->store_id   = $request->store_id;
                $ProductImage->save();
            }
            return $this->success(['message' => __('Product added successfully!')]);
        } else {
            return $this->error(['message' => __('Your Product limit is over, Please upgrade plan')]);
        }
    }

    public function UpdateProduct(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'product_id' => 'required',
                'name' => 'required',
                'category_id' => 'required',
                'description' => 'required',
                'cover_image' => 'required',
                'product_image' => 'required',
                'price' => 'required',
                'discount_type' => 'required',
                'discount_amount' => 'required',
                'product_stock' => 'required',
                'trending' => 'required',
                'variant_product' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }


        $dir = 'uploads/' . $store->id;
        $product = Product::find($request->product_id);
        if (!empty($product)) {
            // description array
            $old_data = '';
            if (!empty($product->other_description_api)) {
                $old_data = json_decode($product->other_description_api, true);
            }

            $array = $request->array ?? [];
            $array_api = [];
            foreach ($array as $array_key => $slug) {
                foreach ($slug['inner-list'] as $slug_key => $value) {

                    $array_api[$slug['section_slug']][$slug_key]['field_type'] = $value['field_type'];
                    $array_api[$slug['section_slug']][$slug_key]['name'] = $value['field_name'];
                    $array_api[$slug['section_slug']][$slug_key]['value'] = !empty($value['value']) ? $value['value'] : '';

                    if ($value['field_type'] == 'photo upload') {
                        $theme_image = !empty($value['value']) ? $value['value'] : '';
                        $upload_image_path = !empty($value['value']) ? $value['value'] : '';

                        if (gettype($theme_image) == 'object') {
                            $image_path = (!empty($old_data[$slug['section_slug']][$slug_key]['value'])) ? $old_data[$slug['section_slug']][$slug_key]['value'] : '';
                            if (File::exists(base_path($image_path))) {
                                File::delete(base_path($image_path));
                            }


                            $fileName = rand(10, 100) . '_' . time() . "_" . $theme_image->getClientOriginalName();
                            $upload = Utility::jsonUpload_file($request, 'value', $theme_image, $fileName, $dir, []);

                            if ($upload['flag'] == true) {
                                $upload_image_path = $upload['image_path'];
                            }
                        }

                        $array[$array_key]['inner-list'][$slug_key]['image_path'] = $upload_image_path;
                        $array[$array_key]['inner-list'][$slug_key]['value'] = $upload_image_path;

                        $array_api[$slug['section_slug']][$slug_key]['field_type'] = $value['field_type'];
                        $array_api[$slug['section_slug']][$slug_key]['name'] = $value['field_name'];
                        $array_api[$slug['section_slug']][$slug_key]['value'] = $upload_image_path;
                    }
                }
            }

            if ($request->cover_image) {
                $fileName = rand(10, 100) . '_' . time() . "_" . $request->cover_image->getClientOriginalName();
                $path = Utility::upload_file($request, 'cover_image', $fileName, $dir, []);
                if ($path['flag'] == 0) {
                    $msg['flag'] = 'error';
                    $msg['msg'] =  $path['msg'];

                    return $msg;
                }
                $product->cover_image_path = $path['url'];
                $product->cover_image_url = $path['full_url'];
            }

            $product->name = $request->name;
            $product->description = $request->description;
            // $product->other_description = !empty($array) ? json_encode($array) : '';
            // $product->other_description_api = !empty($array_api) ? json_encode($array_api) : '';

            // $product->tag = !empty($product_tag_json) ? json_encode($product_tag_json) : '';
            // $product->tag_api = $request->tag;
            $product->category_id = $request->category_id;
            $product->price = $request->price;
            // $product->discount_type = $request->discount_type;
            // $product->discount_amount = $request->discount_amount;
            $product->product_stock = $request->product_stock;
            $product->variant_product = $request->variant_product;
            $product->trending = $request->trending;
            $product->slug = str_replace(' ', '_', strtolower($request->name));
            $product->status = $request->status;
            $product->save();

            //sub_image
            if (!empty($request->product_image)) {
                $product_images = ProductImage::where('product_id', $request->product_id)->get();
                if (!empty($product_images)) {
                    foreach ($product_images as $key => $value) {
                        if (File::exists(base_path($value->image_path))) {
                            File::delete(base_path($value->image_path));
                        }
                        $value->delete();
                    }
                }
            }

            foreach ($request->product_image as $key => $image) {
                $theme_image = $image;
                $fileName = rand(10, 100) . '_' . time() . "_" . $image->getClientOriginalName();
                $pathss = Utility::keyWiseUpload_file($request, 'product_image', $fileName, $dir, $key, []);

                $ProductImage = new ProductImage();
                $ProductImage->product_id = $product->id;
                $ProductImage->image_path = $pathss['url'];
                $ProductImage->image_url  = $pathss['full_url'];
                $ProductImage->store_id   = $request->store_id;
                $ProductImage->save();
            }
            return $this->success(['message' => __('Product Updated Successfully!')]);
        } else {
            return $this->error(['message' => __('Product not found!')]);
        }
    }

    public function ViewProduct(Request $request)
    {

        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'product_id' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $ProductDetail = [];

        $Products = Product::find($request->product_id);
        if (!empty($Products)) {
            $ProductDetail['Product'] = $Products;
        } else {
            return $this->error(['message' => __('Product not found')]);
        }

        $subimage = Product::Sub_image($request->product_id);
        if (!empty($subimage)) {
            $ProductDetail['Sub_Image'] = $subimage['data'];
        }

        if (!empty($ProductDetail)) {
            return $this->success($ProductDetail);
        } else {
            return $this->error(['message' => __('Something went wrong!')]);
        }
    }

    public function DeleteOrder(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'order_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        OrderTaxDetail::where('order_id', $request->order_id)->delete();
        OrderCouponDetail::where('order_id', $request->order_id)->delete();
        OrderBillingDetail::where('order_id', $request->order_id)->delete();
        Order::where('id', $request->order_id)->delete();

        return $this->success(['message' => __('Order deleted successfully!.')]);
    }

    public function SearchData(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'type' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        if ($request->type == 'order') {
            $order = Order::where('store_id', $request->store_id)->where('product_order_id', 'like',  '%' . $request->name . '%');
            $Data = $order->get()->toArray();
        }
        if ($request->type == 'category') {
            $category = Category::where('store_id', $request->store_id)->where('name', 'like',  '%' . $request->name . '%');
            $Data = $category->get()->toArray();
        }

        if (!empty($Data)) {
            return $this->success($Data);
        } else {
            return $this->error(['message' => __('not found.')]);
        }
    }

    public function CreateReview(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }
        $ReviewData = [];

        $userarray = [];
        $users = User::where('current_store', $store->id)->get()->toArray();
        if (!empty($users)) {
            foreach ($users as $key => $value) {
                $userarray[$key]['id'] = $value['id'];
                $userarray[$key]['name'] = $value['name'];

                $ReviewData['users'] = $userarray;
            }
        } else {
            return $this->error(['message' => 'Users data not found']);
        }

        return $this->success($ReviewData);
    }

    public function CategoryProduct(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'category_id' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }


        $ProductData = [];

        $productarray = [];
        $products = Product::select('id', 'name')->where('store_id', $store->id)->where('category_id', $request->category_id)->get();

        if (!empty($products)) {
            foreach ($products as $key => $value) {
                $productarray[$key]['id'] = $value['id'];
                $productarray[$key]['name'] = $value['name'];

                $ProductData['products'] = $productarray;
            }
        } else {
            return $this->error(['message' => __('Products data not found')]);
        }

        return $this->success($ProductData);
    }

    public function AddReview(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'product_id' => 'required',
                'user_id' => 'required',
                'rating' => 'required',
                'title' => 'required',
                'description' => 'required'
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $Product = Product::find($request->product_id);
        if (empty($Product)) {
            return $this->error([
                'message' => __('Product not found.')
            ], __("Product not found."));
        }
        $is_Review = Testimonial::where('user_id', $request->user_id)->where('product_id', $request->product_id)->where('store_id', $store->id)->exists();

        if ($is_Review) {
            return $this->error([
                'message' => __('Rating already added.')
            ], __("Rating already added."));
        }
        $review                  = new Testimonial();
        $review->user_id         = $request->user_id;
        $review->category_id     = $Product->category_id;
        $review->product_id      = $request->product_id;
        $review->rating_no       = $request->rating;
        $review->title           = $request->title;
        $review->description     = $request->description;
        $review->status          = $request->status == '1' ? 1 : 0;
        $review->store_id        = $store->id;
        $review->save();

        Testimonial::AvregeRating($request->product_id);


        return $this->success([
            'message' => __('Rating Add successfully.')
        ], __("Rating add successfully."));
    }

    public function DeleteReview(Testimonial $Testimonial)
    {
        $Testimonial->delete();
        return $this->success(['message' => __('Review deleted successfully!.')]);
    }

    public function UpdateReview(Request $request)
    {

        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'id' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $review = Testimonial::find($request->id);

        if (!empty($request->user_id)) {
            $review->user_id        = $request->user_id;
        }
        if (!empty($request->category_id)) {
            $review->category_id    = $request->category_id;
        }
        if (!empty($request->product_id)) {
            $review->product_id      = $request->id;
        }
        if (!empty($request->rating_no)) {
            $review->rating_no          = $request->rating_no;
        }
        if (!empty($request->description)) {
            $review->description    = $request->description;
        }
        if (!empty($request->status)) {
            $review->status         = $request->status;
        }
        $review->save();

        return $this->success(['message' => __('Product Review Updated successfully!')]);
    }

    public function ReviewStatus(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'id' => 'required',

            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $review = Testimonial::find($request->id);
        if (!empty($review)) {

            if ($review->status == '1') {
                $review->status = '0';
                $review->save();
                return $this->success(['message' => __('Product Review status Updated successfully!')]);
            } else {
                $review->status = '1';
                $review->save();
                return $this->success(['message' => __('Product Review status Updated successfully!')]);
            }
        } else {
            return $this->error(['message' => __('Review not found')]);
        }
    }

    public function ProductDropdown(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $categoryDropdown = [];

        $cat_array = [];
        $product = Category::where('store_id', $store->id)->get();
        if (!empty($product)) {
            foreach ($product as $key => $value) {
                $cat_array[$key]['id'] = $value->id;
                $cat_array[$key]['name'] = $value->name;
            }
            $categoryDropdown['category'] = $cat_array;
            return $this->success($categoryDropdown);
        } else {
            return $this->error(['message' => __('Product Data not found.')]);
        }
    }

    public function SearchProduct(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'category_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $products = Product::where('store_id', $request->store_id)->where('category_id', $request->category_id)->where('product_type',null)->get()->toArray();
        if (!empty($products)) {
            return $this->success($products);
        } else {
            return $this->error(['message' => __('Product not found.')]);
        }
    }

    public function ReviewList(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'product_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $reviewlist = Testimonial::where('product_id', $request->product_id)->where('store_id', $store->id)->OrderBy('id', 'desc')->get()->toarray();

        if (!empty($reviewlist)) {
            return $this->success($reviewlist);
        } else {
            return $this->error(['message' => __('Review Data not found.')]);
        }
    }

    public function StaticsData(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $DashboardData = [];

        //visitor chart
        $arrDuration = [];
        $arrParam = ['duration' => 'month'];
        if ($arrParam['duration']) {

            if ($arrParam['duration'] == 'month') {
                $previous_month = strtotime("-2 week +0 day");
                for ($i = 0; $i < 15; $i++) {
                    $arrDuration[date('Y-m-d', $previous_month)] = date('d-M', $previous_month);
                    $previous_month                              = strtotime(date('Y-m-d', $previous_month) . " +1 day");
                }
            }
        }
        $arrTask          = [];
        $i = 0;
        $arrTask[$i]['date'] = [];
        $arrTask[$i]['pageview']  = [];

        foreach ($arrDuration as $date => $label) {
            $data['visitor'] = \DB::table('shetabit_visits')->select(\DB::raw('count(*) as total'))->where('store_id', $store->id)->whereDate('created_at', '=', $date)->first();
            $uniq            = \DB::table('shetabit_visits')->select('ip')->distinct()->where('store_id', $store->id)->whereDate('created_at', '=', $date)->get();

            $data['unique']           = $uniq->count();
            $arrTask[$i]['date']      = $label;
            $arrTask[$i]['pageview']        = $data['visitor']->total;
            $arrTask[$i]['unique_pageview'] = $data['unique'];
            $i++;
        }
        $DashboardData['visitorchart'] = $arrTask;

        //Top url
        $topurl = [];
        $visitor_url   = \DB::table('shetabit_visits')->selectRaw("count('*') as total, url")->where('store_id', $store->id)->groupBy('url')->orderBy('total', 'DESC')->get();
        if (!empty($visitor_url)) {
            foreach ($visitor_url as $key => $value) {

                $topurl[$key]['total_click'] =  $value->total;
                $topurl[$key]['url'] =  $value->url;
            }

            $DashboardData['topurl'] = $topurl;
        }

        //platform chart
        $platform = [];
        $color = [
            '#4942a8',
            '#41c9d6',
            '#f8a21e',
            '#e83e8c',
            '#E9FFDF',
            '#045dff',
            '#c972f5',
            '#df5dab',
            '#8ee332',
            '#8c6576',
            '#366451',
        ];


        $user_platform = \DB::table('shetabit_visits')->selectRaw("count('*') as total, platform")->where('store_id', $store->id)->groupBy('platform')->orderBy('platform', 'DESC')->get();
        if (!empty($user_platform)) {
            $count = count($color);
            $val = 0;
            foreach ($user_platform as $key => $value) {

                $platform[$key]['platform'] = !empty($value->platform) ? $value->platform : 'other';
                $platform[$key]['data'] = $value->total;
                $platform[$key]['colour'] = $color[$val];
                $val += 1;
                if ($val == ($count - 1)) {
                    $val = 0;
                }
            }

            $DashboardData['platform'] = $platform;
        }

        //browser chart
        $browser = [];
        $color = [
            '#4942a8',
            '#41c9d6',
            '#f8a21e',
            '#e83e8c',
            '#E9FFDF',
            '#045dff',
            '#c972f5',
            '#df5dab',
            '#8ee332',
            '#8c6576',
            '#366451',
        ];
        $user_browser  = \DB::table('shetabit_visits')->selectRaw("count('*') as total, browser")->where('store_id', $store->id)->groupBy('browser')->orderBy('browser', 'DESC')->get();
        if (!empty($user_browser)) {
            $count = count($color);
            $val = 0;
            foreach ($user_browser as $key => $value) {
                $browser[$key]['browser'] = !empty($value->browser) ? $value->browser : 'other';
                $browser[$key]['data'] = $value->total;
                $browser[$key]['colour'] = $color[$val];
                $val += 1;
                if ($val == ($count - 1)) {
                    $val = 0;
                }
            }
            $DashboardData['browser'] = $browser;
        }

        //device chart
        $device = [];
        $color = [
            '#4942a8',
            '#41c9d6',
            '#f8a21e',
            '#e83e8c',
            '#E9FFDF',
            '#045dff',
            '#c972f5',
            '#df5dab',
            '#8ee332',
            '#8c6576',
            '#366451',

        ];
        $user_device   = \DB::table('shetabit_visits')->selectRaw("count('*') as total, device")->where('store_id', $store->id)->groupBy('device')->orderBy('device', 'DESC')->get();
        if (!empty($user_device)) {
            $count = count($color);
            $val = 0;
            foreach ($user_device as $key => $value) {
                $device[$key]['device'] = !empty($value->device) ? $value->device : 'other';
                $device[$key]['data'] = $value->total;
                $device[$key]['colour'] = $color[$val];
                $val += 1;
                if ($val == ($count - 1)) {
                    $val = 0;
                }
            }
            $DashboardData['device'] = $device;
        }

        return $this->success($DashboardData);
    }

    public function EditProfile(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }
        $dir        = 'uploads/' . $store->id;

        $rules = [
            'user_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $admin = User::find($request->user_id);
        if (!empty($admin)) {
            if (!empty($request->name)) {
                $admin->name = $request->name;
            }
            if (!empty($request->email)) {
                $admin->email = $request->email;
            }
            if ($request->hasFile('profile_image')) {
                $fileName = rand(10, 100) . '_' . time() . "_" . $request->profile_image->getClientOriginalName();
                $path = Utility::upload_file($request, 'profile_image', $fileName, $dir, []);
                if ($path['flag'] == 0) {
                    $msg['flag'] = 'error';
                    $msg['msg'] =  $path['msg'];

                    return $msg;
                }
                $admin->profile_image = $path['url'];
            }
            $admin->save();
            return $this->success(['message' => __('User Data updated successfully!')]);
        } else {
            return $this->error(['message' => __('User not found.')]);
        }
    }

    public function UpdatePassword(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'user_id' => 'required',
            'old_password' => 'required',
            'password' => 'required|string|min:4|same:password',
            'password_confirmation' => 'required|string|min:4|same:password',
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $admin = User::find($request->user_id);
        $current_password = $admin->password;

        if (!empty($admin)) {
            if (Hash::check($request->old_password, $current_password)) {
                $admin->password = bcrypt($request->password);
                $admin->save();
                return $this->success(['message' => 'User Password updated successfully.']);
            } else {
                return $this->error(['message' => __('Please Enter Correct Current Password!')]);
            }
        } else {
            return $this->error(['message' => __('User not found.')]);
        }
    }

    public function EmailSetting(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $user = Auth::user();
        if ($user) {

            $rules = [
                'mail_driver' => 'required|string|max:50',
                'mail_host' => 'required|string|max:50',
                'mail_port' => 'required|string|max:50',
                'mail_username' => 'required|string|max:50',
                'mail_password' => 'required|string|max:50',
                'mail_encryption' => 'required|string|max:50',
                'mail_from_address' => 'required|string|max:50',
                'mail_from_name' => 'required|string|max:50',
            ];

            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return $this->error([
                    'message' => $messages->first()
                ]);
            }

            $post['MAIL_DRIVER'] = $request->mail_driver;
            $post['MAIL_HOST'] = $request->mail_host;
            $post['MAIL_PORT'] = $request->mail_port;
            $post['MAIL_USERNAME'] = $request->mail_username;
            $post['MAIL_PASSWORD'] = $request->mail_password;
            $post['MAIL_ENCRYPTION'] = $request->mail_encryption;
            $post['MAIL_FROM_NAME'] = $request->mail_from_name;
            $post['MAIL_FROM_ADDRESS'] = $request->mail_from_address;

            foreach ($post as $key => $data) {
                $arr = [
                    $data,
                    $key,
                    $request->store_id,
                    $user->id,
                ];
                \DB::insert(
                    'insert into settings (`value`, `name`,`store_id`,`created_by`) values (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`) ',
                    $arr
                );
            }
            return $this->success(['message' => __('Email Setting successfully updated.')]);

        } else {
            return $this->error(['message' => __('Unauthorized')]);
        }
    }

    public function GetEmailSetting(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $emaildata = [];
        $settings = Setting::where('store_id', $request->store_id)->pluck('value', 'name')->toArray();

        if (!empty($settings)) {
            $emaildata['MAIL_DRIVER'] = isset($settings['MAIL_DRIVER']) ? $settings['MAIL_DRIVER'] : '';
            $emaildata['MAIL_HOST'] = isset($settings['MAIL_HOST']) ? $settings['MAIL_HOST'] : '';
            $emaildata['MAIL_PORT'] = isset($settings['MAIL_PORT']) ? $settings['MAIL_PORT'] : '';
            $emaildata['MAIL_USERNAME'] = isset($settings['MAIL_USERNAME']) ? $settings['MAIL_USERNAME'] : '';
            $emaildata['MAIL_PASSWORD'] = isset($settings['MAIL_PASSWORD']) ? $settings['MAIL_PASSWORD'] : '';
            $emaildata['MAIL_ENCRYPTION'] = isset($settings['MAIL_ENCRYPTION']) ? $settings['MAIL_ENCRYPTION'] : '';
            $emaildata['MAIL_FROM_NAME'] = isset($settings['MAIL_FROM_NAME']) ? $settings['MAIL_FROM_NAME'] : '';
            $emaildata['MAIL_FROM_ADDRESS'] = isset($settings['MAIL_FROM_ADDRESS']) ? $settings['MAIL_FROM_ADDRESS'] : '';
            return $this->success($emaildata);
        } else {
            return $this->error(['message' => __('Email Setting not found!')]);
        }
    }

    public function LoyalitySetting(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $user = Auth::user();
        if ($user) {
            $rules = [
                'loyality_program_enabled' => 'required',
            ];

            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return $this->error([
                    'message' => $messages->first()
                ]);
            }

            $loyality_program_enabled = !empty($request->loyality_program_enabled) ? $request->loyality_program_enabled : 'off';
            $reward_point = !empty($request->reward_point) ? $request->reward_point : 0;

            $post['loyality_program_enabled'] = $loyality_program_enabled;
            $post['reward_point'] = $reward_point;

            foreach ($post as $key => $data) {
                $arr = [
                    $data,
                    $key,
                    $request->store_id,
                    $user->id,
                ];

                \DB::insert(
                    'insert into settings (`value`, `name`,`store_id`,`created_by`) values (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`) ',
                    $arr
                );
            }
            return $this->success(['message' => __('Loyality Setting successfully updated.')]);
        } else {
            return $this->error(['message' => __('Unauthorized')]);
        }
    }

    public function GetLoyalitySetting(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $loyalitydata = [];
        $settings = Setting::where('store_id', $request->store_id)->pluck('value', 'name')->toArray();

        if (!empty($settings)) {
            $loyalitydata['loyality_program_enabled'] = isset($settings['loyality_program_enabled']) ? $settings['loyality_program_enabled'] : '';
            $loyalitydata['reward_point'] = isset($settings['reward_point']) ? $settings['reward_point'] : '';

            return $this->success($loyalitydata);
        } else {
            return $this->error(['message' => __('Loyality Setting not found!')]);
        }
    }

    public function CodPayment(Request $request)
    {

        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $user = Auth::user();
        if ($user) {
            $rules = [
                'CURRENCY_NAME' => 'required',
                'CURRENCY' => 'required',
            ];

            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return $this->error([
                    'message' => $messages->first()
                ]);
            }

            $post['CURRENCY'] = $request->CURRENCY;
            $post['CURRENCY_NAME'] = $request->CURRENCY_NAME;


            $is_cod_enabled = !empty($request->is_cod_enabled) ? $request->is_cod_enabled : 'off';

            if ($request->is_cod_enabled == 'on' && !empty($request->cod_image)) {
                $theme_image = $request->cod_image;
                $image = upload_theme_image($theme_image);
                if ($image['status'] == false) {
                    return $this->error($image['message']);
                } else {
                    $where = ['name' => 'cod_image'];
                    $Setting = Setting::where($where)->first();

                    if (!empty($Setting)) {
                        if (File::exists(base_path($Setting->value))) {
                            File::delete(base_path($Setting->value));
                        }
                    }
                    $post['cod_image'] = $image['image_path'];
                }
            }

            if ($request->is_cod_enabled == 'off') {
                $request->cod_info = '';
            }

            $post['is_cod_enabled'] = $is_cod_enabled;
            $post['cod_info'] = $request->cod_info;


            foreach ($post as $key => $data) {
                $arr = [
                    $data,
                    $key,
                    $request->store_id,
                    $user->id,
                ];

                \DB::insert(
                    'insert into settings (`value`, `name`,`store_id`,`created_by`) values (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`) ',
                    $arr
                );
            }
            return $this->success(['message' => __('Cod payment Setting successfully updated.')]);
        } else {
            return $this->error(['message' => __('Unauthorized')]);
        }
    }

    public function GetCodPayment(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $coddata = [];
        $settings = Setting::where('store_id', $request->store_id)->pluck('value', 'name')->toArray();

        if (!empty($settings)) {
            $coddata['CURRENCY'] = !empty($settings['CURRENCY']) ? $settings['CURRENCY'] : '';
            $coddata['CURRENCY_NAME'] = !empty($settings['CURRENCY_NAME']) ? $settings['CURRENCY_NAME'] : '';
            $coddata['is_cod_enabled'] = !empty($settings['is_cod_enabled']) ? $settings['is_cod_enabled'] : '';
            $coddata['cod_info'] = !empty($settings['cod_info']) ? $settings['cod_info'] : '';
            $coddata['cod_image'] = !empty($settings['cod_image']) ? $settings['cod_image'] : '';

            return $this->success($coddata);
        } else {
            return $this->error(['message' => __('COD Setting Data not found!')]);
        }
    }

    public function BankPayment(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $user = Auth::user();
        if ($user) {
            $rules = [
                'CURRENCY_NAME' => 'required',
                'CURRENCY' => 'required',
            ];

            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return $this->error([
                    'message' => $messages->first()
                ]);
            }

            $post['CURRENCY'] = $request->CURRENCY;
            $post['CURRENCY_NAME'] = $request->CURRENCY_NAME;


            $is_bank_transfer_enabled = !empty($request->is_bank_transfer_enabled) ? $request->is_bank_transfer_enabled : 'off';

            if ($request->is_bank_transfer_enabled == 'on' && !empty($request->bank_transfer_image)) {
                $theme_image = $request->bank_transfer_image;
                $image = upload_theme_image( $theme_image);
                if ($image['status'] == false) {
                    return $this->error($image['message']);
                } else {
                    $where = ['name' => 'bank_transfer_image'];
                    $Setting = Setting::where($where)->first();

                    if (!empty($Setting)) {
                        if (File::exists(base_path($Setting->value))) {
                            File::delete(base_path($Setting->value));
                        }
                    }
                    $post['bank_transfer_image'] = $image['image_path'];
                }
            }

            if ($request->is_bank_transfer_enabled == 'off') {
                $request->bank_transfer = '';
            }

            $post['is_bank_transfer_enabled'] = $is_bank_transfer_enabled;
            $post['bank_transfer'] = $request->bank_transfer;


            foreach ($post as $key => $data) {
                $arr = [
                    $data,
                    $key,
                    $request->store_id,
                    $user->id,
                ];

                \DB::insert(
                    'insert into settings (`value`, `name`,`store_id`,`created_by`) values (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`) ',
                    $arr
                );
            }
            return $this->success(['message' => __('Bank payment Setting successfully updated.')]);
        } else {
            return $this->error(['message' => __('Unauthorized')]);
        }
    }

    public function GetBankPayment(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $bankdata = [];
        $settings = Setting::where('store_id', $request->store_id)->pluck('value', 'name')->toArray();

        if (!empty($settings)) {
            $bankdata['CURRENCY'] = !empty($settings['CURRENCY']) ? $settings['CURRENCY'] : '';
            $bankdata['CURRENCY_NAME'] = !empty($settings['CURRENCY_NAME']) ? $settings['CURRENCY_NAME'] : '';
            $bankdata['is_bank_transfer_enabled'] = !empty($settings['is_bank_transfer_enabled']) ? $settings['is_bank_transfer_enabled'] : '';
            $bankdata['bank_transfer'] = !empty($settings['bank_transfer']) ? $settings['bank_transfer'] : '';
            $bankdata['bank_transfer_image'] = !empty($settings['bank_transfer_image']) ? $settings['bank_transfer_image'] : '';

            return $this->success($bankdata);
        } else {
            return $this->error(['message' => __('Bank Payment Setting Data not found!')]);
        }
    }

    public function StripePayment(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $user = Auth::user();
        if ($user) {
            $rules = [
                'CURRENCY_NAME' => 'required',
                'CURRENCY' => 'required',
            ];

            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return $this->error([
                    'message' => $messages->first()
                ]);
            }

            $post['CURRENCY'] = $request->CURRENCY;
            $post['CURRENCY_NAME'] = $request->CURRENCY_NAME;


            $is_stripe_enabled = !empty($request->is_stripe_enabled) ? $request->is_stripe_enabled : 'off';

            if ($request->is_stripe_enabled == 'on' && !empty($request->stripe_image)) {
                $theme_image = $request->stripe_image;
                $image = upload_theme_image($theme_image);
                if ($image['status'] == false) {
                    return $this->error($image['message']);
                } else {
                    $where = ['name' => 'stripe_image'];
                    $Setting = Setting::where($where)->first();

                    if (!empty($Setting)) {
                        if (File::exists(base_path($Setting->value))) {
                            File::delete(base_path($Setting->value));
                        }
                    }
                    $post['stripe_image'] = $image['image_path'];
                }
            }



            $post['is_stripe_enabled'] = $is_stripe_enabled;
            $post['stripe_unfo'] = !empty($request->stripe_unfo) ? $request->stripe_unfo : '';
            $post['publishable_key'] = !empty($request->publishable_key) ? $request->publishable_key : '';
            $post['stripe_secret'] = !empty($request->stripe_secret) ? $request->stripe_secret : '';

            if ($request->is_stripe_enabled == 'off') {
                $post['stripe_unfo'] = '';
                $post['publishable_key'] = '';
                $post['stripe_secret'] = '';
            }

            foreach ($post as $key => $data) {
                $arr = [
                    $data,
                    $key,
                    $request->store_id,
                    $user->id,
                ];

                \DB::insert(
                    'insert into settings (`value`, `name`,`store_id`,`created_by`) values (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`) ',
                    $arr
                );
            }
            return $this->success(['message' => __('Stripe payment Setting successfully updated.')]);
        } else {
            return $this->error(['message' => __('Unauthorized')]);
        }
    }

    public function GetStripePayment(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $stripedata = [];
        $settings = Setting::where('store_id', $request->store_id)->pluck('value', 'name')->toArray();

        if (!empty($settings)) {
            $stripedata['CURRENCY'] = !empty($settings['CURRENCY']) ? $settings['CURRENCY'] : '';
            $stripedata['CURRENCY_NAME'] = !empty($settings['CURRENCY_NAME']) ? $settings['CURRENCY_NAME'] : '';
            $stripedata['is_stripe_enabled'] = !empty($settings['is_stripe_enabled']) ? $settings['is_stripe_enabled'] : '';
            $stripedata['publishable_key'] = !empty($settings['publishable_key']) ? $settings['publishable_key'] : '';
            $stripedata['stripe_secret'] = !empty($settings['stripe_secret']) ? $settings['stripe_secret'] : '';
            $stripedata['stripe_unfo'] = !empty($settings['stripe_unfo']) ? $settings['stripe_unfo'] : '';
            $stripedata['stripe_image'] = !empty($settings['stripe_image']) ? $settings['stripe_image'] : '';

            return $this->success($stripedata);
        } else {
            return $this->error(['message' => __('Stripe Payment Setting Data not found!')]);
        }
    }

    public function TestMail(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'email' => 'required|email'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $settings = Setting::where('store_id', $request->store_id)->pluck('value', 'name')->toArray();

        if (empty($settings['MAIL_DRIVER'])) {
            return $this->error(['message' => __('Email settings not configured')]);
        }

        try {
            config(
                [
                    'mail.driver' => $settings['MAIL_DRIVER'] ?? '',
                    'mail.host' => $settings['MAIL_HOST'] ?? '',
                    'mail.port' => $settings['MAIL_PORT'] ?? '',
                    'mail.encryption' => $settings['MAIL_ENCRYPTION'] ?? '',
                    'mail.username' => $settings['MAIL_USERNAME'] ?? '',
                    'mail.password' => $settings['MAIL_PASSWORD'] ?? '',
                    'mail.from.address' => $settings['MAIL_FROM_ADDRESS'] ?? '',
                    'mail.from.name' => $settings['MAIL_FROM_NAME'] ?? '',
                ]
            );

            Mail::to($request->email)->send(new TestMail());
        } catch (\Exception $e) {
            $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
            return $this->error(['message' => $smtp_error]);
        }
        return $this->success(['message' => __('Email send Successfully.')]);
    }

    public function ChangeStore(Request $request)
    {
        $user = \Auth::user();

        $rules = [
            'store_id' => 'required',
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $store = Store::find($request->store_id);
        if (!empty($store)) {
            $user->current_store = $store->id;
            $user->update();
            return $this->success(['message' => __('Store Change Successfully!')]);
        } else {
            return $this->error(['message' => __('Store not found!')]);
        }
    }

    public function ViewUsedCoupon(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'coupon_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }


        $UserCoupons = UserCoupon::where('coupon_id', $request->coupon_id)->get()->toArray();
        if (!empty($UserCoupons)) {
            return $this->success($UserCoupons);
        } else {
            return $this->error(['message' => __('Data not found!')]);
        }
    }






public function DeleteStore(Request $request)
{
    $request->validate([
        'store_id' => ['required', 'integer'],
    ]);

    $user = \Auth::user();

    if (!$user) {
        return $this->error([
            'message' => __('Unauthenticated user!'),
        ]);
    }

    $store = Store::where('id', $request->store_id)
        ->where('created_by', $user->id)
        ->first();

    if (!$store) {
        return $this->error([
            'message' => __('Store not found or you are not authorized to delete it!'),
        ]);
    }

    /*
     * منع المستخدم من حذف آخر متجر يملكه.
     */
    $storesCount = Store::where('created_by', $user->id)->count();

    if ($storesCount <= 1) {
        return $this->error([
            'message' => __('You cannot delete your last store. Create another store first.'),
        ]);
    }

    try {
        \DB::beginTransaction();

        Page::where('store_id', $store->id)->delete();
        Order::where('store_id', $store->id)->delete();
        AppSetting::where('store_id', $store->id)->delete();
        Blog::where('store_id', $store->id)->delete();
        Contact::where('store_id', $store->id)->delete();
        Coupon::where('store_id', $store->id)->delete();
        Category::where('store_id', $store->id)->delete();
        Newsletter::where('store_id', $store->id)->delete();
        PlanOrder::where('store_id', $store->id)->delete();
        Testimonial::where('store_id', $store->id)->delete();
        Setting::where('store_id', $store->id)->delete();
        Shipping::where('store_id', $store->id)->delete();
        Tax::where('store_id', $store->id)->delete();
        ProductVariant::where('store_id', $store->id)->delete();

        /*
         * حذف المنتجات وصورها باستخدام الدالة الموجودة.
         */
        $products = Product::where('store_id', $store->id)->get();

        $productController = new ProductController();

        foreach ($products as $product) {
            $productController->destroy($product);
        }

        /*
         * حذف المتجر.
         */
        $deletedStoreId = $store->id;

        $store->delete();

        /*
         * اختيار متجر بديل بعد الحذف.
         */
        $nextStore = Store::where('created_by', $user->id)
            ->where('id', '!=', $deletedStoreId)
            ->first();

        /*
         * هذا الشرط احتياطي، والمفروض دائمًا يوجد متجر لأننا منعنا
         * حذف آخر متجر.
         */
        if (!$nextStore) {
            \DB::rollBack();

            return $this->error([
                'message' => __('No alternative store was found.'),
            ]);
        }

        /*
         * لا نغيّر المتجر الحالي إلا إذا كان هو المتجر المحذوف.
         */
        if ((int) $user->current_store === (int) $deletedStoreId) {
            $user->current_store = $nextStore->id;
            $user->save();
        }

        \DB::commit();

        $userData = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'current_store' => $user->current_store,
        ];

        return $this->success([
            'message' => __('Store deleted successfully!'),
            'user_info' => $userData,
        ]);
    } catch (\Throwable $exception) {
        \DB::rollBack();

        \Log::error('Store deletion failed.', [
            'user_id' => $user->id,
            'store_id' => $request->store_id,
            'message' => $exception->getMessage(),
        ]);

        return $this->error([
            'message' => __('An error occurred while deleting the store.'),
        ]);
    }
}






    public function DeleteStore_backup(Request $request)
    {

        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $user = \Auth::user();

        Page::where('store_id', $store->id)->delete();
        Order::where('store_id', $store->id)->delete();
        AppSetting::where('store_id', $store->id)->delete();
        Blog::where('store_id', $store->id)->delete();
        Contact::where('store_id', $store->id)->delete();
        Coupon::where('store_id', $store->id)->delete();
        Category::where('store_id', $store->id)->delete();
        Newsletter::where('store_id', $store->id)->delete();
        PlanOrder::where('store_id', $store->id)->delete();
        Testimonial::where('store_id', $store->id)->delete();
        Setting::where('store_id', $store->id)->delete();
        Shipping::where('store_id', $store->id)->delete();
        Tax::where('store_id', $store->id)->delete();
        ProductVariant::where('store_id', $store->id)->delete();

        $products = Product::where('store_id', $store->id)->get();
        $pro_img = new ProductController();
        foreach ($products as $pro) {
            $pro_img->destroy($pro);
        }

        $store->delete();

        $userstore = Store::where('created_by', $user->id)->first();

        $user->current_store = $userstore->id;
        $user->save();

        $user_data['id'] = $user->id;
        $user_data['name'] = $user->name;
        $user_data['email'] = $user->email;
        $user_data['current_store'] = $user->current_store;

        return $this->success(['message' => __('Store deleted successfully!'), 'user_info' => $user_data]);
    }

    //Delivery APP
    //login

    public function deliveryLogin(Request $request)
    {
        $rules = [
            'email' => 'required|email',
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        if (!empty($request->password)) {
            $user = Auth::guard('deliveryboy')->attempt(['email' => $request->email, 'password' => $request->password]);
            if (!$user) {
                return $this->error(['message' => 'Invalid login details']);
            }
            $user = Auth::guard('deliveryboy')->user();

            $user = DeliveryBoy::find($user->id);
        } else {
            return $this->error(['message' => 'Invalid login details']);
        }

        $user_data = DeliveryBoy::find($user->id);

        $user_array['currency'] = \App\Models\Utility::GetValByName('CURRENCY', $user_data->store_id);
        $user_array['currency_name'] = \App\Models\Utility::GetValByName('CURRENCY_NAME', $user_data->store_id);


        $user_array['id'] = $user_data->id;
        $user_array['name'] = $user_data->name;
        $user_array['email'] = $user_data->email;
        $user_array['image'] = !empty($user_data->profile_image) ? $user_data->profile_image : "/storage/uploads/avatar.png";
        $user_array['contact'] = $user_data->contact;

        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;
        $user_array['token'] = $token;
        $user_array['token_type'] = 'Bearer';
        return $this->success($user_array);
    }

    public function DeliveryBoyOrderList(Request $request)
    {
        $user  = auth('deliveryboy')->user();
        
        if ($user) {
            if ($request->type == 'pending') {
                $orders = Order::select('id', 'product_order_id as product_order_id', 'order_date as date', 'final_price as amount', 'delivered_status', 'delivery_date', 'payment_status')
                    ->where('deliveryboy_id', $user->id)
                    ->whereIn('delivered_status', [0, 4, 5, 6])
                    ->paginate(10);
            }
            if ($request->type == 'complete') {
                $orders = Order::select('id', 'product_order_id as product_order_id', 'order_date as date', 'final_price as amount', 'delivered_status', 'delivery_date', 'payment_status')
                    ->where('deliveryboy_id', $user->id)
                    ->where('delivered_status', 1)
                    ->paginate(10);
            }
            if ($request->type == 'cancel') {
                $orders = Order::select('id', 'product_order_id as product_order_id', 'order_date as date', 'final_price as amount', 'delivered_status', 'delivery_date', 'payment_status')
                    ->where('deliveryboy_id', $user->id)
                    ->where('delivered_status', 2)
                    ->paginate(10);
            }

            if (!empty($orders)) {
                $orders->load('billingData');
                return $this->success($orders);
            } else {
                return $this->error(['message' => __('Order Data not found.')]);
            }
        } else {
            return $this->error(['message' => __('Unauthorized')]);
        }
    }

    public function orderDetail(Request $request)
    {
        $validator = \Validator::make(
            $request->all(),
            [
                'order_id' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $order = Order::order_detail($request->order_id);

        if (!empty($order['message'])) {
            return $this->error($order);
        } else {
            return $this->success($order);
        }
    }

    public function statusChange(Request $request)
    {
        $validator = \Validator::make(
            $request->all(),
            [
                'id' => 'required',
                'delivered' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $data['order_id'] = $request->id;
        $data['order_status'] = $request->delivered;
        $responce = Order::order_status_change($data);

        $order = Order::find($request->id);
        if ($order->delivered_status == 0) {
            $responce['order_status'] = __('Pending');
        }
        if ($order->delivered_status == 1) {
            $responce['order_status'] = __('Delivered');
        }
        if ($order->delivered_status == 2) {
            $responce['order_status'] = __('Cancel');
        }
        if ($order->delivered_status == 4) {
            $responce['order_status'] = 'pickedup';
        }
        if ($order->delivered_status == 5) {
            $responce['order_status'] = 'shipped';
        }
        if ($order->delivered_status == 6) {
            $responce['order_status'] = 'delivered';
        }
        if ($order->delivered_status == 8) {
            $responce['order_status'] = __('Pre Order');
        }

        if ($responce) {
            return $this->success($responce);
        } else {
            return $this->error(['message' => __('Something went wrong.')]);
        }
    }

    public function deliveryHome(Request $request)
    {
        $user  = auth('deliveryboy')->user();
        
        if ($user) {
            $counterData = [];
            $total_pending = Order::where('deliveryboy_id', $user->id)
                ->whereIn('delivered_status', [0, 4, 5, 6])
                ->count();

            $total_complete = Order::where('deliveryboy_id', $user->id)
                ->where('delivered_status', 1)
                ->count();

            $total_cancel = Order::where('deliveryboy_id', $user->id)
                ->where('delivered_status', 2)
                ->count();

            $counterData['pending'] = $total_pending;
            $counterData['complete'] = $total_complete;
            $counterData['cancel'] = $total_cancel;

            //total earning
            $total_earning = Order::select(\DB::raw('SUM(final_price) as total_amount'))
                ->where('deliveryboy_id', $user->id)
                ->get();

            $counterData['total_earning'] =  (int)($total_earning->isEmpty() ? 0 : $total_earning->first()->total_amount);

            //today earning
            $today_earning = Order::select(\DB::raw('SUM(final_price) as total_amount'))
                ->where('deliveryboy_id', $user->id)
                ->whereRaw('DATE(order_date) = CURDATE()')
                ->get();

            $counterData['today_earning'] =  (int)($today_earning->isEmpty() ? 0 : $today_earning->first()->total_amount);

            //order
            $assign_order = Order::orderBy('created_at', 'Desc')
                ->select('id', 'product_order_id as product_order_id', 'order_date as date', 'final_price as amount', 'delivered_status', 'delivery_date', 'payment_type', 'payment_status')
                ->where('deliveryboy_id', $user->id)
                ->limit('5')
                ->get();

            $counterData['transaction'] = $assign_order;

            return $this->success($counterData);
        } else {
            return $this->error(['message' => __('Unauthorized')]);
        }
    }

    public function deliveryTransaction(Request $request)
    {
        $user  = auth('deliveryboy')->user();
        
        if ($user) {
            $transactions = Order::select('id', 'product_order_id as product_order_id', 'order_date as date', 'final_price as amount', 'delivered_status', 'delivery_date', 'payment_status', 'payment_type')
                ->where('deliveryboy_id', $user->id)
                ->paginate(10);

            //total earning
            $total_earning = Order::select(\DB::raw('SUM(final_price) as total_amount'))
                ->where('deliveryboy_id', $user->id)
                ->get();

            $counterData['total_earning'] =  (int)($total_earning->isEmpty() ? 0 : $total_earning->first()->total_amount);

            
            //today earning
            $today_earning = Order::select(\DB::raw('SUM(final_price) as total_amount'))
                ->where('deliveryboy_id', $user->id)
                ->whereRaw('DATE(order_date) = CURDATE()')
                ->get();

            $counterData['today_earning'] =  (int)($today_earning->isEmpty() ? 0 : $today_earning->first()->total_amount);

            if (!empty($transactions)) {
                $counterData['transaction'] = $transactions;
            }

            return $this->success($counterData);
        } else {
            return $this->error(['message' => __('Unauthorized')]);
        }
    }

    public function changeProfile(Request $request)
    {
        $rules = [
            'id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $deliveryboy = DeliveryBoy::find($request->id);

        $dir        = 'uploads';

        if (!empty($deliveryboy)) {
            if (!empty($request->name)) {
                $deliveryboy->name = $request->name;
            }
            if (!empty($request->email)) {
                $deliveryboy->email = $request->email;
            }
            if (!empty($request->contact)) {
                $deliveryboy->contact = $request->contact;
            }

            if ($request->hasFile('profile_image')) {
                $fileName = rand(10, 100) . '_' . time() . "_" . $request->profile_image->getClientOriginalName();
                $path = Utility::upload_file($request, 'profile_image', $fileName, $dir, []);
                if ($path['flag'] == 0) {
                    $msg['flag'] = 'error';
                    $msg['msg'] =  $path['msg'];

                    return $msg;
                }
                $deliveryboy->profile_image = $path['url'];
            }
            $deliveryboy->save();

            $user_array['id'] = $deliveryboy->id;
            $user_array['name'] = $deliveryboy->name;
            $user_array['email'] = $deliveryboy->email;
            $user_array['contact'] = $deliveryboy->contact;
            $user_array['image'] = !empty($deliveryboy->profile_image) ? $deliveryboy->profile_image : "/storage/uploads/avatar.png";

            return $this->success($user_array);
        } else {
            return $this->error(['message' => __('Delivery Boy not found.')]);
        }
    }

    public function logout(Request $request)
    {
        $user = DeliveryBoy::find($request->user_id);
        if (!empty($user)) {
            return $this->success([
                'message' => __('User Logout'),
                'logout' => $user->tokens()->delete()
            ]);
        } else {
            return $this->error([
                'message' => __('User not found')
            ]);
        }
    }

    public function delete_user(Request $request)
    {
        $rules = [
            'user_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $user = DeliveryBoy::find($request->user_id);
        if (!empty($user)) {
            $user->delete();

            return $this->success(['message' => __('User Deleted successfully')]);
        } else {
            return $this->error(['message' => __('User not found.')]);
        }
    }

    public function orderCancel(Request $request)
    {
        $validator = \Validator::make(
            $request->all(),
            [
                'order_id' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $user = DeliveryBoy::find($request->user_id);
        $order = Order::find($request->order_id);

        if (!empty($order)) {
            if ($order->delivered_status == '0') {
                if ($user && $order->deliveryboy_id == $user->id) {
                    $order->deliveryboy_id = null; // Removed quotes around null
                    $order->delivered_status = 2;
                    $order->save();
                    return $this->success(['message' => __('Order cancel Successfully.')]);
                } else {
                    $order->delivered_status = 2;
                    $order->save();
                    return $this->success(['message' => __('Order cancel Successfully.')]);
                }
            } else {
                return $this->error(['message' => __('You cannot cancel this order.')]);
            }
        } else {
            return $this->error(['message' => __('Order not found.')]);
        }
    }
    public function AddProductBrand(Request $request)
    {
        $user = Auth::user();
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }
        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required',
                'logo' => 'required'
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $slug = ProductBrand::slugs($request->name);

        $url = null;
        if ($request->logo) {
            $dir        = 'uploads/' . getCurrentStore();
            $image_size = $request->file('logo')->getSize();
            $result = Utility::updateStorageLimit($user->creatorId(), $image_size);
            if ($result == 1) {
                $fileName = rand(10, 100) . '_' . time() . "_" . $request->logo->getClientOriginalName();
                $path = Utility::upload_file($request, 'logo', $fileName, $dir, []);
                
                if ($path['flag'] == 1) {
                    $url = $path['url'];
                } else {
                    return $this->error([
                        'msg' => $path['msg']
                    ]);
                }
            } else {
                return $this->error([
                    'result' => $result
                ]);
            }
        }

        $productBrand                      = new ProductBrand();
        $productBrand->name                = $request->name;
        $productBrand->logo                = $url;
        $productBrand->slug                = $slug;
        $productBrand->status              = $request->status;
        $productBrand->is_popular          = $request->is_popular;
        $productBrand->store_id            = getCurrentStore();
        $productBrand->created_by          = $user->id;
        $productBrand->save();
        return $this->success([
            'message' => __('Product Brand successfully created.')
        ]);
    }
    public function UpdateProductBrand(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }
        $validator = \Validator::make(
            $request->all(),
            [
                'productbrand_id' => 'required',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $productBrand = ProductBrand::find($request->productbrand_id);

        $url = null;
        if ($request->logo) {
            $file_path = $productBrand->logo;
            $dir        = 'uploads/' . getCurrentStore();
            $image_size = $request->file('logo')->getSize();
            $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $image_size);
            if ($result == 1) {
                Utility::changeStorageLimit(\Auth::user()->creatorId(), $file_path);
                $fileName = rand(10, 100) . '_' . time() . "_" . $request->logo->getClientOriginalName();
                $path = Utility::upload_file($request, 'logo', $fileName, $dir, []);
                if ($path['flag'] == 1) {
                    $url = $path['url'];
                } else {
                    return $this->error([
                        'msg' => $path['msg']
                    ]);
                }
            } else {
                return $this->error([
                    'result' => $result
                ]);
            }
        }

        $productBrand->name                = $request->name;
        if ($url) {
            $productBrand->logo                = $url;
        }
        if ($request->status) {
            $productBrand->status              = $request->status;
        }
        if ($request->is_popular) {
            $productBrand->is_popular          = $request->is_popular;
        }
        $productBrand->save();

        return $this->success([
            'message' => __('Product Brand successfully updated.')
        ]);
    }

    public function DeleteProductBrand(Request $request)
    {
        $store = Store::find($request->store_id);

        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'productbrand_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $productBrand = ProductBrand::find($request->productbrand_id);

        if (!empty($productBrand)) {
            $productBrand->delete();
            return $this->success(['message' => __('Product Brand deleted successfully!.')]);
        } else {
            return $this->error(['message' => __('Product Brand data not found.')]);
        }
    }
    public function ChangeStatus(Request $request)
    {
        $store = Store::find($request->store_id);

        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }
        $rules = [
            'productbrand_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $productBrand = ProductBrand::find($request->productbrand_id);

        if ($productBrand) {
            $productBrand->status = $request->status;
            $productBrand->save();
            $return['status'] = 'success';
            $return['message'] = __('Status change successfully.');
            return response()->json($return);
        } else {
            $return['status'] = 'error';
            $return['message'] = __('Something went wrong!!');
            return response()->json($return);
        }
    }
    public function ChangePopular(Request $request)
    {
        $store = Store::find($request->store_id);

        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }
        $rules = [
            'productbrand_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $productBrand = ProductBrand::find($request->productbrand_id);

        if ($productBrand) {
            $productBrand->is_popular = $request->is_popular;
            $productBrand->save();
            $return['status'] = 'success';
            $return['message'] = __('Status change successfully.');
            return response()->json($return);
        } else {
            $return['status'] = 'error';
            $return['message'] = __('Something went wrong!!');
            return response()->json($return);
        }
    }
    public function AddProductLable(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }
        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required'
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $user = Auth::user();
        if ($user) {

            $slug = ProductLabel::slugs($request->name);
            
            $productLabel                      = new ProductLabel();
            $productLabel->name                = $request->name;
            $productLabel->slug                = $slug;
            $productLabel->status              = $request->status;
            $productLabel->store_id            = getCurrentStore();
            $productLabel->created_by          = $user->id;
            $productLabel->save();
            return $this->success([
                'message' => __('Product Label successfully created.')
            ]);
        } else {
            return $this->error(['message' => __('Unauthorized')]);
        }
    }
    public function UpdateProductLable(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }

        $rules = [
            'lable_id' => 'required',
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $productLabel = ProductLabel::find($request->lable_id);
        $productLabel->name                = $request->name;
        if ($request->status) {
            $productLabel->status              = $request->status;
        }
        $productLabel->store_id            = getCurrentStore();
        $productLabel->save();

        return $this->success([
            'message' => __('Product Label successfully updated.')
        ]);
    }
    public function DeleteProductLable(Request $request)
    {
        $store = Store::find($request->store_id);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }
        $rules = [
            'lable_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }

        $productLabel = ProductLabel::find($request->lable_id);
        if (!empty($productLabel)) {
            $productLabel->delete();

            return $this->success(['message' => __('Product Label Deleted successfully')]);
        } else {
            return $this->error(['message' => __('Product Label data not found.')]);
        }
    }
    public function LableChangeStatus(Request $request)
    {
        $store = Store::find($request->store_id);

        if (empty($store)) {
            return $this->error(['message' => __('Store not found!')]);
        }
        $rules = [
            'lable_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $productLabel = ProductLabel::find($request->lable_id);

        if ($productLabel) {
            $productLabel->status = $request->status;
            $productLabel->save();
            $return['status'] = 'success';
            $return['message'] = __('Status change successfully.');
            return response()->json($return);
        } else {
            $return['status'] = 'error';
            $return['message'] = __('Something went wrong!!');
            return response()->json($return);
        }
    }
}