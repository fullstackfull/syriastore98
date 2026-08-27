<?php

namespace Workdo\RewardClubPoint\app\Listeners;

use App\Events\AddRewardClubPoint;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Store;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Workdo\RewardClubPoint\app\Models\RewardClubPoint;


class AddRewardClubPointLis
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(AddRewardClubPoint $event): void
    {
        if (module_is_active('RewardClubPoint')) {
            $order = $event->order ?? null;
            $requests_data = $event->requests_data ?? null;
            if (!empty($order)) {
                $store = Store::where('slug',$event->slug)->first();
                $setting = getAdminAllSetting($store['created_by'],$order['store_id']);
                if (!empty($setting) && isset($setting['club_point_enabled']) && $setting['club_point_enabled'] == 'on') {
                    $customer = Customer::find($order['customer_id']);
                    $orderClubPoint = ($order['product_price'] * $setting['order_points'] ?? 0) / ($setting['order_point_price'] ?? 0);
                    if ($requests_data['club_point_is_active'] ?? '' == 'on' && $requests_data['final_amount_point'] == $order['final_price']) {
                        $totalClubPoint = $orderClubPoint;
                        $customerTotalPoints = $customer['total_club_point'];
                        $rewardPrice    = ($customer['total_club_point'] * $setting['reward_point_price'] ?? 0) / ($setting['reward_points'] ?? 0);
                    } else {
                        $totalClubPoint = ($customer['total_club_point'] ?? 0) + $orderClubPoint;
                    }

                    $customer['total_club_point'] = $totalClubPoint;
                    $customer->save();

                    RewardClubPoint::create(
                        [
                            'customer_id'   => $order['customer_id'],
                            'order_id'      => $order['id'],
                            'product_id'    => $order['product_id'],
                            'points'        => $orderClubPoint,
                            'used_points'   => $customerTotalPoints ?? 0,
                            'reward_price'  => $rewardPrice ?? 0,
                            'store_id'      => $order['store_id'],
                        ]
                    );
                }

            }
        }
    }
}
