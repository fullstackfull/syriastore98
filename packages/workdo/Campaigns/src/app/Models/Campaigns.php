<?php

namespace Workdo\Campaigns\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Store;
use App\Models\User;

class Campaigns extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'user',
        'store',
        'product',
        'start_date',
        'end_date',
        'total_days',
        'total_cost',
        'payment_method',
        'status',
        'approval',
        'created_by'
    ];

    protected static function newFactory(): CampaignsFactory
    {
        //return CampaignsFactory::new();
    }
    public static function isEnablePayment()
    {
        $payments = getSuperAdminAllSetting();
        if (!is_array($payments) && !is_object($payments)) {
            return []; // Or handle the error appropriately
        }

        $enabledPayments = [];
        $paymentMethodNames = [];

        foreach ($payments as $key => $value) {
            if (strpos($key, 'is_') === 0 && $value === 'on') {
                $enabledPayments[] = $key;
            }
        }
        foreach ($enabledPayments as $payment) {
            $methodName = str_replace(['is_', '_enabled'], '', $payment);
            $paymentMethodNames[] = ucfirst($methodName);
        }
        return $paymentMethodNames;
    }

    public function users()
    {
        return $this->hasOne('App\Models\User', 'id', 'user');
    }
    public function stores()
    {
        return $this->hasOne('App\Models\Store', 'id', 'store');
    }

    public function products()
    {
        return $this->hasOne('App\Models\Product', 'id', 'product');
    }

    public static $statuses = [
        0 => 'Pending',
        1 => 'Active',
        2 => 'Expired',
        3 => 'Declined'
    ];
}
