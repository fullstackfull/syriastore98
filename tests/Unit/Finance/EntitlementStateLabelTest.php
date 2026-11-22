<?php

namespace Tests\Unit\Finance;

use App\Support\Database\GateFProductionSchema;
use App\Support\Finance\EntitlementStateLabel;
use App\Support\Api\OrderStatusContract;
use Tests\TestCase;

/**
 * P5-R4 / GAP-149 -- the entitlement-state vocabulary, measured against the production enum.
 *
 * `Tests\TestCase` rather than a bare PHPUnit case because `label()` calls `__()`, which needs a
 * booted container. The sibling FIN-SYM work learned that the hard way on `MoneyText::amount()`.
 */
final class EntitlementStateLabelTest extends TestCase
{
    /**
     * THE PREMISE, AND THE ONLY ASSERTION HERE THAT CANNOT GO STALE. Every other case in this file
     * is a claim about six strings I chose; this one reads the six out of the MEASURED production
     * DDL that `GateFProductionSchema` carries verbatim, so if the column's enum ever gains or
     * loses a value this fails until the class follows. Without it, "six states" is just a number
     * repeated in two files.
     */
    public function test_the_class_knows_exactly_the_states_the_production_column_can_hold(): void
    {
        $ddl = GateFProductionSchema::ddlFor('store_order_financials');

        $this->assertSame(1,
            preg_match("/`payment_status` enum\(([^)]*)\)/", $ddl, $m),
            'PREMISE: the measured DDL no longer declares `payment_status` as an enum, so this '
            . 'file cannot tell which states the column holds.');

        preg_match_all("/'([^']+)'/", $m[1], $values);
        $fromSchema = $values[1];

        sort($fromSchema);
        $fromClass = EntitlementStateLabel::states();
        sort($fromClass);

        $this->assertSame($fromSchema, $fromClass,
            'The merchant-facing state vocabulary and the production column disagree. A state the '
            . 'column can hold but this class cannot name renders as the raw token; a state this '
            . 'class names but the column cannot hold is dead code pretending to be coverage.');
    }

    public function test_only_paid_and_unpaid_may_be_chosen_by_a_merchant(): void
    {
        $this->assertSame(['paid', 'unpaid'], EntitlementStateLabel::selectable());

        foreach (['paid', 'unpaid'] as $state) {
            $this->assertTrue(EntitlementStateLabel::isSelectable($state), $state);
        }

        /*
         * The four the engine owns. `cancelled` and `refunded` are written by
         * OrderEntitlementReversal, so typing one would fabricate a reversal; `pending` and
         * `partially_paid` come from the gateway normaliser, and typing `partially_paid` would
         * claim a partial capture whose AMOUNT the application was never told -- which is exactly
         * why OrderFinancialService leaves `paid_amount` at zero for such a row.
         */
        foreach (['pending', 'partially_paid', 'refunded', 'cancelled'] as $state) {
            $this->assertFalse(EntitlementStateLabel::isSelectable($state), $state);
        }
    }

    public function test_the_two_new_phrases_are_the_admin_api_contracts_own_phrases(): void
    {
        /*
         * One state, one name across surfaces. These are not new English: `OrderStatusContract`
         * already translates the same two words for the admin API, and both already exist in
         * resources/lang/ar.json, so nothing here invented an Arabic string.
         */
        $reflected = new \ReflectionClass(OrderStatusContract::class);
        $phrases = $reflected->getConstant('PHRASES');

        $this->assertIsArray($phrases, 'PREMISE: OrderStatusContract no longer carries PHRASES.');

        foreach (['pending' => 'Pending', 'partially_paid' => 'Partially Paid'] as $state => $phrase) {
            $this->assertSame($phrase, $phrases[$state] ?? null,
                "PREMISE: the admin API's phrase for `{$state}` is not '{$phrase}' any more.");

            $this->assertSame($phrase, EntitlementStateLabel::label($state),
                "the finance screen and the admin API now call `{$state}` different things");
        }
    }

