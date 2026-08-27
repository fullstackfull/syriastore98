<?php

  namespace App\Exports;

use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Customer;
use DB;

  use Maatwebsite\Excel\Concerns\FromCollection;

  use Maatwebsite\Excel\Concerns\WithHeadings;



class OrderExport implements FromCollection, WithHeadings {




   public function headings(): array {




    // according to users table




    return [

        "Order Id",

        "Order Date",

        "User Name",

        "product Name",

        "Product Price",

        "Coupon Price",

        "Delivery Price",

        "Tax Price",

        "Final Price",

        "Return Price",

        "Payment Type",

        "Payment status",

        "Delivered status",

        "Delivered Date",

        "Confirmed Date",

        "Picked Date",

        "Return status",

        "Cancel Date",

        "Reward Points",
       ];

    }




   public function collection(){
    $store = getStoreById(getCurrentStore());
    $data = Order::where('store_id', $store->id)->get();

    foreach($data as $k => $order){
        $order->setHidden(['demo_field', 'delivered_status_string', 'delivered_image','order_id_string','return_date','
        delivery_date','user_name']);
        unset($order->id,$order->is_guest	,$order->product_json	,$order->payment_comment,$order->delivery_comment,$order->store_id ,$order->delivery_id	,$order->shipped_date	,$order->created_at,$order->updated_at);
        $products=Product::find($order->product_id);
        $product_id=isset($products)?$products->name:'';
        $customer=Customer::find($order->customer_id);
        $customer_id=isset($customer)?$customer->name:'';

         $data[$k]["product_id"]=$product_id;
         $data[$k]["customer_id"]=$customer_id;
         $data[$k]["reward_points"]= 1;
         

    }


       return collect($data);

   }

}
