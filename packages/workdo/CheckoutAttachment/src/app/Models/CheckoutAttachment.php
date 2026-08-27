<?php

namespace Workdo\CheckoutAttachment\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Models\Store;
use App\Models\Utility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Session;
use Illuminate\Support\Facades\Cache;

class CheckoutAttachment extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'product_order_id',  'store_id', 'customer_id'
    ];

    public static function CheckoutAttachment($order, $slug, $request)
    {
        if (is_array($request)) {
            $attachments = $request['attachment'];
        } else {
            $attachments = $request->attachment;
        }
        $data = [];
        if ($attachments && count($attachments) > 0) {
            $store = getStore($slug);
            $dir        = 'uploads/' . $store->id;

            $attachment = new CheckoutAttachment();
            $attachment->created_by = null;
            if (auth('customers')->user()) {
                $attachment->created_by = auth('customers')->user()->id ?? null;
            }
            $attachment->store_id = $store->id;
            $attachment->product_order_id = $order->product_order_id;
            $errors = [];
            foreach ($attachments as $filekey => $file) {
                $file_size = $file->getSize();
                $result = Utility::updateStorageLimit($store->created_by, $file_size);
                if ($result == 1) {
                    $imageName = $file->getClientOriginalName();
                    $path = Utility::keyWiseUpload_file($request, 'attachment', $imageName, $dir, $filekey, []);

                    if ($path['flag'] == 1) {
                        $data[] = $path['url'];
                    } else {
                        $errors = __($path['msg']);
                    }
                } else {
                    return redirect()->back()->with('error', $result);
                }
            }

            $attachment->attachment    =  json_encode($data);
            $attachment->save();
        }
    }

    public static function CheckoutAttachmentOrderStore($order, $slug)
    {
        $attachments = json_decode(Session::get('request_attachment_data'));
        if ($attachments && count($attachments) > 0) {
            $store = getStore($slug);
            $dir        = 'uploads/' . $store->id;

            $attachment = new CheckoutAttachment();
            $attachment->created_by = null;
            if (auth('customers')->user()) {
                $attachment->created_by = auth('customers')->user()->id ?? null;
            }
            $attachment->store_id = $store->id;
            $attachment->product_order_id = $order->product_order_id;
            $attachment->attachment    =  json_encode($attachments);
            $attachment->save();

            Session::forget('request_attachment_data');
        }
    }

    public static function CheckoutAttachmentStore($slug, $request)
    {
        if ($request->attachment && count($request->attachment) > 0) {
            $store = getStore($slug);
            $dir        = 'uploads/' . $store->id;

            $errors = [];
            foreach ($request->attachment as $filekey => $file) {
                $file_size = $file->getSize();
                $result = Utility::updateStorageLimit($store->created_by, $file_size);
                if ($result == 1) {
                    $imageName = $file->getClientOriginalName();
                    $path = Utility::keyWiseUpload_file($request, 'attachment', $imageName, $dir, $filekey, []);

                    if ($path['flag'] == 1) {
                        $data[] = $path['url'];
                    } else {
                        $errors = __($path['msg']);
                    }
                } else {
                    return redirect()->back()->with('error', $result);
                }
            }
            Session::put('request_attachment_data', json_encode($data));
        }
    }
}
