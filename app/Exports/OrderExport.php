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
    /*
     * P1-SEC-11 criterion 2. `getCurrentStore()` returns a hardcoded 1 for an operator whose
     * `current_store` is empty (helper.php:407-409), and `getStoreById(1)` is NULL on any
     * deployment whose stores do not start at 1 -- a first store that was deleted is enough. This
     * line then read `$store->id` and the export 500ed. `fileExport`'s own
     * `if (empty(getCurrentStore()))` guard cannot catch it, because the helper returned 1 rather
     * than nothing.
     *
     * An unresolvable store exports NOTHING rather than guessing one: this class is reached from a
     * download response, so the alternative to an empty file is a stack trace. The caller refuses
     * with a message before getting here; this is the second line of defence, because the class is
     * constructible from anywhere.
     *
     * The helper is deliberately NOT changed -- config/tenancy.php:60-63 records two storefront
     * outages shipped by changing a helper this central.
     */
    $store = getStoreById(getCurrentStore());

    if ($store === null) {
        return collect([]);
    }

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
