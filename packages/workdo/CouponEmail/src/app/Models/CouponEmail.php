<?php

namespace Workdo\CouponEmail\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Workdo\CouponEmail\app\Emails\CouponEmailTemplate;
use Illuminate\Support\Facades\Mail;

class CouponEmail extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'name',
        'value',
        'created_by',
        
        'store_id'
    ];

    public static function sendCouponEmail($mailTo,$subject,$content,$store_id)
    {
        $setting = Setting::where('store_id', $store_id)->pluck('value', 'name')->toArray();
        if($content){
            try {
                config(
                    [
                        'mail.driver' => $setting['MAIL_DRIVER'],
                        'mail.host' => $setting['MAIL_HOST'],
                        'mail.port' => $setting['MAIL_PORT'],
                        'mail.encryption' => $setting['MAIL_ENCRYPTION'],
                        'mail.username' => $setting['MAIL_USERNAME'],
                        'mail.password' => $setting['MAIL_PASSWORD'],
                        'mail.from.address' => $setting['MAIL_FROM_ADDRESS'],
                        'mail.from.name' => $setting['MAIL_FROM_NAME'],
                    ]
                );
                Mail::to(
                    [
                        $mailTo,
                    ]
                )->send(new CouponEmailTemplate($content, $setting,$subject));
            } catch (\Throwable $th) {
                $error = __('E-Mail has been not sent due to SMTP configuration');
            }
            if (isset($error)) {
                $arReturn = [
                    'is_success' => false,
                    'error' => $error,
                ];
            } else {
                $arReturn = [
                    'is_success' => true,
                    'error' => false,
                ];
            }
        }
        else{
            $arReturn = [
                'is_success' => false,
                'error' => __('Mail not send, email is empty'),
            ];
        }
        return $arReturn;
    }
}
