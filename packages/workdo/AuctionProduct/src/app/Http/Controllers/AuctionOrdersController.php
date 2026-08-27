<?php

namespace Workdo\AuctionProduct\app\Http\Controllers;

use App\Models\DeliveryBoy;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\OrderRefund;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\AuctionProduct\app\Models\AuctionProductOrder;
use Illuminate\Support\Facades\Crypt;
use Workdo\AuctionProduct\app\Models\AuctionProductBid;
use Workdo\AuctionProduct\DataTables\AuctionProductOrderData;
use Illuminate\Support\Facades\Cache;

class AuctionOrdersController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index(AuctionProductOrderData $dataTable)
    {
        if (!auth()->user()) {
			return redirect()->route('login');
		}
        if (auth()->user() && auth()->user()->isAbleTo('Manage Auction Product Order')) {
            $store = Store::find(getCurrentStore());
            $enable_auction_product = \App\Models\Utility::GetValueByName('enable_auction_product', $store->id);
            if(isset($enable_auction_product) && $enable_auction_product == 'on')
            {
                $orders = AuctionProductOrder::where('store_id', $store->id)->get();

                return $dataTable->render('auction-product::auctionorder.index',compact('orders'));
            }else {
                return redirect()->route('dashboard')->with('error', __('Permission denied.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        return view('auction-product::auctionorder.create');
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('auction-product::auctionorder.show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('auction-product::auctionorder.edit');
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     * @param int $id
     * @return Renderable
     */
    public function destroy($id)
    {
        $order = AuctionProductOrder::find($id);
        if($order)
        {
            $order->delete();

            return redirect()->back()->with('success', __('Order delete successfully.'));
        }else{
            return redirect()->back()->with('error', __('The Auction order is not found.'));
        }
       
    }

    public function order_view(Request $request, $id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Show Order')) {
            try {
                $id = Crypt::decrypt($id);
                $order = AuctionProductOrder::order_detail($id);
                $order_data = AuctionProductOrder::find($id);
                $store_id = getStoreById(getCurrentStore());
                $order_notes = OrderNote::where('order_id',$id)->where('store_id', getCurrentStore())->get();
                $store = Store::where('id' ,getCurrentStore())->first();
                $deliveryboys = DeliveryBoy::where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Assign to deliveryboy',"");
                if (!empty($order['message'])) {
                    return redirect()->back()->with('error', __('Order Not Found.'));
                }
                return view('auction-product::auctionorder.view', compact('order','store','order_notes','deliveryboys','order_data'));
            } catch (DecryptException $e) {
                return redirect()->back()->with('error', __('Something was wrong.'));
            }
            catch (\Exception $e) {
                return redirect()->back()->with('error', __('Something was wrong.'));
             }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function orderdetails($slug , $order_id)
    {
        try {
            $id = Crypt::decrypt($order_id);
            $order = AuctionProductOrder::order_detail($id);
            $store = getStore($slug);
            $order_data =  AuctionProductOrder::where('id' ,$id)->where('store_id',$store->id)->first();
            if (!$order_data) {
                abort(404);
            }
            
            $order_note = OrderNote::where('order_id', $order['id'])
            ->where('note_type', 'to_customer')
            ->get();

            if(!empty($order['message'])) {
                return redirect()->back()->with('error', __('Order Not Found.'));
            }

            return view('front_end.pages.auction_order_detail', compact('slug','order','store','order_data','order_note'));

        } catch (DecryptException $e) {
            return redirect()->back()->with('error', __('Something was wrong.'));
        }
    }

    public function order_assign(Request $request)
    {
        $order =AuctionProductOrder::find($request->order_id);
        if($order)
        {
        $order->delivery_id =$request->delivery_boy;
        $order->save();
        $return['status'] = 'success';
        $return['message'] = 'Order assigned successfully';
        return response()->json($return);
        }
        else{
            $return['status'] = 'error';
            $return['message'] = 'The Auction order is not found.';
            return response()->json($return);
        }

    }

    public function order_status_change(Request $request)
    {
        $data['order_id'] = $request->id;
        $data['order_status'] = $request->delivered;

        $responce = AuctionProductOrder::order_status_change($data);
        $order = AuctionProductOrder::order_detail($data['order_id']);
        $d_order = AuctionProductOrder::find($data['order_id']);
        $store = getStoreById($d_order->store_id);
        $dArr  = [
            'order_id' => $data['order_id'],
            'order_status' => $data['order_status']
        ];
        $owner= User::find($store->created_by);
        try
        {
            $order_id = Crypt::encrypt($data['order_id']);
            $resp  = Utility::sendEmailTemplate('Status Change', $order['delivery_informations']['email'], $dArr,$owner, $store, $order_id);
        }
        catch(\Exception $e)
        {
            $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
        }
        try
        {
            $mobile_no =$order['delivery_informations']['phone'];
            $msg = __("Hello, Welcome to $store->name .Your Order is $request->delivered, !Hi $request->id, Thank you for Shopping");

            $resp  = Utility::SendMsgs('Status Change', $mobile_no, $msg);
        }
        catch(\Exception $e)
        {
            $smtp_error = __('Invalid OAuth access token - Cannot parse access token');
        }

        if ($responce['status'] == 'success') {
            $module = 'Status Change';
            $store = Store::find(getCurrentStore());
            $webhook =  Utility::webhook($module, $store->id);
            if ($webhook) {
                $order = Order::order_detail($request->id);
                $parameter = json_encode($order);

                // 1 parameter is  URL , 2 parameter is data , 3 parameter is method
                $status = Utility::WebhookCall($webhook['url'], $parameter, $webhook['method']);
                if ($status != true) {
                    $msgs  = __('Webhook call failed.');
                }
            }

            $return['status'] = 'success';
            $return['message'] = $responce['message']. ((isset($msgs)) ? '<br> <span class="text-danger">' . $msgs . '</span>' : '');
            return response()->json($return);
        } else {
            $return['status'] = 'error';
            $return['message'] = $responce['message'];
            return response()->json($return);
        }

    }

    public function order_return(Request $request)
    {
        $data['product_id'] = $request->product_id;
        $data['variant_id'] = $request->variant_id;
        $data['order_id']   = $request->order_id;

        $responce = AuctionProductOrder::product_return($data);
        if ($responce['status'] == 'success') {
            $return['status'] = 'success';
            $return['message'] = $responce['message'];
            return response()->json($return);
        } else {
            $return['status'] = 'error';
            $return['message'] = $responce['message'];
            return response()->json($return);
        }
    }


    public function order_receipt($id)
    {
        try {
            $id = Crypt::decrypt($id);
            $order = AuctionProductOrder::order_detail($id);
            $order_data = AuctionProductOrder::find($id);
            if(!empty($order['message'])) {
                return redirect()->back()->with('error', __('Order Not Found.'));
            }
            return view('auction-product::auctionorder.receipt', compact('order','order_data'));

        } catch (DecryptException $e) {
            return redirect()->back()->with('error', __('Something was wrong.'));
        }
        catch (\Exception $e) {
            return redirect()->back()->with('error', __('Something was wrong.'));
         }
    }

    public function shippinglabel(Request $request ,$id)
    {
        try{
            $id =crypt::decrypt($id);
            $order = AuctionProductOrder::order_detail($id);
            $settings = Utility::Setting();
            $product = [];
            $products = [];
            if($order['product']['variant_id'] == '0')
            $product[] = Product::where('id' ,$order['product_id'])->pluck('product_weight','id')->first();

            else{
                $products[] = ProductVariant::where('id' ,$order['product']['variant_id'])->pluck('weight' ,'id')->first();

            }
            $newArray = [];

            foreach ($product as $key => $value) {
                $newArray[$key] = intval($value);
            }
            $product = array_merge($newArray ,$products);
            $product_sum =array_sum($product);



            if(!empty($order['message'])) {
                return redirect()->back()->with('error', __('Order Not Found.'));
            }
            return view('auction-product::auctionorder.shippinglabel', compact('order','product_sum','settings'));

        } catch (DecryptException $e) {
            return redirect()->back()->with('error', __('Something was wrong.'));
        }
    }

    public function order_list(Request $request, $slug)
    {
        $store = getStore($slug);
        
        if (auth('customers')->user()) {

        $orders = AuctionProductOrder::where('customer_id', auth('customers')->user()->id)->orderBy('id', 'desc')->where('store_id', $store->id)->paginate(10);
        $bid_product = AuctionProductBid::where('customer_id',auth('customers')->user()->id)->where('store_id',$store->id)->orderBy('id', 'desc')->paginate(10);
        } else {
            $orders = collect();
            $bid_product = collect();
        }
        $currency = Utility::GetValueByName('CURRENCY_NAME');
        $order_refunds = OrderRefund::where('store_id', $store->id)->get();
        $return['html'] = view('auction-product::auctionorder.tabs_view', compact('orders', 'order_refunds','store','currency','bid_product'))->render();
        return response()->json($return);
    }
}
