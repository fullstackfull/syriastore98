<?php

namespace Workdo\LotteryPrizes\app\Http\Controllers;

use App\Models\DeliveryBoy;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Workdo\LotteryPrizes\DataTables\LotteryOrderData;
use Illuminate\Support\Facades\Crypt;
use Workdo\LotteryPrizes\app\Models\PurchaseTicket;

class LotteryOrderController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index(LotteryOrderData $dataTable)
    {
        if (!auth()->user()) {
			return redirect()->route('login');
		}
        // if (auth()->user() && auth()->user()->isAbleTo('Manage Lottery Order')) {
            $store = Store::find(getCurrentStore());
            $orders = Order::where('store_id', $store->id)->where('product_order_status','lottery_order')->get();
            return $dataTable->render('lottery-prizes::admin.lottery-order.index',compact('orders'));
            
        // } else {
        //     return redirect()->back()->with('error', __('Permission denied.'));
        // }
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        return view('lottery-prizes::create');
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
        return view('lottery-prizes::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('lottery-prizes::edit');
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
        //
    }

    public function order_view(Request $request, $id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Show Order')) {
            try {
                $id = Crypt::decrypt($id);
                $order = Order::find($id);
                $lottery = PurchaseTicket::where('lottery_code',$order->lottery_code)->first();
                $store_id = getStoreById(getCurrentStore());
                $order_notes = OrderNote::where('order_id',$id)->where('store_id', getCurrentStore())->get();
                $store = Store::where('id' ,getCurrentStore())->first();
                $deliveryboys = DeliveryBoy::where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Assign to deliveryboy',"");
                if (empty($order)) {
                    return redirect()->back()->with('error', __('Order Not Found.'));
                }
                return view('lottery-prizes::admin.lottery-order.view', compact('store','order_notes','deliveryboys','order','lottery'));
            }
            catch (\Exception $e) {
                return redirect()->back()->with('error', __('Something was wrong.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function order_status_change(Request $request)
    {
        $data['order_id'] = $request->id;
        $data['order_status'] = $request->delivered;
        
        $responce = Order::order_status_change($data);
        $d_order = Order::find($data['order_id']);
        $lottery = PurchaseTicket::where('lottery_code',$d_order->lottery_code)->first();
        $store = getStoreById($d_order->store_id);
        $dArr  = [
            'order_id' => $data['order_id'],
            'order_status' => $data['order_status']
        ];
        $owner= User::find($store->created_by);
        try
        {
            $order_id = Crypt::encrypt($data['order_id']);
            $resp  = Utility::sendEmailTemplate('Status Change', $lottery->email, $dArr,$owner, $store, $order_id);
        }
        catch(\Exception $e)
        {
            $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
        }
        try
        {
            $mobile_no =$lottery->number;
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

            $return['status'] = 'success';
            $return['message'] = $responce['message']. ((isset($msgs)) ? '<br> <span class="text-danger">' . $msgs . '</span>' : '');
            return response()->json($return);
        } else {
            $return['status'] = 'error';
            $return['message'] = $responce['message'];
            return response()->json($return);
        }

    }

    public function shippinglabel(Request $request ,$id)
    {
        try{
            $id =crypt::decrypt($id);
            $order = Order::find($id);
            $settings = Utility::Setting();
            $product = [];
            $products = [];
            $product[] = Product::where('id' ,$order->product_id)->pluck('product_weight','id')->first();
            $newArray = [];

            foreach ($product as $key => $value) {
                $newArray[$key] = intval($value);
            }
            $product = array_merge($newArray ,$products);
            $product_sum =array_sum($product);
            $lottery = PurchaseTicket::where('lottery_code',$order->lottery_code)->first();
            if(empty($order)) {
                return redirect()->back()->with('error', __('Order Not Found.'));
            }
            return view('lottery-prizes::admin.lottery-order.shippinglabel', compact('order','product_sum','settings','lottery'));

        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Something was wrong.'));
        }
    }

    public function order_receipt($id)
    {
        try {
            $id = Crypt::decrypt($id);
            $order = Order::find($id);
            if(empty($order)) {
                return redirect()->back()->with('error', __('Order Not Found.'));
            }
            return view('lottery-prizes::admin.lottery-order.receipt', compact('order'));

        }
        catch (\Exception $e) {
            return redirect()->back()->with('error', __('Something was wrong.'));
        }
    }

    public function order_return(Request $request)
    {
        $data['product_id'] = $request->product_id;
        $data['variant_id'] = $request->variant_id;
        $data['order_id']   = $request->order_id;

        $responce = Order::product_return($data);
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

}
