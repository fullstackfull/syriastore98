<?php

namespace App\Payments;

/**
 * RETIRED PAYMENT GATEWAYS -- WITHDRAWN BY THE OWNER, NOT TURNED OFF BY A TENANT.
 * =================================================================================================
 *
 * A gateway is offered to customers through a per-tenant DATABASE row,
 * `settings.is_<provider>_enabled`, read back by Utility::GetValueByName(). Eleven call sites
 * decide whether to offer a provider from that one row: the storefront list
 * (Setting::paymentList, Utility::payment_data), the Flutter API list
 * (Api\ApiController::payment_list), the plan-purchase page (StripePaymentController and
 * resources/views/plans/stripe.blade.php) and the two admin settings screens (SettingController,
 * PaymentSettingController).
 *
 * The owner has WITHDRAWN two of them outright: PayHere (its Composer package is gone, so every
 * call into it fatals) and YooKassa (its callback cannot be verified). Withdrawal is NOT the same
 * thing as a tenant switching a gateway off, and it must not be expressed by writing 'off' into
 * any tenant's row:
 *   - rows are per-store, there are many stores, and a new store writes its own;
 *   - a row is operator-writable, so one UI toggle or one seed brings the provider back;
 *   - the stored credentials are the OWNER'S DATA and must survive. Nothing in this class deletes
 *     a settings row or blanks a credential; only the enable value is ever overridden.
 *
 * So withdrawal lives HERE, in one list consulted by the code that READS the row. Every surface
 * asks this class rather than spelling out its own comparison, because a provider that is retired
 * in ten places out of eleven is a provider that comes back by accident. Retiring a third
 * provider is one line below.
 *
 * NAMED 'RETIRED', NOT 'DISABLED', on purpose. `is_<provider>_enabled` is the tenant's own on/off
 * setting and still means exactly what it always meant. This is a different and higher authority,
 * and conflating the two is how one gets reverted in the belief it was the other.
 *
 * WHY THIS IS ITS OWN CLASS AND NOT A METHOD ON Utility. Two tenancy ratchets --
 * EloquentScopeBypassInventoryTest::test_no_first_party_truncate_targets_a_scoped_model and
 * ResidualTenancyExposureTest::test_the_raw_query_entry_points_in_the_owned_models_are_exactly_these_twelve
 * -- pin ten raw-query and truncate() sites in app/Models/Utility.php by exact `path:line`, and
 * ResidualTenancyExposureTest's own docblock records that this has already turned them red twice
 * for changes that introduced no bypass. Adding ~140 lines to that file would have shifted all ten
 * and cost two CI failures for no behavioural reason. Utility's four call sites are therefore
 * one-for-one line replacements and the list lives here.
 *
 * WHAT THIS DOES NOT CLAIM. The three guard-protected call sites into PayHere::checkOut() --
 * packages/workdo/{Campaigns,Donation,LotteryPrizes}/src/app/Services/*PaymentService.php -- are
 * NOT repaired. `packages/` is byte-identical to main and may not be edited. This is containment
 * by UNAVAILABILITY: a customer can no longer select the provider, so those paths are not entered.
 *
 * Plan ACTIVATION is a separate and independent gate (config/payments.php, consulted by
 * GuardsPlanActivation::assertPlanActivationEnabled(), which aborts 403 before any write and where
 * both providers are already shipped-disabled). That gate stops a forged RETURN from activating a
 * plan; this list stops the provider being OFFERED in the first place. Both exist deliberately --
 * either one alone would be a single point of failure.
 */
class RetiredGateways
{
    /**
     * THE LIST. Lower-case canonical provider names.
     *
     * tests/Feature/Payments/RetiredProviderSurfaceTest asserts against this directly, so the
     * list and the behaviour cannot drift apart.
     */
    public const PROVIDERS = ['payhere', 'yookassa'];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::PROVIDERS;
    }

    /**
     * THE authoritative predicate. Case-insensitive, because this codebase spells the same two
     * providers 'payhere', 'PayHere', 'yookassa' and 'Yookassa' across the surfaces.
     */
    public static function isRetired($provider): bool
    {
        if (!is_string($provider) || $provider === '') {
            return false;
        }

        return in_array(strtolower($provider), self::PROVIDERS, true);
    }

    /**
     * The settings-row names the retired gateways are offered through, e.g.
     * ['is_payhere_enabled', 'is_yookassa_enabled'].
     *
     * @return list<string>
     */
    public static function enableKeys(): array
    {
        $keys = [];

        foreach (self::PROVIDERS as $provider) {
            $keys[] = 'is_' . $provider . '_enabled';
        }

        return $keys;
    }

    /**
     * Map a settings-row name back to a retired provider, or null for every other row.
     *
     * Only the `is_<provider>_enabled` row shape is recognised, so a CREDENTIAL row
     * (payhere_merchant_secret, yookassa_secret_key, the images, the descriptions) is never
     * matched and therefore never altered by anything built on this.
     */
    public static function providerForEnableKey($name): ?string
    {
        if (!is_string($name) || $name === '') {
            return null;
        }
        if (!preg_match('/^is_(.+)_enabled$/i', $name, $matches)) {
            return null;
        }

        return self::isRetired($matches[1]) ? strtolower($matches[1]) : null;
    }

    /**
     * The status a surface must publish for a gateway: 'off' for a retired one, and for every
     * other provider the tenant's stored value unchanged, with this codebase's existing
     * empty-means-off fallback.
     */
    public static function status($provider, $stored = null)
    {
        if (self::isRetired($provider)) {
            return 'off';
        }

        return !empty($stored) ? $stored : 'off';
    }

    /**
     * Force every retired gateway's enable row to 'off' in a settings array fetched by
     * getSuperAdminAllSetting() / getAdminAllSetting().
     *
     * The KEY IS KEPT and every credential value is KEPT, so the array's shape does not change:
     * a surface that tests `isset($settings['is_payhere_enabled'])` still finds the key, and an
     * admin field that prints a stored credential back still finds it. Only the enable value is
     * overridden.
     */
    public static function disableIn($settings)
    {
        if (!is_array($settings)) {
            return $settings;
        }

        foreach (array_keys($settings) as $key) {
            if (self::providerForEnableKey($key) !== null) {
                $settings[$key] = 'off';
            }
        }

        return $settings;
    }
}
