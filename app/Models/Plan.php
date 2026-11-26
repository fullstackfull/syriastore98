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
     * THE TIE-BREAK MATCHES WHAT THE ORIGINAL ACTUALLY GOT, which is a weaker and more honest
     * claim than "reproduces the original's behaviour". `array_count_values()` keys follow first
     * appearance and `array_search()` returns the first matching key, so among plans level on
     * purchases the winner was the one whose earliest `plan_orders` row came first in the scan.
     * That scan order was PRIMARY-KEY ORDER ONLY BY ACCIDENT: the old query had no `ORDER BY`, and
     * nothing could serve `SELECT plan_id, id FROM plan_orders` from a covering secondary index,
     * because the migration indexes only `order_id` (unique), `store_id` and the `user_id` foreign
     * key -- never `plan_id`. Adding an index on `plan_id` would have changed the old tie-break
     * silently. So `MIN(id)` is equivalent to what the original got on both engines today and is
     * strictly more deterministic; it is not a defined behaviour being preserved. Without any
     * tie-break, two equally-popular plans could swap between requests.
     * `OperatorDashboardCardLabelsTest::test_a_plan_tie_is_broken_by_the_earliest_order` pins it.
     *
     * THIS AGGREGATES ACROSS STORES, and `plan_orders` is NOT a table without a tenant dimension:
     * the migration gives it a nullable, indexed `store_id` and the model has it in `$fillable`.
     * The breadth is unchanged from the `pluck()` this replaced, which had no predicate either,
     * and the only caller is `HomeController::handleSuperAdmin()`, reached solely for the platform
     * operator. But this method is a public static with no predicate and no operator test of its
     * own, so its safety is entirely the caller's -- do not add a merchant-facing caller without
     * a predicate. Corrected after an independent review refuted the claim that these were
     * "platform-level tables with no tenant dimension"; that is true of `plan_user_coupons`, which
     * genuinely has no store column, and false of this one.
     *
     * The NAME still comes from `plans`, not from the denormalised `plan_orders.plan_name`, so a
     * renamed plan reads as the original did -- and a plan deleted after its orders still yields
     * '', because `Plan::find()` answers null and `?->` carries it. (This sentence said
     * `firstWhere()`, which this method has never called. A docblock naming a function the code
     * beside it does not use is the same class of defect as the citation corrected in §5 of
     * `docs/development/phase-6/PHASE-06-DASHBOARD-CARD-LABELS.md`.)
     *
     * ONE DIVERGENCE IS ACCEPTED AND NOT FIXED. Ranking from the CHILD table and then looking the
     * parent up means a winning group whose `plans` row is missing yields '' -- the card goes
     * blank -- where the original iterated existing parents and would have named the best
     * surviving one. `plan_orders.plan_id` is `integer()` NOT NULL so there is no null group, but
     * a `plan_id` of 0 would blank the card, and two gateway handlers pass `$plan->id ?? null`
     * (`CashfreeController` and `KhaltiPaymnetController`). Falling through to the next group is
     * more code than a blank card on an operator dashboard warrants; recorded rather than built.
     */
    public static function most_purchese_plan()
    {
        /*
         * GAP-231 -- SETTLED PURCHASES ONLY, AND THAT IS A DEFECT FIX RATHER THAN A DEFINITION
         * CHANGE. This counted EVERY `plan_orders` row, so a plan whose every purchase `failed`
         * could be presented to the operator as the platform's most popular plan. A ranking that
         * can be led by rows representing no money is wrong; it is not a choice about what
         * "popular" means.
         *
         * THE SPELLINGS COME FROM THE DATA, NOT FROM THE CONSTANT, and that is load-bearing:
         * `SaasRevenueFigures::SETTLED_PLAN_STATUSES` is lower-cased while the application also
         * stores `'Succeeded'` and `'Approved'` (the latter written by
         * `BankTransferController::orderapprove()`), and a lower-cased `whereIn` matches those on
         * MySQL's case-insensitive collation and NOT on SQLite's. `SettledPlanOrders` resolves
         * that in PHP and hands back the stored spellings, so this predicate means the same thing
         * in the suite and in production. Its docblock has the measurement.
         *
         * THE AGGREGATION STAYS IN THE DATABASE. This method has been repaired twice for pulling
         * every row into PHP; the predicate narrows the `GROUP BY`, it does not move it.
         *
         * `MIN(id)` IS THE TIE-BREAK AND SURVIVES THE PREDICATE -- a ranking with no stable
         * tie-break flaps between two values on identical data, which is worse than either answer.
         * Pinned by `test_a_tie_is_broken_stably_by_the_earliest_order`.
         */
        $ranked = PlanOrder::query()
            ->whereIn('payment_status', \App\Support\Operator\SettledPlanOrders::spellingsInUse())
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
