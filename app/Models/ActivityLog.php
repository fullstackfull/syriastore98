<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'customer_id',
        'log_type',
        'remark',
        
        'store_id',
    ];

    public static function order_entry($data= [])
    {
        // order create activity log
        $ActivityLog = new ActivityLog();
        $ActivityLog->customer_id = $data['customer_id'];
        $ActivityLog->log_type = 'place order';
        $ActivityLog->remark = json_encode(
            [
                'order_id' => $data['order_id'],
                'order_date' => $data['order_date'],
                'product' => $data['products'],
                'price' => $data['final_price'],
                'payment_type' => $data['payment_type'],
            ]
        );
        $ActivityLog->store_id = $data['store_id'];
        $ActivityLog->save();
    }

    public function userinfo()
    {
        return $this->hasOne(User::class, 'id', 'customer_id');
    }

    public static function products($id = '')
    {
        $productIdArray = explode(',', $id);
        $products = Product::whereIn('id', $productIdArray)->get();
        $productNames = [];
        foreach ($products as $product) {
            $productNames[] = $product->name;
        }
        return implode(', ', $productNames);

    }

    public static function variants($id = '')
    {
        $variant = ProductVariant::find($id);
        return $variant->variant ?? null;
    }

    /**
     * P2.5-PKG-C (F-C-09). `$storeId` IS NOW REQUIRED, and the query carries it.
     *
     * This summed `Order::where('customer_id', $userId)` with NO store predicate, and `Order` is
     * absent from `config/tenancy.php`'s `scoped_models`, so Total Spend and Total Orders on the
     * customer timeline aggregated the customer's purchases across EVERY STORE on the platform --
     * one merchant's page showing another merchant's revenue.
     *
     * ALREADY DECLARED OPEN before this fix: `ResidualTenancyExposureTest`'s raw-expression test
     * recorded this exact site by path and stated the harm in as many words. Its narrative has
     * been updated in the same change, because a resolved declaration is fiction.
     *
     * MEASURED (CustomerTimelineTenancyTest): a customer with a $25 order in store A and a $775
     * order in store B returned total_orders 2 and total_spend 800 for store A's merchant.
     *
     * THE `DB::raw` STAYS. It is a SELECT EXPRESSION on an Eloquent query, not a bypass -- the
     * surrounding builder carries its scopes, and the ratchet above says so explicitly. The defect
     * was never the raw SQL; it was the missing predicate.
     *
     * FAILS CLOSED when either argument is absent. The parameter has no default, so a caller that
     * forgets it is a TypeError at the call site rather than a silent platform-wide sum -- which
     * is how this would otherwise come back. There is exactly one caller
     * (`CustomerController::customerTimeline`), so requiring it costs nothing.
     *
     * @param int|null $storeId the store whose orders may be counted; null sums nothing
     */
    public static function totalspend($userId = null, ?int $storeId = null)
    {
        $totalSpend = 0;
        $totalOrders = 0;
        if($userId && $storeId)
        {
            $results = Order::where('store_id', $storeId)
                    ->where('customer_id', $userId)
                    ->select(\DB::raw('SUM(final_price) as total_price ,COUNT(*) as order_count'))
                    ->first();
            if ($results) {
                $totalSpend = !empty($results->total_price) ? $results->total_price : '0';
                $totalOrders = $results->order_count;
            }
        }
        return ['total_spend' => $totalSpend, 'total_orders' => $totalOrders];
    }
}
