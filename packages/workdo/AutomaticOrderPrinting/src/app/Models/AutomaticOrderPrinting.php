<?php

namespace Workdo\AutomaticOrderPrinting\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AutomaticOrderPrinting extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'name','value','created_by','store_id'
    ];

    protected static function templateData()
    {
        $arr              = [];
        $arr['colors']    = [
            '003580',
            '666666',
            '6676ef',
            'f50102',
            'f9b034',
            'fbdd03',
            'c1d82f',
            '37a4e4',
            '8a7966',
            '6a737b',
            '050f2c',
            '0e3666',
            '3baeff',
            '3368e6',
            'b84592',
            'f64f81',
            'f66c5f',
            'fac168',
            '46de98',
            '40c7d0',
            'be0028',
            '2f9f45',
            '371676',
            '52325d',
            '511378',
            '0f3866',
            '48c0b6',
            '297cc0',
            'ffffff',
            '000',
        ];
        $arr['templates'] = [
            "template1" => "New York",
            "template2" => "Toronto",
            "template3" => "Rio",
            "template4" => "London",
            "template5" => "Istanbul",
            "template6" => "Mumbai",
            "template7" => "Hong Kong",
            "template8" => "Tokyo",
            "template9" => "Sydney",
            "template10" => "Paris",
        ];
        return $arr;
    }

    protected static function getPrintSettings($user_id = null, $store_id = null)
    {
        if(!empty($user_id)){
            $user = User::find($user_id);
        }
        else
        {
            $user =  auth()->user();
        }

        if (isset($user->type) && !in_array($user->type, ['admin', 'super admin'])) {
            $user = User::find($user->created_by);
        }
        if (!empty($user))
        {
            $store_id = $store_id ?? $user->current_store;
            $store = Store::find($store_id);
            $settings = [];
            $settings = AutomaticOrderPrinting::where('created_by',$user->id)->where('store_id',$store_id)->pluck('value', 'name')->toArray();
            return $settings;
        } elseif (!empty($store_id)) {
            $settings = [];
            $settings = AutomaticOrderPrinting::where('store_id',$store_id)->pluck('value', 'name')->toArray();
            return $settings;
        }

        return [];
    }

    protected static function hex2rgb($hex)
    {
        $hex = str_replace("#", "", $hex);

        if(strlen($hex) == 3)
        {
            $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
            $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
            $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
        }
        else
        {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }
        $rgb = array(
            $r,
            $g,
            $b,
        );

        return $rgb;
    }

    protected static function getFontColor($color_code)
    {
        $rgb = self::hex2rgb($color_code);
        $R   = $G = $B = $C = $L = $color = '';

        $R = (floor($rgb[0]));
        $G = (floor($rgb[1]));
        $B = (floor($rgb[2]));

        $C = [
            $R / 255,
            $G / 255,
            $B / 255,
        ];

        for($i = 0; $i < count($C); ++$i)
        {
            if($C[$i] <= 0.03928)
            {
                $C[$i] = $C[$i] / 12.92;
            }
            else
            {
                $C[$i] = pow(($C[$i] + 0.055) / 1.055, 2.4);
            }
        }

        $L = 0.2126 * $C[0] + 0.7152 * $C[1] + 0.0722 * $C[2];

        if($L > 0.179)
        {
            $color = 'black';
        }
        else
        {
            $color = 'white';
        }

        return $color;
    }
}
