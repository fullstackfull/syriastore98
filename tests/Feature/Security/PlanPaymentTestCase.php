<?php

namespace Tests\Feature\Security;

use App\Models\Plan;
use App\Models\PlanCoupon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Shared fixtures for the plan-purchase security suites.
 *
 * Extracted so PlanPaymentBypassTest (the eSewa/Sofort proofs) and PlanPaymentFailClosedTest
 * (the twelve forgeable providers) use ONE definition of a paid plan and ONE definition of the
 * super-admin currency fixture. Two copies of seedSuperAdminCurrency() is exactly how a positive
 * test starts passing vacuously in one file and not the other.
 */
abstract class PlanPaymentTestCase extends SecurityTestCase
{
    protected function makePlan(string $price = '499'): Plan
    {
        return Plan::create([
            'name'             => 'Paid Plan ' . uniqid(),
            'price'            => $price,
            'duration'         => 'Monthly',
            'themes'           => '[]',
            'max_stores'       => '5',
            'max_products'     => '50',
            'max_users'        => '5',
            'storage_limit'    => '100',
            'enable_domain'    => 'off',
            'enable_subdomain' => 'off',
            'enable_chatgpt'   => 'off',
            'pwa_store'        => 'off',
        ]);
    }

    /**
     * Seed what getSuperAdminAllSetting() reads, so the COMPLETION path can actually run.
     *
     * Without this the legitimate return dies at `$admin_payment_setting['CURRENCY_NAME']` with
     * "Undefined array key" -- a pre-existing fixture gap in untouched upstream code, downstream
     * of every guard added here. A positive test would otherwise pass vacuously by never
     * reaching the order at all, which would make it worthless as proof that a real purchase
     * still works; and a NEGATIVE test would pass for the wrong reason, asserting a refusal
     * where the unfixed code would merely have 500ed. Both kinds seed it.
     *
     * getSuperAdminAllSetting() (app/Helper/helper.php) resolves the super admin through a
     * 3600-second Cache::remember('super_admin_details'), so the cache is flushed here --
     * otherwise the first test in the process fixes the value for every later one.
     *
     * @param array<string,string> $extra Further settings rows, e.g. a provider's merchant keys.
     */
    protected function seedSuperAdminCurrency(string $currency = 'USD', array $extra = []): void
    {
        Cache::flush();

        $admin = $this->makePlatformAdmin();

        $rows = array_merge(['CURRENCY_NAME' => $currency], $extra);

        foreach ($rows as $name => $value) {
            DB::table('settings')->insert([
                'name'       => $name,
                'value'      => $value,
                'created_by' => $admin->id,
                'store_id'   => $admin->current_store,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Turn a provider's plan activation on for one test, the way the owner would with an env key.
     *
     * The shipped default is DISABLED for every provider on the map; PlanPaymentPostureTest
     * asserts that separately. These tests override it in memory so that the ACTIVATION path can
     * be exercised at all -- otherwise the positive and replay proofs would be asserting the
     * refusal twice over and proving nothing about a genuine payment.
     */
    protected function enableProvider(string $provider): void
    {
        config(['payments.plan_activation.' . $provider => true]);
    }

    protected function makeCoupon(string $code, int $discountPercent, int $limit = 5): PlanCoupon
    {
        $coupon = PlanCoupon::create([
            'name'     => 'Coupon ' . $code,
            'code'     => $code,
            'discount' => $discountPercent,
            'limit'    => $limit,
        ]);

        $coupon->is_active = 1;
        $coupon->save();

        return $coupon;
    }
}
