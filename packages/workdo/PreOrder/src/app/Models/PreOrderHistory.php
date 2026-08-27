<?php

namespace Workdo\PreOrder\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Mail\CommonEmailTemplate;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\EmailTemplateLang;
use App\Models\User;
use App\Models\UserEmailTemplate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PreOrderHistory extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'order_id',
        'customer_id',
        'order_email',
        'mail_status',
        
        'store_id',
    ];

    public static function PreOrderHistory($order, $store, $request, $order_email = null)
    {
        $pre_order_history = new PreOrderHistory();
        $pre_order_history->order_id = $order->id;
        $pre_order_history->customer_id = $order->customer_id;
        $pre_order_history->order_email = $order_email ?? null;
        $pre_order_history->mail_status = '0';
        $pre_order_history->store_id = $request['store_id'];
        $pre_order_history->save();

        $orderProductIds = explode(',', $order->product_id);
        $productJson = json_decode($order->product_json, true);

        foreach ($productJson as $item) {
            $productId = $item['product_id'];
            $quantity = $item['qty'];
            
            if (in_array($productId, $orderProductIds)) {
                $preOrderSetting = PreOrderSetting::where('product_id', $productId)->where('status', 1)->where('store_id', $request['store_id'])->first();
                if (isset($preOrderSetting)){
                    if ($preOrderSetting) {
                        $newQty = $preOrderSetting->qty - $quantity;

                        if ($newQty < 0) {
                            $newQty = 0; // Ensure qty doesn't go negative
                        }
                    }
                    $preOrderSetting->qty = $newQty ?? 0;
                    $preOrderSetting->save();
                }
            }
        }
        
        $customer = Customer::find($order->customer_id);
        $owner = User::find($store->created_by);
        try{
            $productsData = $order->getProductsData();
            $productNames = array_column($productsData, 'name');
            $product_name = implode(', ', $productNames);
        } catch(\Exception $e){
            $product_name = '';
        }
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
            $resp = self::sendEmailTemplate('Pre Order Created', $order_email, $dArr, $owner, $store);
            $resp1 = self::sendEmailTemplate('Pre Order Created For Owner', $owner->email, $dArr, $owner, $store);
        } catch (\Exception $e) {
            $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
        }
    }

    
    public static function sendEmailTemplate($emailTemplate, $mailTo, $obj, $user, $store)
    {
        // find template is exist or not in our record
        $template = EmailTemplate::where('name', 'LIKE', $emailTemplate)->first();

        if (isset($template) && !empty($template)) {

            // check template is active or not by company
            $is_actives = UserEmailTemplate::where('template_id', '=', $template->id)->where('user_id', $user->id)->first();

            $setting = getAdminAllSetting($user->id);

            if ($is_actives->is_active == 1) {

                // get email content language base
                $EmailTemplateLang = EmailTemplateLang::where('parent_id', '=', $template->id)->where('is_default', 1)->first();
                if (isset($EmailTemplateLang->language) && isset($user->language)) {
                    $prefer_lang = ($user->language == $EmailTemplateLang->language) ? $user->language : $EmailTemplateLang->language;
                } else {
                    $prefer_lang = 'en';
                }

                // get email content language base
                $content = EmailTemplateLang::where('parent_id', '=', $template->id)->where('language', 'LIKE', $prefer_lang)->first();
                $content->from = $template->from;

                if (!empty($content->content)) {

                    // send email
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

                        $content->content = self::replaceVariable($content->content, $obj, $store);

                        $ownername = User::where('id', $store->created_by)->first();

                        if ($mailTo == $ownername->email) {

                            Mail::to(
                                [
                                    $store['email'],
                                ]
                            )->send(new CommonEmailTemplate($content, $setting, $store));
                        } else {
                            Mail::to(
                                [
                                    $mailTo,
                                ]
                            )->send(new CommonEmailTemplate($content, $setting, $store));
                        }
                    } catch (\Exception $e) {
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
                } else {
                    $arReturn = [
                        'is_success' => false,
                        'error' => __('Mail not send, email is empty'),
                    ];
                }
                return $arReturn;
            } else {
                return [
                    'is_success' => true,
                    'error' => false,
                ];
            }
        } else {
            return [
                'is_success' => false,
                'error' => __('Mail not send, email not found'),
            ];
        }
    }

    public static function replaceVariable($content, $obj, $store)
    {
        $arrVariable = [
            '{product_name}',
            '{order_id}',
            '{order_link}',
            '{order_status}',
            '{order_date}',
            '{customer_name}',
            '{owner_name}',
            '{payment_status}',
            '{app_name}',
            '{app_url}',
        ];
        $arrValue = [
            'product_name' => '-',
            'order_id' => '-',
            'order_link' => '-',
            'order_status' => '-',
            'order_date' => '-',
            'customer_name' => '-',
            'owner_name' => '-',
            'payment_status' => '-',
        ];

        foreach ($obj as $key => $val) {
            $arrValue[$key] = $val;
        }

        $arrValue['app_name'] = $store->name;
        $arrValue['app_url'] = '<a href="' . env('APP_URL') . '" target="_blank">' . env('APP_URL') . '</a>';

        return str_replace($arrVariable, array_values($arrValue), $content);
    }
}
