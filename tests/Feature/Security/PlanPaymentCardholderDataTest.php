<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * A PAN POSTED BY A BROWSER MUST NOT END UP IN plan_orders.
 * =============================================================================================
 *
 * CyberSourceController::planPayWithCyberSourceData wrote the raw card number the browser posted
 * straight into plan_orders.card_number -- a column declared
 * `$table->string('card_number', 10)` at
 * database/migrations/2023_12_11_102124_create_plan_orders_table.php:19. A 16-digit PAN
 * therefore has two possible fates and both are bad: under MySQL strict mode the INSERT errors
 * outright (so a successfully CHARGED card fails to record its purchase at all), and otherwise
 * it is SILENTLY TRUNCATED to the first ten digits -- the issuer identification number plus the
 * start of the account, stored at rest, with none of the obligations that come with holding
 * cardholder data.
 *
 * THIS IS NOT A REVENUE HOLE, and the distinction matters for how it was fixed. Activation on
 * this path IS genuinely provider-verified: the handler posts to
 * apitest.cybersource.com/pts/v2/payments with an HMAC-signed digest and requires
 * `$result['status'] == "AUTHORIZED"`, so a forged return cannot activate a plan and CyberSource
 * is NOT on the config/payments.php disabled map. The outbound request is untouched -- the
 * provider must receive the full number -- and only what is PERSISTED changed.
 *
 * WHAT IS ASSERTED, and why each assertion is the thing that differs:
 *
 *   THE PAN IS NOT IN THE ROW. Not "the column is short enough" -- the actual digits, searched
 *   for across the whole row, because a truncated PAN still satisfies the column.
 *
 *   THE LAST FOUR ARE. Dropping the field entirely would also pass the assertion above while
 *   breaking every receipt, so the positive half is asserted too.
 *
 *   THE PROVIDER STILL RECEIVES THE FULL NUMBER. The point of the fix is that the payment
 *   architecture is unchanged; an "improvement" that masked the outbound number would break
 *   real payments silently, and no test on the stored row would notice.
 *
 *   AND THE PLAN IS STILL ACTIVATED, so this is not passing because the handler stopped working.
 */
class PlanPaymentCardholderDataTest extends PlanPaymentTestCase
{
    use RefreshDatabase;

    /** A Luhn-valid Visa test number, 16 digits -- six more than the column can hold. */
    private const PAN = '4111111111111111';

    private function fakeAuthorizedCyberSource(): void
    {
        Http::fake([
            'apitest.cybersource.com/*' => Http::response(['status' => 'AUTHORIZED'], 201),
        ]);
    }

    /**
     * @return array{0:\App\Models\User,1:\App\Models\Plan}
     */
    private function purchase(array $overrides = []): array
    {
        $this->seedSuperAdminCurrency('USD', [
            'cybersource_pay_api_key'     => 'key',
            'cybersource_pay_merchant_id' => 'merchant',
            // base64, because the handler base64_decode()s it before using it as an HMAC key.
            'cybersource_pay_secret_key'  => base64_encode('secret'),
            'defult_currancy'             => 'USD',
        ]);

        $merchant = $this->makeMerchant();
        $plan     = $this->makePlan('499');

        $this->actingAs($merchant)->post(route('plan.get.cybersource.status'), array_merge([
            'data'       => json_encode([
                'id'          => $plan->id,
                'amount'      => 499,
                'duration'    => 'Monthly',
                'coupon_code' => null,
            ]),
            'cardNumber' => self::PAN,
            'month'      => '12',
            'year'       => '2030',
            'owner'      => 'A Merchant',
        ], $overrides));

        return [$merchant, $plan];
    }

    public function test_a_posted_card_number_is_not_persisted_on_the_plan_order(): void
    {
        $this->fakeAuthorizedCyberSource();

        [, $plan] = $this->purchase();

        $order = DB::table('plan_orders')->where('plan_id', $plan->id)->first();

        $this->assertNotNull(
            $order,
            'No plan_orders row was written, so this test is not exercising the card_number '
            . 'write at all. Check the Http::fake and the CyberSource settings fixture before '
            . 'trusting the assertions below.'
        );

        // THE WHOLE ROW, not just the column: a PAN that moved to another field would otherwise
        // pass. This is the assertion the defect fails.
        $this->assertStringNotContainsString(
            self::PAN,
            json_encode($order),
            'The raw card number the browser posted is stored on the plan order. Under MySQL '
            . 'strict mode this INSERT would have failed outright (card_number is varchar(10)); '
            . 'without strict mode it is silently truncated and the row is cardholder data at '
            . 'rest. Store the last four digits only.'
        );

        // And no leading fragment of it either -- truncation to 10 characters is the non-strict
        // MySQL outcome and is just as much cardholder data as the full number.
        $this->assertStringNotContainsString(
            substr(self::PAN, 0, 10),
            (string) $order->card_number,
            'card_number holds the first ten digits of the PAN -- the issuer identification '
            . 'number and the start of the account. That is what silent truncation produces; it '
            . 'is not a fix.'
        );
    }

