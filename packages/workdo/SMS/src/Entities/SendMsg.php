<?php

namespace Workdo\SMS\Entities;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Workdo\SMS\app\Models\Notification;
use Workdo\SMS\app\Models\NotificationTemplateLang;
use Tzsk\Sms\Facades\Sms;

class SendMsg extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [];

    protected static function newFactory()
    {
        return \Workdo\SMS\Database\factories\SendMsgFactory::new();
    }

    public static function SendMsgs($mobile_no, $uArr, $template, $store_id)
    {
        
        $usr = \Auth::user();
        $template = Notification::where('module', 'SMS')->where('action', $template)->first();
        if ($template) {
            if (empty($usr)) {
                $usr = User::find($store_id);
            }
            if (!empty($usr)) {
                $content = NotificationTemplateLang::where('parent_id', '=', $template->id)->where('lang', 'LIKE', 'en')->first();
            } else {
                $content = NotificationTemplateLang::where('parent_id', '=', $template->id)->where('lang', 'LIKE', 'en')->first();
            }
            $msg = self::replaceVariable($content->content, $uArr, $store_id);
            $admin_settings = getAdminAllSetting($usr->id, $store_id);

            if ($admin_settings['sms_setting_enabled'] == "on") {
                self::active_driver($store_id);
            }
            try {
                if ($admin_settings['sms_setting_enabled'] == "on") {
                    $response = Sms::via($admin_settings['sms_setting'])->send($msg, function ($sms) use ($mobile_no) {
                        $sms->to($mobile_no);
                    });
                }
            } catch (\Exception $e) {
            }
        }
        
    }

    public static function replaceVariable($content, $obj)
    {
        $arrVariable = [
            '{amount}',
            '{user_name}',
            '{payment_type}',
            '{status}',

        ];
        $arrValue    = [
            'amount' => '-',
            'user_name' => '-',
            'payment_type' => '-',
            'status' => '-',
        ];
        foreach ($obj as $key => $val) {
            $arrValue[$key] = $val;
        }
        $arrValue['app_name'] = env('APP_NAME');
        $arrValue['app_url'] = '<a href="' . env('APP_URL') . '" target="_blank">' . env('APP_URL') . '</a>';

        return str_replace($arrVariable, array_values($arrValue), $content);
    }

    public static function active_driver($store_id = null)
    {
        $usr = \Auth::user();
        if (empty($usr)) {
            $usr = User::find($store_id);
        }
        $admin_settings = getAdminAllSetting($usr->id, $store_id);
        if ($admin_settings['sms_setting'] == "twilio") {

            $twilio_sid = isset($admin_settings['sms_twilio_sid']) ? $admin_settings['sms_twilio_sid'] : '';
            $twilio_token = isset($admin_settings['sms_twilio_token']) ? $admin_settings['sms_twilio_token'] : '';
            $twilio_from = isset($admin_settings['sms_twilo_from_number']) ? $admin_settings['sms_twilo_from_number'] : '';

            config(
                [
                    'sms.drivers.twilio.sid' => $twilio_sid,
                    'sms.drivers.twilio.token' => $twilio_token,
                    'sms.drivers.twilio.from' => $twilio_from,
                ]
            );
        } elseif ($admin_settings['sms_setting'] == "sns") {
            $sns_access_key = isset($admin_settings['sns_access_key']) ? $admin_settings['sns_access_key'] : '';
            $sns_secret_key = isset($admin_settings['sns_secret_key']) ? $admin_settings['sns_secret_key'] : '';
            $sns_region = isset($admin_settings['sns_region']) ? $admin_settings['sns_region'] : '';
            $sns_sender_id = isset($admin_settings['sns_sender_id']) ? $admin_settings['sns_sender_id'] : '';
            $sns_type = isset($admin_settings['sns_type']) ? $admin_settings['sns_type'] : '';

            config(
                [
                    'sms.drivers.sns.sid' => $sns_access_key,
                    'sms.drivers.sns.token' => $sns_secret_key,
                    'sms.drivers.sns.from' => $sns_region,
                    'sms.drivers.sns.from' => $sns_sender_id,
                    'sms.drivers.sns.from' => $sns_type,
                ]
            );
        } elseif ($admin_settings['sms_setting'] == "clockwork") {
            $clockwork_api_key = isset($admin_settings['clockwork_api_key']) ? $admin_settings['clockwork_api_key'] : '';


            config(
                [
                    'sms.drivers.clockwork.key' => $clockwork_api_key,

                ]
            );
        } elseif ($admin_settings['sms_setting'] == "melipayamak") {
            $melipayamak_username = isset($admin_settings['melipayamak_username']) ? $admin_settings['melipayamak_username'] : '';
            $melipayamak_password = isset($admin_settings['melipayamak_password']) ? $admin_settings['melipayamak_password'] : '';
            $melipayamak_from_number = isset($admin_settings['melipayamak_from_number']) ? $admin_settings['melipayamak_from_number'] : '';
            config(
                [
                    'sms.drivers.melipayamak.username' => $melipayamak_username,
                    'sms.drivers.melipayamak.password' => $melipayamak_password,
                    'sms.drivers.melipayamak.from' => $melipayamak_from_number,
                    'sms.drivers.melipayamak.flash' => false,

                ]
            );
        } elseif ($admin_settings['sms_setting'] == "kavenegar") {
            $kavenegar_apiKey = isset($admin_settings['kavenegar_apiKey']) ? $admin_settings['kavenegar_apiKey'] : '';
            $kavenegar_from_number = isset($admin_settings['kavenegar_from_number']) ? $admin_settings['kavenegar_from_number'] : '';
            config(
                [
                    'sms.drivers.kavenegar.apiKey' => $kavenegar_apiKey,
                    'sms.drivers.kavenegar.from' => $kavenegar_from_number,

                ]
            );
        } else {
            $smsgatewayme_apiToken = isset($admin_settings['smsgatewayme_apiToken']) ? $admin_settings['smsgatewayme_apiToken'] : '';
            $Smsgatewayme_device_id = isset($admin_settings['Smsgatewayme_device_id']) ? $admin_settings['Smsgatewayme_device_id'] : '';
            config(
                [
                    'sms.drivers.smsgatewayme.apiToken' => $smsgatewayme_apiToken,
                    'sms.drivers.smsgatewayme.from' => $Smsgatewayme_device_id,

                ]
            );
        }
    }
}
