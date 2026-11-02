<?php

namespace App\Exports;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Facades\Cache;

class CouponExport implements FromCollection, WithHeadings {




   public function headings(): array {
        return [
            "Number",
            "Coupon Name",
            "Coupon Code",
            "Coupon Type",
            "Applied Product",
            "Exclude Product",
            "Applied Categories",
            "Exclude Categories",
            "Minimum Spend",
            "Maximum Spend",
            "Coupon Limit User",
            "Coupon Limit x_item",
            "Coupon Limit",
            "Coupon Expiry Date",
            "Discount Amount",
            "Sale Items",
            "Free Shipping Coupon",
            "Status",
        ];
    }

    public function collection(){
        $store = getStoreById(getCurrentStore());

        /*
         * P1-SEC-11 criterion 2 -- the second line of defence `OrderExport` already carries, and
         * for the same reason: this class is reached from a download response, so the alternative
         * to an empty file is a stack trace. `$store->id` on a null store was a 500 for any
         * operator whose `current_store` is empty on a deployment whose stores do not start at 1
         * (a deleted first store is enough). The caller refuses with a message before getting
         * here; this guard exists because the class is constructible from anywhere.
         */
        if ($store === null) {
            return collect([]);
        }

        $data = Coupon::where('store_id', $store->id)->get();
        $i = 1;
        foreach($data as $k => $coupon){
            unset($coupon->store_id ,$coupon->created_at,$coupon->updated_at);
            $data[$k]["id"] = $i++ ;
        }
        return collect($data);
    }

}
