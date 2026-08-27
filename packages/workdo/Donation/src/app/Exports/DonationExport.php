<?php

namespace Workdo\Donation\app\Exports;

use App\Models\Store;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Workdo\Donation\app\Models\CustomerDonation;
use Illuminate\Support\Facades\Cache;

class DonationExport implements FromCollection, WithHeadings {

   public function headings(): array {
        return [
            "id",
            "Donation",
            "Customer",
            "Customer Name",
            "Customer Email",
            "Customer Number",
            "Customer Address",
            "Country",
            "State",
            "City",
            "Amount",
            "Payment Type"
        ];
    }

    public function collection(){
        $store = Store::find(getCurrentStore());
        $data = CustomerDonation::where('store_id', $store->id)->get();
        $i = 1;
        foreach($data as $k => $coupon){
            unset($coupon->store_id ,$coupon->created_at,$coupon->updated_at);
            $data[$k]["id"] = $i++ ;
        }
        return collect($data);
    }

}
