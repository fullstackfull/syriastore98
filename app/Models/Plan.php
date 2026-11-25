<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory, Cachable;
    protected $fillable = [
        'name',
        'price',
        'duration',
        'max_stores',
        'max_products',
        'max_users',
        // How many Lynomia agent seats this package includes. Null means the package says
        // nothing about them, which is every plan that predates the Lynomia Chat module;
        // Workdo\LynomiaChat\app\Services\SeatAllowanceSync treats null as "grant nothing"
        // rather than as zero, so an unconfigured plan locks nobody out.
        'lynomia_agent_seats',
        // How many marketing messages this package includes per billing period. Null means
        // the package says nothing, like the seats above, and
        // Workdo\LynomiaChat\app\Services\MarketingAllowanceSync treats null as "grant
        // nothing" rather than as zero -- zero would stop every campaign on the plan.
        'lynomia_campaign_sends',
        'storage_limit',
        'enable_domain',
        'enable_subdomain',
        'enable_chatgpt',
        'pwa_store',
        'shipping_method',
        'themes',
        'enable_tax',
        'description',
        'trial',
        'trial_days',
        'modules',
    ];

    public static $arrDuration = [
        'Unlimited' => 'Unlimited',
        'Month' => 'Per Month',
        'Year' => 'Per Year',
    ];

    public static function getDurationsArr(): array
    {
        return [
            'Unlimited' => __('Unlimited'),
            'Month'     => __('Per Month'),
            'Year'      => __('Per Year'),
        ];
    }

    public function status()
    {
        return [
            __('Unlimited'),
            __('Per Month'),
            __('Per Year'),
        ];
    }

    public function getThemes(){
        if(!empty($this->themes)){
            return explode(',',$this->themes);
        }else{
            return [];
        }
    }

    public static function total_plan()
    {
        return Plan::count();
    }

    /**
     * THE MOST-PURCHASED PLAN'S NAME, COUNTED IN THE DATABASE.
     *
     * This was `PlanOrder::pluck('plan_id','id')->toarray()` followed by `array_count_values()`:
     * every `plan_orders` row on the platform pulled into PHP and counted there, with no predicate
     * and no bound, on every load of the operator's dashboard. `HomeController::handleSuperAdmin()`
     * already carries that same account for `popularStores`, which was three lines of exactly this
     * shape over the visit log, and for `topStoresByOrderValue`. Found while fixing the N+1 behind
     * the coupon card beside this one.
     *
     * THE TIE-BREAK IS THE ORIGINAL'S, not a new choice. `array_count_values()` keys follow first
     * appearance and `array_search()` returns the first matching key, so among plans level on
     * purchases the winner was the one whose earliest `plan_orders` row came first in primary-key
     * order. `MIN(id)` reproduces that; without it, two equally-popular plans could swap between
     * requests. `OperatorDashboardCardLabelsTest::
     * test_a_plan_tie_is_broken_by_the_earliest_order` pins it.
     *
     * The NAME still comes from `plans`, not from the denormalised `plan_orders.plan_name`, so a
     * renamed plan reads as the original did -- and a plan deleted after its orders still yields
     * '', because `firstWhere()` answers null and `?->` carries it.
     */
    public static function most_purchese_plan()
    {
        $ranked = PlanOrder::query()
            ->selectRaw('plan_id, COUNT(*) as purchases, MIN(id) as first_order_id')
            ->groupBy('plan_id')
            ->orderByDesc('purchases')
            ->orderBy('first_order_id')
            ->limit(1)
            ->toBase()
            ->first();

        if ($ranked === null) {
            return '';
        }

        return (string) (Plan::find((int) $ranked->plan_id)?->name ?? '');
    }
}
