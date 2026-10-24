<?php

namespace Tests\Feature\Security;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * P1-PLT-02 -- FIVE PLAN-PAYMENT HANDLERS WROTE A PHANTOM HOST ORDER INSTEAD OF A PLAN ORDER.
 * =============================================================================================
 *
 * The ledger filed "two order writers persist a NULL store_id" (Mercado, Skrill). Reading them: in the free-plan branch (`$price <= 0`, i.e.
 * a 100% coupon) the handler calls `Order::create(['order_id'=>..., 'plan_name'=>..., 'plan_id'=>..., 'price'=>..., 'user_id'=>...])` with the
 * HOST `App\Models\Order` (the `orders` table of storefront orders) instead of `PlanOrder` (`plan_orders`, which every sibling gateway
 * writes). Mass assignment drops every key the host model does not list, so what lands is a blank host `orders` row -- payment_type the gateway
 * name, payment_status 'succeeded', `store_id` NULL, no customer, no lines -- and the platform's own record of the plan sale is never
 * written. Five handlers share it: Mercado, Skrill, Paystack, Razorpay and PaymentWall (the last is behind a fail-closed activation gate).
 *
 * ORACLES: the `orders` and `plan_orders` tables, raw. The same request is driven through the real route for each gateway.
 */
class PlanPaymentFreePlanRecordTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function freePlan(): Plan
    {
        return Plan::forceCreate(['name' => 'Free Plan', 'price' => 0, 'duration' => 'Unlimited', 'max_stores' => -1, 'max_products' => -1,
            'max_users' => -1, 'themes' => '[]', 'storage_limit' => '-1']);
    }

    public static function gateways(): array
    {
        return [
            'mercado' => ['/plan-pay-with-mercado', 'Mercado'],
            'skrill' => ['/plan-pay-with-skrill', 'Skrill'],
            'paystack' => ['/plan-pay-with-paystack', 'Paystack'],
            'razorpay' => ['/plan-pay-with-razorpay', 'Razorpay'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('gateways')]
    public function test_a_free_plan_is_recorded_as_a_plan_order_and_writes_no_host_order(string $uri, string $gateway): void
    {
        [$merchant] = $this->makeTenant();
        $plan = $this->freePlan();

        // The platform operator's currency setting, which these handlers index without a default.
        $operator = $this->makePlatformAdmin(['current_store' => 1]);
        DB::table('settings')->insert(['name' => 'CURRENCY_NAME', 'value' => 'USD', 'created_by' => $operator->id, 'store_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        \Illuminate\Support\Facades\Cache::forget('super_admin_details');

        // Mercado and Skrill assign `$authuser->plan` (alongside the real assignPlan()) before the order is written. No migration in this
        // repository declares a `users.plan` column; whether production has one is unverified (GATE F class). The fixture adds it so the
        // branch is REACHED here -- a handler that dies on that line never gets as far as the order, and the assertions below would
        // pass without testing anything. Recorded in the ledger as an operator check.
        if (! \Illuminate\Support\Facades\Schema::hasColumn('users', 'plan')) {
            \Illuminate\Support\Facades\Schema::table('users', fn ($table) => $table->unsignedBigInteger('plan')->nullable());
        }

        $hostOrdersBefore = DB::table('orders')->count();

        $response = $this->actingAs($merchant->fresh())->post($uri, ['plan_id' => Crypt::encrypt($plan->id)]);

        // Premise: the free-plan branch was reached (the plan was assigned), so "no host order" is not a refusal in disguise.
        $this->assertSame($plan->id, (int) DB::table('users')->where('id', $merchant->id)->value('plan_id'), "{$gateway}: the free-plan branch was not reached (status {$response->status()}).");

        $this->assertSame($hostOrdersBefore, DB::table('orders')->count(), "{$gateway} wrote a phantom host order (store_id NULL) for a plan purchase.");
        $this->assertSame(1, DB::table('plan_orders')->where('user_id', $merchant->id)->where('plan_id', $plan->id)->count(), "{$gateway} did not record the plan sale in plan_orders.");
    }

    /** The census: no plan-payment controller creates a host Order from a plan row. */
    public function test_no_plan_payment_controller_creates_a_host_order_from_plan_fields(): void
    {
        $offenders = [];

        foreach (glob(app_path('Http/Controllers/*PaymentController.php')) as $file) {
            $source = (string) file_get_contents($file);

            // `Order::create(` not preceded by Plan (PlanOrder::create is the right call) and followed within the call by plan_id/plan_name
            if (preg_match_all('/(?<![A-Za-z])Order::create\((.{0,900}?)\);/s', $source, $m)) {
                foreach ($m[1] as $call) {
                    if (str_contains($call, "'plan_id'") || str_contains($call, "'plan_name'")) {
                        $offenders[] = basename($file);
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)), 'A plan purchase is written as a HOST order.');
    }
}
