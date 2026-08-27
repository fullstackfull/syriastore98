<?php

namespace Workdo\PreOrder\app\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\{Customer, Order, Product, ProductVariant, Setting ,Store,User};
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Workdo\PreOrder\app\Models\PreOrderHistory;

class PreOrder extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'partial:preorder';

    /**
     * The console command description.
     */
    protected $description = 'Handles pre orders';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        date_default_timezone_set('Asia/Kolkata');
        $preOrderHistories = PreOrderHistory::where('mail_status', 0)->get();

        if(isset($preOrderHistories))
        {
            foreach ($preOrderHistories as $preOrderHistory)
            {
                $send_mail = false;
                $order = Order::find($preOrderHistory->order_id);
                if(isset($order)){
                    $order_products = json_decode($order->product_json, true);
                    if (isset($order_products) && !empty($order_products)) {
                        foreach ($order_products as $key => $order_product) {

                            if(isset($order_product['product_id']) && $order_product['product_id'] != 0){
                                $product = Product::where('id', $order_product['product_id'])->first();

                                if(isset($product) && isset($order_product['variant_id']) && $order_product['variant_id'] != 0)
                                {
                                    $variant = ProductVariant::where('id',$order_product['variant_id'])->where('product_id', $order_product['product_id'])->first();
                                    if(isset($variant)){
                                        $variationOptions = explode(',', $variant->variation_option);
                                        $option = in_array('manage_stock', $variationOptions);
                                        if ($option == true) {
                                            if ($variant->stock > 0) {
                                                $send_mail = true;
                                            }else{
                                                $send_mail = false;
                                            }
                                        }else{
                                            if ($product->product_stock > 0) {
                                                $send_mail = true;
                                            }else{
                                                $send_mail = false;
                                            }
                                        }
                                    }
                                }else{
                                    if (isset($product) && $product->variant_product == 0 && $product->track_stock == 1 && $product->stock_status == 'in_stock' && $product->product_stock > 0){
                                        $send_mail = true;
                                    }else{
                                        $send_mail = false;
                                    }
                                }

                            }

                        }
                    }

                    $store = Store::where('id', $order->store_id)->where('is_active', 1)->first();
                    if(isset($store)){
                        $customer = Customer::find($order->customer_id);
                        $owner = User::find($store->created_by);
                        $order_email = $preOrderHistory->order_email ?? (!empty($customer->email) ? $customer->email : '');
                        try{
                            $productsData = $order->getProductsData();
                            $productNames = array_column($productsData, 'name');
                            $product_name = implode(', ', $productNames);
                        } catch(\Exception $e){
                            $product_name = '';
                        }

                        if($send_mail){
                            try {
                                $dArr = [
                                    'product_name' => $product_name ?? '',
                                    'order_id' => $order->product_order_id,
                                    'order_link' => env('APP_URL').'/'. $store->slug . '/order/' . \Illuminate\Support\Facades\Crypt::encrypt($order->id ?? ''),
                                    'customer_name' => isset($customer) && isset($customer->first_name) ? $customer->first_name : '',
                                    'order_status' => isset($order) && isset($order->delivered_status) ? $order->delivered_status : '',
                                    'order_date' => isset($order) && isset($order->order_date) ? $order->order_date : '',
                                    'owner_name' => isset($owner) && isset($owner->name) ? $owner->name : '',
                                    'payment_status' => isset($order) && isset($order->payment_status) ? $order->payment_status : '',
                                ];
                        
                                // Send Email
                                $resp = PreOrderHistory::sendEmailTemplate('Pre Order Notification', $order_email, $dArr, $owner, $store);
                                Log::info([$resp]);
                                if(isset($resp['is_success']) && $resp['is_success'] == true){
                                    // $order_cahnge = Order::find($order->id);

                                    if($order->delivered_status == 8){
                                        $order->delivered_status = 0;
                                        $order->save();
                                    }
                                    $preOrderHistory->mail_status = 1;
                                    $preOrderHistory->save();
                                }
                            } catch (\Exception $e) {
                                Log::info([$e]);
                                $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
                            }
                        }
                    }
                }

            }
        }

    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            // Define your arguments here if needed
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            // Define your options here if needed
        ];
    }
}
