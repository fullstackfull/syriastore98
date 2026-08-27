<?php

namespace App\Exports;

use App\Models\Newsletter;
use App\Models\Store;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class NewsletterExport implements FromCollection, WithHeadings
{
    public function headings(): array {

        // according to users table

        return [
    
            "Customer Id",
    
            "Email",
    
            "Theme Id",
    
            "Store Id",
            
           ];
    
    }
    
    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        $store = getStoreById(getCurrentStore());
        $data = Newsletter::where('store_id', $store->id)->get();

        foreach($data as $k => $order){
            unset($order->id,$order->created_at,$order->updated_at);
        }

        return collect($data);
    }
}
