<?php

namespace Tests\Feature\Payments;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\PaymentSettingController;
use App\Models\Setting;
use App\Models\Utility;
use App\Payments\RetiredGateways;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use ReflectionMethod;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE TWO RETIRED GATEWAYS MUST NOT BE SELECTABLE, WHATEVER THE TENANT'S ROW SAYS.
 * =================================================================================================
 *
 * The owner withdrew PayHere (its Composer package is gone, so every call into it fatals) and
 * YooKassa (its callback cannot be verified). A gateway is offered through a per-tenant DATABASE
 * row, `settings.is_<provider>_enabled`, so withdrawal is enforced in the code that READS that
 * row -- RetiredGateways::PROVIDERS and the helpers beside it -- and not by writing to any row.
 *
 * EVERY TEST HERE SETS THE TENANT'S ROW TO 'on' FIRST. That is the whole point: the database says
 * on, the code says no. A test that left the row at its default would pass against completely
 * unmodified code and would prove nothing.
 *
 * AND EVERY TEST CARRIES A CONTROL. Asserting only "payhere is off" is satisfiable by a fixture
 * that never took effect, by a surface that returns 'off' for everything, or by a store whose
 * settings were never read. So each surface is also asked about a NON-retired gateway whose row
 * was set 'on' by the same fixture in the same way, and that one must come back 'on'. If the
 * fixture is broken the control fails, and the retired assertion is never trusted on its own.
 *
 * WHAT IS DELIBERATELY NOT ASSERTED HERE. That the three guard-protected
 * packages/workdo/{Campaigns,Donation,LotteryPrizes} call sites into PayHere::checkOut() were
 * repaired. They were not; `packages/` is byte-identical to main and may not be edited. The claim
 * under test is containment by UNAVAILABILITY: the provider cannot be selected, so those paths are
 * not entered. Plan ACTIVATION is a separate gate with its own suites
 * (config/payments.php + GuardsPlanActivation, asserted by PlanPaymentFailClosedTest).
 */
class RetiredProviderSurfaceTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** A non-retired gateway used as the control in every surface assertion. */
    private const CONTROL_PROVIDER = 'paytr';

    /** Its index in the storefront and Flutter API lists. */
    private const CONTROL_INDEX = 24;

    /**
     * 'other_payment', which untouched upstream code pins to 'off' with a literal at
     * app/Models/Setting.php:58. It reads no settings row, so it is off on the base branch too.
     */
    private const UPSTREAM_PINNED_OFF_INDEX = 2;

    private const YOOKASSA_INDEX = 25;
    private const PAYHERE_INDEX = 30;

    /**
     * Turn on EVERY `is_*_enabled` row this surface reads, for this store, plus a credential for
     * each retired provider so that a surface which also requires credentials is satisfied too.
     *
     * The key list is read out of the surface's own source rather than typed here, so a gateway
     * added upstream is covered automatically and the "we lost exactly two" count below cannot
     * quietly stop meaning anything.
     *
     * @return list<string> the enable keys that were switched on
     */
    private function enableEveryGatewayFor(int $storeId, string $sourceFile): array
    {
        $source = file_get_contents(base_path($sourceFile));

        preg_match_all("/'(is_[A-Za-z0-9_]+_enabled)'/", $source, $matches);
        $keys = array_values(array_unique($matches[1]));

        $this->assertGreaterThan(
            40,
            count($keys),
            'Fixture sanity: ' . $sourceFile . ' should read dozens of gateway enable rows. '
            . 'If this drops, the pattern stopped matching and every count below is meaningless.'
        );

        $rows = array_fill_keys($keys, 'on');

        // Credentials the plan/storefront surfaces additionally require before they will offer a
        // provider. Stored as the owner's data would be -- and asserted intact further down.
        $rows['payhere_merchant_secret'] = 'stored-payhere-secret';
        $rows['payhere_merchant_id'] = 'stored-payhere-merchant';
        $rows['yookassa_shop_id_key'] = 'stored-yookassa-shop';
        $rows['yookassa_secret_key'] = 'stored-yookassa-secret';
        $rows['paytr_merchant_id'] = 'stored-paytr-merchant';
        $rows['paytr_merchant_key'] = 'stored-paytr-key';
        $rows['paytr_salt_key'] = 'stored-paytr-salt';

        foreach ($rows as $name => $value) {
            Setting::create([
                'name' => $name,
                'value' => $value,
                'store_id' => $storeId,
                'created_by' => 1,
            ]);
        }

        return $keys;
    }

    // ---------------------------------------------------------------------------------------
    // The list itself. One place to change, one place to assert.
    // ---------------------------------------------------------------------------------------

    public function test_the_retired_gateway_list_is_exactly_payhere_and_yookassa(): void
    {
        $this->assertSame(
            ['payhere', 'yookassa'],
            RetiredGateways::all(),
            'The owner retired exactly two providers. Anything else in this list is a gateway '
            . 'that has been switched off for every tenant without the owner asking.'
        );

        $this->assertTrue(RetiredGateways::isRetired('payhere'));
        $this->assertTrue(RetiredGateways::isRetired('yookassa'));

        // The codebase spells the same two providers four different ways across the surfaces.
        $this->assertTrue(RetiredGateways::isRetired('PayHere'));
        $this->assertTrue(RetiredGateways::isRetired('Yookassa'));

        // And every other gateway is untouched by it.
        foreach (['stripe', 'paypal', 'paytr', 'razorpay', 'khalti', 'authorizenet', 'tap'] as $kept) {
            $this->assertFalse(
                RetiredGateways::isRetired($kept),
                $kept . ' is a working gateway and must not be caught by the retired list.'
            );
        }

        // The predicate must match the enable ROW, and must never match a CREDENTIAL row --
        // matching one would blank the owner's stored keys through the same code path.
        $this->assertSame('payhere', RetiredGateways::providerForEnableKey('is_payhere_enabled'));
        $this->assertNull(RetiredGateways::providerForEnableKey('is_stripe_enabled'));
        $this->assertNull(RetiredGateways::providerForEnableKey('payhere_merchant_secret'));
        $this->assertNull(RetiredGateways::providerForEnableKey('yookassa_secret_key'));
    }

    // ---------------------------------------------------------------------------------------
    // The reader every surface goes through.
    // ---------------------------------------------------------------------------------------

    public function test_the_settings_reader_returns_off_although_the_tenant_row_says_on(): void
    {
        [, $store] = $this->makeTenant();

        $this->enableEveryGatewayFor($store->id, 'app/Models/Setting.php');

        // Precondition, read straight off the table: the row really does say 'on'. Without this
        // the test could be passing because nothing was ever written.
        $this->assertSame(
            'on',
            Setting::where('store_id', $store->id)->where('name', 'is_payhere_enabled')->value('value'),
            'Precondition: the tenant row must say on, so that an off answer is the CODE talking.'
        );

        $this->assertSame('off', Utility::GetValueByName('is_payhere_enabled', $store->id));
        $this->assertSame('off', Utility::GetValueByName('is_yookassa_enabled', $store->id));

        // GetValByName is the second reader of the same rows and caches per store FOREVER, so a
        // stale 'on' there would outlive the row it came from. It is guarded too.
        $this->assertSame('off', Utility::GetValByName('is_payhere_enabled', $store->id));
        $this->assertSame('off', Utility::GetValByName('is_yookassa_enabled', $store->id));

        // Control: the same fixture, the same readers, a gateway that was not retired.
        $this->assertSame('on', Utility::GetValueByName('is_paytr_enabled', $store->id));
        $this->assertSame('on', Utility::GetValueByName('is_stripe_enabled', $store->id));
        $this->assertSame('on', Utility::GetValByName('is_paytr_enabled', $store->id));

        // The stored credentials come back untouched. Retiring a gateway must not destroy or
        // blank the owner's data.
        $this->assertSame(
            'stored-payhere-secret',
            Utility::GetValueByName('payhere_merchant_secret', $store->id)
        );
        $this->assertSame(
            'stored-yookassa-secret',
            Utility::GetValueByName('yookassa_secret_key', $store->id)
        );
    }

    // ---------------------------------------------------------------------------------------
    // Surface 1: the storefront payment list.
    // ---------------------------------------------------------------------------------------

    public function test_the_storefront_list_reports_retired_gateways_off_although_the_rows_say_on(): void
    {
        [, $store] = $this->makeTenant();
        $this->enableEveryGatewayFor($store->id, 'app/Models/Setting.php');

        $list = Setting::paymentList($store->slug);

        $this->assertSame('PayHere', $list[self::PAYHERE_INDEX]['name_string']);
        $this->assertSame('off', $list[self::PAYHERE_INDEX]['status']);
        $this->assertSame('yookassa', $list[self::YOOKASSA_INDEX]['name']);
        $this->assertSame('off', $list[self::YOOKASSA_INDEX]['status']);

        $this->assertSame(
            'on',
            $list[self::CONTROL_INDEX]['status'],
            'Control: PayTR was switched on by the same fixture and must still be offered. '
            . 'If this is off, the surface is reporting off for everything and the two '
            . 'assertions above prove nothing.'
        );
    }

    public function test_the_storefront_list_loses_exactly_two_gateways_and_keeps_every_other_one(): void
    {
        [, $store] = $this->makeTenant();
        $this->enableEveryGatewayFor($store->id, 'app/Models/Setting.php');

        $list = Setting::paymentList($store->slug);

        $offered = [];
        $withheld = [];

        foreach ($list as $index => $entry) {
            if (!isset($entry['status'])) {
                continue;
            }
            if ($entry['status'] === 'on') {
                $offered[] = $index;
            } else {
                $withheld[] = $index;
            }
        }

        // The real count, not an eyeball: with EVERY enable row set to 'on', the only entries
        // this list withholds are index 2 and the two retired ones. A fourth would be a working
        // gateway taken away from the merchant.
        //
        // Index 2 is NOT ours. 'other_payment' is pinned to 'off' unconditionally by untouched
        // upstream code (app/Models/Setting.php:58, a literal `= 'off'`); it reads no settings
        // row at all, so no fixture can switch it on and it is off on the base branch too. It is
        // named here rather than filtered out, so that if upstream ever wires it up this test
        // reports the change instead of hiding it.
        $this->assertSame('other_payment', $list[self::UPSTREAM_PINNED_OFF_INDEX]['name']);
        $this->assertFalse(RetiredGateways::isRetired('other_payment'));

        $this->assertSame(
            [self::UPSTREAM_PINNED_OFF_INDEX, self::YOOKASSA_INDEX, self::PAYHERE_INDEX],
            $withheld,
            'Our change may withhold exactly two gateways, on top of upstream\'s own pinned-off '
            . 'index ' . self::UPSTREAM_PINNED_OFF_INDEX . '. Withheld indexes: '
            . implode(',', $withheld)
        );

        $this->assertCount(
            count($list) - 3,
            $offered,
            'Every other entry in the list must still be offered.'
        );

        // The INDEXES are not renumbered. Repo B and other call sites address entries by
        // position, so forcing a status off is right and removing an entry is not.
        $this->assertArrayHasKey(self::PAYHERE_INDEX, $list);
        $this->assertArrayHasKey(self::YOOKASSA_INDEX, $list);
        $this->assertSame(
            range(0, count($list) - 1),
            array_keys($list),
            'The list must stay a dense 0..n index set -- nothing deleted, nothing shifted.'
        );
    }

    public function test_the_per_provider_storefront_lookup_reports_retired_gateways_off(): void
    {
        [, $store] = $this->makeTenant();
        $this->enableEveryGatewayFor($store->id, 'app/Models/Utility.php');

        $payhere = Utility::payment_data('PayHere', $store->id);
        $yookassa = Utility::payment_data('yookassa', $store->id);
        $control = Utility::payment_data(self::CONTROL_PROVIDER, $store->id);

        $this->assertSame('off', $payhere['status']);
        $this->assertSame('off', $yookassa['status']);
        $this->assertSame(
            'on',
            $control['status'],
            'Control: PayTR must still report on through the same lookup.'
        );

        // Shape kept: the keys this lookup has always returned are still returned.
        $this->assertSame('PayHere', $payhere['name']);
        $this->assertArrayHasKey('payhere_merchant_secret', $payhere);
        $this->assertSame('stored-payhere-secret', $payhere['payhere_merchant_secret']);
    }

    // ---------------------------------------------------------------------------------------
    // Surface 2: the Flutter API list. A SHIPPED CONTRACT -- the shape may not move.
    // ---------------------------------------------------------------------------------------

    public function test_the_flutter_api_list_reports_retired_gateways_off_and_keeps_its_shape(): void
    {
        [, $store] = $this->makeTenant();
        $this->enableEveryGatewayFor($store->id, 'app/Http/Controllers/Api/ApiController.php');

        $request = Request::create('/api/' . $store->slug . '/payment-list', 'POST');
        $list = app(ApiController::class)->payment_list($request, $store->slug)->getData(true);
        $list = $list['data'] ?? $list;

        $this->assertSame('off', $list[self::PAYHERE_INDEX]['status']);
        $this->assertSame('off', $list[self::YOOKASSA_INDEX]['status']);
        $this->assertSame(
            'on',
            $list[self::CONTROL_INDEX]['status'],
            'Control: PayTR must still be advertised to the app through the same response.'
        );

        // THE CONTRACT. Repo B parses this response; a missing key or a shifted index is a
        // client-side crash, which is why the status is forced and the entry is not deleted.
        foreach ([self::PAYHERE_INDEX => 'payhere', self::YOOKASSA_INDEX => 'yookassa'] as $index => $nameString) {
            $this->assertArrayHasKey($index, $list, 'Index ' . $index . ' must still exist.');

            foreach (['status', 'name_string', 'name', 'detail', 'image'] as $key) {
                $this->assertArrayHasKey(
                    $key,
                    $list[$index],
                    'Repo B reads ' . $key . ' from every entry; it must still be present.'
                );
            }

            $this->assertSame($nameString, $list[$index]['name_string']);
        }

        // The provider-specific keys repo B reads for these two entries are still there as well.
        $this->assertArrayHasKey('payhere_merchant_id', $list[self::PAYHERE_INDEX]);
        $this->assertArrayHasKey('payhere_mode', $list[self::PAYHERE_INDEX]);
        $this->assertArrayHasKey('yookassa_shop_id_key', $list[self::YOOKASSA_INDEX]);
        $this->assertArrayHasKey('yookassa_secret_key', $list[self::YOOKASSA_INDEX]);

        $this->assertSame(
            range(0, count($list) - 1),
            array_keys($list),
            'The API list must stay a dense 0..n index set: repo B addresses entries by position.'
        );
    }

    // ---------------------------------------------------------------------------------------
    // Surface 3: the admin settings screen. An operator may not switch one back on.
    // ---------------------------------------------------------------------------------------

    public function test_an_admin_attempt_to_re_enable_a_retired_gateway_does_not_make_it_selectable(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->enableEveryGatewayFor($store->id, 'app/Models/Setting.php');

        // The operator's stored credential, before the attempt.
        $this->assertSame(
            'stored-payhere-secret',
            Setting::where('store_id', $store->id)
                ->where('name', 'payhere_merchant_secret')->value('value')
        );

        $this->actingAs($merchant);

        // The exact form submission an operator makes when they tick the toggle and save: the
        // retired gateway on, with every required credential filled in so that nothing else
        // could be the reason it is refused.
        $request = Request::create('/payment-setting', 'POST', [
            'is_payhere_enabled' => 'on',
            'payhere_merchant_id' => 'attacker-merchant',
            'payhere_merchant_secret' => 'attacker-secret',
            'payhere_app_id' => 'attacker-app',
            'payhere_app_secret' => 'attacker-app-secret',
            'payhere_mode' => 'live',
            'is_yookassa_enabled' => 'on',
            'yookassa_shop_id_key' => 'attacker-shop',
            'yookassa_secret_key' => 'attacker-secret',
            'is_paytr_enabled' => 'on',
            'paytr_merchant_id' => 'operator-merchant',
            'paytr_merchant_key' => 'operator-key',
            'paytr_salt_key' => 'operator-salt',
        ]);
        $request->setLaravelSession($this->app['session']->driver());

        app(PaymentSettingController::class)->update($request);

        // THE ROW, not a status code: the attempt did not turn it on.
        $this->assertSame(
            'off',
            Setting::where('store_id', $store->id)
                ->where('name', 'is_payhere_enabled')->value('value')
        );
        $this->assertSame(
            'off',
            Setting::where('store_id', $store->id)
                ->where('name', 'is_yookassa_enabled')->value('value')
        );

        // And it is still not offered to a customer afterwards.
        $list = Setting::paymentList($store->slug);
        $this->assertSame('off', $list[self::PAYHERE_INDEX]['status']);
        $this->assertSame('off', $list[self::YOOKASSA_INDEX]['status']);

        // Control: the same submission DID enable a non-retired gateway, so the refusal above is
        // specific to the retired two and not a form that simply failed.
        $this->assertSame(
            'on',
            Setting::where('store_id', $store->id)
                ->where('name', 'is_paytr_enabled')->value('value')
        );
        $this->assertSame('on', $list[self::CONTROL_INDEX]['status']);

        // The owner's previously stored credential was NOT overwritten by the attempt and NOT
        // deleted by the refusal. Retiring a gateway does not touch stored data.
        $this->assertSame(
            'stored-payhere-secret',
            Setting::where('store_id', $store->id)
                ->where('name', 'payhere_merchant_secret')->value('value'),
            'The refused submission must neither write the attacker value nor delete the row.'
        );
    }

    public function test_the_admin_screen_still_shows_the_retired_gateway_with_its_toggle_off(): void
    {
        $settings = [
            'is_payhere_enabled' => 'on',
            'is_yookassa_enabled' => 'on',
            'is_paytr_enabled' => 'on',
            'payhere_merchant_secret' => 'stored-payhere-secret',
        ];

        $normalised = RetiredGateways::disableIn($settings);

        // The toggle the admin partial renders is driven by this value: it must read off.
        $this->assertSame('off', $normalised['is_payhere_enabled']);
        $this->assertSame('off', $normalised['is_yookassa_enabled']);
        $this->assertSame('on', $normalised['is_paytr_enabled']);

        // Shape and data kept: the key is still present (surfaces test it with isset()) and the
        // stored credential is still there, so the block renders with its values intact rather
        // than vanishing and orphaning the owner's data.
        $this->assertArrayHasKey('is_payhere_enabled', $normalised);
        $this->assertSame('stored-payhere-secret', $normalised['payhere_merchant_secret']);
        $this->assertSame(array_keys($settings), array_keys($normalised));

        // The screen says WHY, rather than silently ignoring the operator.
        $annotate = new ReflectionMethod(\App\Http\Controllers\SettingController::class, 'annotateRetiredGateways');
        $annotate->setAccessible(true);

        $gateways = $annotate->invoke(
            app(\App\Http\Controllers\SettingController::class),
            [
                ['id' => 'payhere', 'name' => 'PayHere', 'is_only_admin' => false],
                ['id' => 'paytr', 'name' => 'PayTR', 'is_only_admin' => false],
            ]
        );

        $this->assertTrue($gateways[0]['retired']);
        $this->assertStringContainsString('retired', $gateways[0]['name']);
        $this->assertFalse(
            isset($gateways[1]['retired']),
            'A working gateway must not be labelled retired.'
        );
        $this->assertSame('PayTR', $gateways[1]['name']);

        // The block is still rendered: is_only_admin drives the partial's visibility and must
        // survive, because deleting the block would orphan the stored credentials.
        $this->assertFalse($gateways[0]['is_only_admin']);
    }

    // ---------------------------------------------------------------------------------------
    // Surface 4: the plan-purchase page.
    // ---------------------------------------------------------------------------------------

    public function test_a_retired_gateway_alone_does_not_open_the_plan_purchase_page(): void
    {
        // Only retired providers configured, both switched on, both with credentials.
        $settings = [
            'is_payhere_enabled' => 'on',
            'payhere_merchant_secret' => 'stored-payhere-secret',
            'is_yookassa_enabled' => 'on',
            'yookassa_shop_id_key' => 'stored-yookassa-shop',
            'yookassa_secret_key' => 'stored-yookassa-secret',
        ];

        $normalised = RetiredGateways::disableIn($settings);

        // This is the data StripePaymentController::stripe() runs its "is ANY gateway enabled"
        // test against, and the data resources/views/plans/stripe.blade.php is rendered from.
        // With only retired providers configured the answer must be no, so the page is not
        // opened and no retired tab can be reached.
        $anyEnabled = false;

        foreach ($normalised as $name => $value) {
            if (RetiredGateways::providerForEnableKey($name) === null
                && str_starts_with($name, 'is_')
                && str_ends_with($name, '_enabled')
                && $value === 'on'
            ) {
                $anyEnabled = true;
            }
        }

        $this->assertFalse($anyEnabled);
        $this->assertNotSame('on', $normalised['is_payhere_enabled']);
        $this->assertNotSame('on', $normalised['is_yookassa_enabled']);

        // The credentials the blade's own conditions test with !empty() are still present, which
        // is why the status alone cannot be what closes the two tabs -- see the next test.
        $this->assertSame('stored-payhere-secret', $normalised['payhere_merchant_secret']);
    }

    public function test_the_plan_purchase_tabs_are_closed_by_the_predicate_not_by_the_status(): void
    {
        // The shipped conditions are lifted VERBATIM out of resources/views/plans/stripe.blade.php
        // and evaluated against a settings array in which both retired providers are switched on
        // AND fully credentialled -- so nothing but the retired predicate can be withholding them.
        //
        // Why the conditions are evaluated rather than the whole page rendered: this view's layout
        // chain (partision/header.blade.php) needs a resolved current store and would make the
        // test about the layout. Why they are not just grepped for: a grep proves a string is in
        // the file, not that the condition is false. These are run.
        $settings = [
            'is_payhere_enabled' => 'on',
            'payhere_merchant_secret' => 'stored-payhere-secret',
            'payhere_merchant_id' => 'stored-payhere-merchant',
            'is_yookassa_enabled' => 'on',
            'yookassa_shop_id_key' => 'stored-yookassa-shop',
            'yookassa_secret_key' => 'stored-yookassa-secret',
            'is_paytr_enabled' => 'on',
            'paytr_merchant_id' => 'stored-paytr-merchant',
            'paytr_merchant_key' => 'stored-paytr-key',
            'paytr_salt_key' => 'stored-paytr-salt',
        ];

        $conditions = $this->planPurchaseConditions();

        $retired = [];
        $control = [];

        foreach ($conditions as $condition) {
            $isRetired = stripos($condition, 'payhere') !== false
                || stripos($condition, 'yookassa') !== false;
            $isControl = !$isRetired && stripos($condition, self::CONTROL_PROVIDER) !== false;

            // Only the gateway conditions are evaluated. The view's other conditions read
            // things this test has no business standing up (Auth::user()->type and friends),
            // and evaluating them would turn a payment test into a fixture exercise.
            if (!$isRetired && !$isControl) {
                continue;
            }

            $offered = str_contains(
                Blade::render("@if (" . $condition . ")\nOFFERED\n@endif\n", [
                    'admin_payments_details' => $settings,
                ]),
                'OFFERED'
            );

            if ($isRetired) {
                $retired[$condition] = $offered;
            } else {
                $control[$condition] = $offered;
            }
        }

        // Two per provider: the sidebar link and the payment form itself.
        $this->assertCount(
            4,
            $retired,
            'Expected 4 plan-purchase conditions mentioning a retired provider (a sidebar link '
            . 'and a payment form each). Found: ' . count($retired) . '. If the view changed, '
            . 'this test must be looked at rather than adjusted.'
        );

        foreach ($retired as $condition => $offered) {
            $this->assertFalse(
                $offered,
                'A retired provider is still offered on the plan-purchase page by: '
                . preg_replace('/\s+/', ' ', $condition)
            );
        }

        // THE CONTROL, and the reason this test exists at all. PayTR's conditions are written
        // the same two ways, from the same array, and they must still evaluate TRUE. If they did
        // not, the four assertions above would be satisfied by a broken fixture.
        $this->assertCount(2, $control);

        foreach ($control as $condition => $offered) {
            $this->assertTrue(
                $offered,
                'Control: PayTR must still be offered on the plan-purchase page. Condition: '
                . preg_replace('/\s+/', ' ', $condition)
            );
        }

        // AND WHY THE STATUS ALONE COULD NOT HAVE DONE IT. Two of the four shipped conditions are
        // `isset($x) == 'on'`. isset() yields a BOOLEAN, and `true == 'on'` is TRUE in PHP, so
        // those two are satisfied whenever the KEY EXISTS, at any value -- including 'off'. This
        // is a pre-existing upstream defect; it is recorded here because it is exactly why
        // forcing the stored value to 'off' is NOT sufficient on this surface, and why the
        // retired predicate is the first term in each condition.
        $this->assertTrue(
            isset($settings['is_payhere_enabled']) == 'on',
            'The shipped `isset($x) == \'on\'` idiom is true even when the value is off.'
        );

        $offSettings = $settings;
        $offSettings['is_payhere_enabled'] = 'off';
        $offSettings['is_yookassa_enabled'] = 'off';

        $formConditions = array_values(array_filter(
            array_keys($retired),
            fn ($condition) => str_contains($condition, "isset(\$admin_payments_details")
                && str_contains($condition, "]) == 'on'")
        ));

        $this->assertCount(
            2,
            $formConditions,
            'Expected the two payment-form conditions to still use the `isset($x) == \'on\'` idiom.'
        );

        foreach ($formConditions as $condition) {
            // Same condition, retired term removed: it renders even with the value 'off'. That is
            // the measurement behind the claim above.
            $withoutPredicate = preg_replace(
                '/^\s*!\\\\App\\\\Payments\\\\RetiredGateways::isRetired\([^)]*\)\s*&&\s*/',
                '',
                $condition
            );

            $this->assertNotSame(
                $condition,
                $withoutPredicate,
                'The retired predicate must be the FIRST term of each payment-form condition.'
            );

            $this->assertStringContainsString(
                'OFFERED',
                Blade::render("@if (" . $withoutPredicate . ")\nOFFERED\n@endif\n", [
                    'admin_payments_details' => $offSettings,
                ]),
                'Without the predicate this condition is true even with the stored value off, '
                . 'which is why a status-only change would have been vacuous here.'
            );
        }
    }

    /**
     * Every `@if` condition in the plan-purchase view, lifted out with balanced-paren matching
     * so a multi-line condition is captured whole.
     *
     * @return list<string>
     */
    private function planPurchaseConditions(): array
    {
        $blade = file_get_contents(base_path('resources/views/plans/stripe.blade.php'));
        $length = strlen($blade);
        $conditions = [];
        $offset = 0;

        while (($position = strpos($blade, '@if (', $offset)) !== false) {
            $index = $position + strlen('@if (');
            $start = $index;
            $depth = 1;

            while ($index < $length && $depth > 0) {
                if ($blade[$index] === '(') {
                    $depth++;
                } elseif ($blade[$index] === ')') {
                    $depth--;
                }
                $index++;
            }

            $conditions[] = trim(substr($blade, $start, $index - 1 - $start));
            $offset = $index;
        }

        $this->assertGreaterThan(
            100,
            count($conditions),
            'Fixture sanity: the plan-purchase view has well over a hundred @if conditions. '
            . 'A low count means the extraction broke and every assertion below is meaningless.'
        );

        return $conditions;
    }
}
