<?php
namespace App\Services;

use Illuminate\Support\Facades\{DB, Http, Crypt};

class Zid
{
    // طلبات لمتجر محدد برقمه
    public static function store($storeId)
    {
        $s = DB::table('zid_stores')->where('store_id', $storeId)->first();
        abort_unless($s, 404, "المتجر $storeId غير مربوط بتطبيقك");

        return Http::withHeaders([
            'Authorization'   => 'Bearer ' . Crypt::decryptString($s->authorization),
            'X-Manager-Token' => Crypt::decryptString($s->access_token),
            'Accept'          => 'application/json',
            'Accept-Language' => 'ar',
        ])->baseUrl('https://api.zid.sa/v1');
    }

    // أرقام كل المتاجر المربوطة
    public static function allStoreIds()
    {
        return DB::table('zid_stores')->pluck('store_id');
    }
}