    public function test_both_arabic_phrases_are_already_translated(): void
    {
        $ar = json_decode((string) file_get_contents(base_path('resources/lang/ar.json')), true);

        $this->assertIsArray($ar, 'PREMISE: resources/lang/ar.json did not decode.');

        foreach (EntitlementStateLabel::states() as $state) {
            $english = EntitlementStateLabel::label($state);

            $this->assertArrayHasKey($english, $ar,
                "'{$english}' -- the finance screen's phrase for `{$state}` -- has no Arabic. An "
                . 'Arabic merchant would read the English string on a money column.');

            $this->assertNotSame('', trim((string) $ar[$english]),
                "the Arabic for '{$english}' is blank");
        }
    }

    /** @dataProvider everyState */
    public function test_every_state_has_a_measured_tone_and_a_non_empty_label(string $state): void
    {
        $tone = EntitlementStateLabel::tone($state);

        /*
         * The five tones FinanceStatusBadgeContrastTest measures at WCAG AA in BOTH themes. A tone
         * outside that set would render a pill nothing has contrast-checked, on a money column.
         */
        $this->assertContains($tone, ['success', 'warning', 'danger', 'info', 'neutral'],
            "`{$state}` renders tone `{$tone}`, which is not one of the five measured DS tones");

        $this->assertNotSame('', trim(EntitlementStateLabel::label($state)),
            "`{$state}` renders an empty pill, which "
            . 'FinanceStatusBadgeContrastTest::test_no_rendered_finance_pill_is_empty refuses');
    }

    public static function everyState(): array
    {
        return [
            'paid' => ['paid'],
            'partially_paid' => ['partially_paid'],
            'pending' => ['pending'],
            'refunded' => ['refunded'],
            'cancelled' => ['cancelled'],
            'unpaid' => ['unpaid'],
        ];
    }

    public function test_the_two_states_this_slice_added_no_longer_read_as_money_that_never_arrived(): void
    {
        $notReceived = EntitlementStateLabel::label('unpaid');

        foreach (['pending', 'partially_paid'] as $state) {
            $this->assertNotSame($notReceived, EntitlementStateLabel::label($state),
                "`{$state}` still reads as '{$notReceived}'. For `partially_paid` that is the "
                . 'opposite of what happened: CollectedCustomerPayments counts such a row as '
                . 'money collected the moment paid_amount is above zero.');

            $this->assertNotSame(EntitlementStateLabel::tone('unpaid'), EntitlementStateLabel::tone($state),
                "`{$state}` still renders in the same tone as a row where nothing arrived");
        }
    }

    /**
     * AN UNRECOGNISED VALUE IS SHOWN AS ITSELF. It cannot occur through the enum or through
     * `normalizePaymentStatus()`, so it means something else wrote the row -- and labelling it
     * with one of the six meanings would be a guess printed on a money column.
     */
    public function test_an_unknown_state_is_shown_as_itself_in_a_neutral_tone(): void
    {
        $this->assertFalse(EntitlementStateLabel::isKnown('chargeback'));
        $this->assertSame('chargeback', EntitlementStateLabel::label('chargeback'));
        $this->assertSame('neutral', EntitlementStateLabel::tone('chargeback'));
        $this->assertFalse(EntitlementStateLabel::isSelectable('chargeback'));
    }

    /**
     * AN EMPTY OR NULL VALUE IS THE ONE UNKNOWN THAT READS AS "Not Received", because a blank
     * financial state is "no money recorded" -- and because showing the raw value would render an
     * empty pill, which the contrast suite refuses and which tells a merchant nothing.
     */
    public function test_a_blank_state_falls_back_to_not_received_rather_than_an_empty_pill(): void
    {
        foreach ([null, '', '   '] as $blank) {
            $this->assertSame(EntitlementStateLabel::label('unpaid'), EntitlementStateLabel::label($blank),
                'a blank state does not read as money that has not arrived');

            $this->assertSame('danger', EntitlementStateLabel::tone($blank));
            $this->assertFalse(EntitlementStateLabel::isKnown($blank));
        }
    }

    public function test_surrounding_whitespace_does_not_hide_a_known_state(): void
    {
        $this->assertTrue(EntitlementStateLabel::isKnown(' partially_paid '));
        $this->assertSame('Partially Paid', EntitlementStateLabel::label(' partially_paid '));
        $this->assertSame('warning', EntitlementStateLabel::tone(' partially_paid '));
    }
}