    public function test_the_last_four_digits_are_kept_so_a_receipt_still_identifies_the_card(): void
    {
        $this->fakeAuthorizedCyberSource();

        [, $plan] = $this->purchase();

        $this->assertSame(
            '1111',
            (string) DB::table('plan_orders')->where('plan_id', $plan->id)->value('card_number'),
            'The last four digits are no longer recorded. Dropping the field entirely also '
            . 'satisfies the no-PAN assertion while leaving every receipt unable to say which '
            . 'card was charged, which is why this half is asserted separately.'
        );
    }

    public function test_a_formatted_card_number_still_yields_four_digits_not_four_spaces(): void
    {
        $this->fakeAuthorizedCyberSource();

        [, $plan] = $this->purchase(['cardNumber' => '4111 1111 1111 1111']);

        $this->assertSame(
            '1111',
            (string) DB::table('plan_orders')->where('plan_id', $plan->id)->value('card_number'),
            'A card number typed with spaces produced something other than its last four '
            . 'digits. substr() alone on a formatted number returns formatting.'
        );
    }

    public function test_an_absent_card_number_is_recorded_as_null_not_as_a_card_ending_0000(): void
    {
        $this->fakeAuthorizedCyberSource();

        [, $plan] = $this->purchase(['cardNumber' => '']);

        $this->assertNull(
            DB::table('plan_orders')->where('plan_id', $plan->id)->value('card_number'),
            'A missing card number is recorded as something other than null. The previous '
            . 'expression recorded the integer 0 here, which reads like a card ending 0000.'
        );
    }

    /**
     * THE PROVIDER STILL GETS THE FULL NUMBER. This is the half that a careless "mask the card
     * number" change breaks, and nothing about the stored row would reveal it: payments would
     * simply start being declined.
     */
    public function test_the_full_card_number_is_still_sent_to_cybersource(): void
    {
        $this->fakeAuthorizedCyberSource();

        $this->purchase();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'apitest.cybersource.com/pts/v2/payments')
                && ($request->data()['paymentInformation']['card']['number'] ?? null) === self::PAN;
        });
    }

    /**
     * And the purchase still completes, so none of the above is passing because the handler
     * stopped recording anything.
     */
    public function test_the_verified_purchase_still_activates_the_plan_and_records_its_price(): void
    {
        $this->fakeAuthorizedCyberSource();

        [$merchant, $plan] = $this->purchase();

        $this->assertSame(
            (string) $plan->id,
            (string) $merchant->fresh()->plan_id,
            'An AUTHORIZED CyberSource payment no longer activates the plan.'
        );

        $this->assertEqualsWithDelta(
            499.0,
            (float) DB::table('plan_orders')->where('plan_id', $plan->id)->value('price'),
            0.009,
            "The recorded price is not the plan's 499."
        );
    }

    /**
     * A return that CyberSource did not authorize must not activate anything.
     *
     * This is the provider verification that keeps CyberSource off the config/payments.php
     * disabled map, so it is asserted here rather than assumed: if it were lost, this handler
     * would join the eleven in PlanPaymentNewlyGuardedTest with nothing standing in its way.
     */
    public function test_a_declined_cybersource_payment_activates_nothing(): void
    {
        Http::fake([
            'apitest.cybersource.com/*' => Http::response(['status' => 'DECLINED'], 201),
        ]);

        [$merchant, $plan] = $this->purchase();

        $this->assertNull(
            DB::table('plan_orders')->where('plan_id', $plan->id)->first(),
            'A DECLINED CyberSource payment recorded a plan order.'
        );

        $this->assertNotSame(
            (string) $plan->id,
            (string) $merchant->fresh()->plan_id,
            'A DECLINED CyberSource payment activated the plan. This is the verification that '
            . 'keeps this provider off the fail-closed map; without it the handler activates on '
            . 'caller-supplied data.'
        );
    }
}
