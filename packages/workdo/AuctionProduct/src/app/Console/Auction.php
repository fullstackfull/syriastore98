<?php

namespace Workdo\AuctionProduct\app\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Workdo\AuctionProduct\app\Models\AuctionProductSetting;
use Workdo\AuctionProduct\app\Models\AuctionProductOrder;
use App\Models\Store;
use Workdo\AuctionProduct\app\Models\AuctionProductBid;
use App\Models\Product;
use App\Models\Customer;
use App\Models\User;
use App\Models\Utility;
use App\Models\Cart;
use App\Models\DeliveryAddress;
use Illuminate\Support\Facades\Crypt;

class Auction extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'auction:order';

    /**
     * The console command description.
     */
    protected $description = 'Command description.';

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
        $currentDateTime = \Carbon\Carbon::now();
        $auctionProductIds = [];
        $auctionProductSettings = AuctionProductSetting::where('is_active', 1)->get();
        foreach ($auctionProductSettings as $setting) {

            $startDate = \Carbon\Carbon::parse($setting->start_date . ' ' . $setting->start_time);
            $endDate = \Carbon\Carbon::parse($setting->end_date . ' ' . $setting->end_time);

            if ($endDate < $startDate) {
                $endDate->addDay();
            }
            $currentDateTime->setTimezone($startDate->getTimezone());

            if (
                $currentDateTime->year == $endDate->year &&
                $currentDateTime->month == $endDate->month &&
                $currentDateTime->day == $endDate->day &&
                $currentDateTime->hour == $endDate->hour &&
                $currentDateTime->minute == $endDate->minute
            ) {
                $auctionProductIds = array_merge($auctionProductIds, explode(',', $setting->auction_product));
                $auction_product = Product::where('product_type', 'auction')
                            ->where('store_id', $setting->store_id)
                            ->whereIn('id', $auctionProductIds)
                            ->get();
                            $tax_price = $shipping_price = 0;
                foreach($auction_product as  $item) {
                    $maxBidAmount = AuctionProductBid::where('product_id', $item->id)->max('bid_amount');
                    $maxBidRecord = AuctionProductBid::where('product_id', $item->id)->where('bid_amount', $maxBidAmount)->first();

                    if($maxBidRecord)
                    {
                        $store = Store::find($item->store_id);
                        $customer_id =  $maxBidRecord->customer_id;
                        $customer = Customer::find($customer_id);
                        $DeliveryAddress = DeliveryAddress::where( 'customer_id', $customer_id)->first();
                        $city_id =  isset($DeliveryAddress->city_id) ? $DeliveryAddress->city_id : '';
                        $state_id =  isset($DeliveryAddress->state_id) ? $DeliveryAddress->state_id : '';
                        $country =  isset($DeliveryAddress->country_id) ? $DeliveryAddress->country_id : '';
                        $tax_price = ($item->tax_id !== null) ? \App\Models\Cart::getProductTaxAmount($item->tax_id, $maxBidAmount, $store->id, $city_id, $state_id, $country, true) : 0;
                        $shipping_price = \Workdo\AuctionProduct\app\Models\AuctionProductOrder::calculateFlateShipping($item->shipping_id, $maxBidAmount + $tax_price, $city_id, $state_id, $country, $item);
                        $amount = $maxBidAmount + $tax_price + $shipping_price;

                        $product_order_id  = '0' . date('YmdHis');
                        $product_reward_point = 1;

                        $order = new AuctionProductOrder();
                        $order->product_order_id = $product_order_id;
                        $order->order_date = date('Y-m-d H:i:s');
                        $order->customer_id = !empty($customer->id) ? $customer->id : 0;
                        $order->product_id = $item->id;
                        $order->product_json = json_encode($item);
                        $order->price = SetNumber( $maxBidAmount);
                        $order->sub_total = SetNumber( $amount);
                        $order->tax_price = SetNumber( $tax_price);
                        $order->shipping_price = SetNumber( $shipping_price);
                        $order->payment_status = 'Pending';
                        $order->order_status = 'Pending';
                        $order->delivery_id = $requests_data['method_id'] ?? 0;
                        $order->delivery_comment = !empty($requests_data['delivery_comment']) ? $requests_data['delivery_comment'] : '';
                        $order->delivered_status = 0;
                        $order->reward_points = SetNumber($product_reward_point);
                        $order->store_id = $item->store_id;
                        $order->save();

                        //Order Mail
                        $customer = Customer::find($order['customer_id']);
                        $order_email = !empty($customer->email) ? $customer->email : '';
                        $owner=User::find($store->created_by);
                        $owner_email=$owner->email;
                        $order_id    = Crypt::encrypt($order->id);

                        try
                        {
                            $dArr = [
                            'order_id' => $order->product_order_id,
                            ];

                            // Send Email
                            $resp = Utility::sendEmailTemplate('Order Created', $order_email, $dArr, $owner,$store, $order_id);
                        }
                        catch(\Exception $e)
                        {
                            $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
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
            ['example', InputArgument::REQUIRED, 'An example argument.'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['example', null, InputOption::VALUE_OPTIONAL, 'An example option.', null],
        ];
    }
}
