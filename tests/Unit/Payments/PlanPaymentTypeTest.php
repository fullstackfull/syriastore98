<?php

namespace Tests\Unit\Payments;

use App\Payments\PlanPaymentType;
use Tests\TestCase;

/**
 * F153 — a plan payment type is recognised whatever language it was written in.
 *
 * `BankTransferController:103` stores `__('Bank Transfer')`, so the column holds the BUYER'S
 * locale. Sixteen of this application's language files translate that string, which means English
 * was the only language the old literal comparison worked in.
 */
class PlanPaymentTypeTest extends TestCase
{
    /**
     * Every pinned spelling resolves. Driven from the constant itself so a spelling added later
     * is covered without editing this test -- the point is that NOTHING in the pinned set is
     * unreachable, not that these particular fifteen are.
     */
    public function test_every_pinned_spelling_resolves_to_its_canonical_type(): void
    {
        $spellings = PlanPaymentType::spellings();

        $this->assertNotEmpty($spellings, 'the spelling table is empty, so every case below would pass vacuously');

        foreach ($spellings as $canonical => $list) {
            $this->assertNotEmpty($list, $canonical . ' has no spellings');

            foreach ($list as $spelling) {
                $this->assertSame(
                    $canonical,
                    PlanPaymentType::canonical($spelling),
                    sprintf('"%s" did not resolve to %s, so a plan order written in that language is unrecognised.', $spelling, $canonical)
                );
            }
        }
    }

    /** The exact value that broke the approve control, named so a regression reads clearly. */
    public function test_the_arabic_bank_transfer_is_a_bank_transfer(): void
    {
        $this->assertTrue(
            PlanPaymentType::is('تحويل مصرفي', PlanPaymentType::BANK_TRANSFER),
            'The Arabic spelling of Bank Transfer is not recognised, so the platform operator\'s '
            . 'Payment Status control does not render and the order cannot be approved.'
        );
    }

    public function test_english_still_resolves(): void
    {
        $this->assertTrue(PlanPaymentType::is('Bank Transfer', PlanPaymentType::BANK_TRANSFER));
        $this->assertTrue(PlanPaymentType::is('Manually', PlanPaymentType::MANUALLY));
    }

    /**
     * FOLDING IS CASE AND WHITESPACE ONLY. The column is free text written by four different
     * controllers, so `bank transfer` and `  Bank  Transfer ` must match -- but nothing beyond
     * that, because a near-match on a payment type is a worse answer than no match.
     */
    public function test_matching_folds_case_and_whitespace_but_nothing_more(): void
    {
        $this->assertTrue(PlanPaymentType::is('  bank   transfer  ', PlanPaymentType::BANK_TRANSFER));
        $this->assertTrue(PlanPaymentType::is('BANK TRANSFER', PlanPaymentType::BANK_TRANSFER));

        $this->assertFalse(PlanPaymentType::is('Banktransfer', PlanPaymentType::BANK_TRANSFER));
        $this->assertFalse(PlanPaymentType::is('Bank Transfers', PlanPaymentType::BANK_TRANSFER));
    }

    public function test_an_unrecognised_or_empty_value_resolves_to_null(): void
    {
        $this->assertNull(PlanPaymentType::canonical(null));
        $this->assertNull(PlanPaymentType::canonical(''));
        $this->assertNull(PlanPaymentType::canonical('   '));
        $this->assertNull(PlanPaymentType::canonical('Stripe'));

        // And an unrecognised value is not silently SOME type.
        $this->assertFalse(PlanPaymentType::is('Stripe', PlanPaymentType::BANK_TRANSFER));
        $this->assertFalse(PlanPaymentType::is('Stripe', PlanPaymentType::MANUALLY));
    }

    public function test_the_two_types_do_not_collide(): void
    {
        $this->assertFalse(PlanPaymentType::is('Bank Transfer', PlanPaymentType::MANUALLY));
        $this->assertFalse(PlanPaymentType::is('Manually', PlanPaymentType::BANK_TRANSFER));
        $this->assertFalse(PlanPaymentType::is('تحويل مصرفي', PlanPaymentType::MANUALLY));
        $this->assertFalse(PlanPaymentType::is('يدوياً', PlanPaymentType::BANK_TRANSFER));
    }

    /**
     * THE PINNED SET IS AUTHORITATIVE, not the language files.
     *
     * This phase already shipped the opposite: `GuardsPlanActivation` derived a replay guard's
     * lookup from `resources/lang/*.json`, and a presentation edit that removed 32 mistranslated
     * brand names silently narrowed what that money control could see. Here the constant holds
     * the Arabic spelling directly, so the same edit cannot reach it.
     */
    public function test_the_arabic_spelling_is_pinned_rather_than_read_from_the_language_file(): void
    {
        $reflected = (new \ReflectionClass(PlanPaymentType::class))->getConstant('PINNED');

        $this->assertIsArray($reflected);
        $this->assertContains(
            'تحويل مصرفي',
            $reflected[PlanPaymentType::BANK_TRANSFER],
            'The Arabic spelling is not pinned, so editing ar.json would change what the plan '
            . 'approval screen can recognise.'
        );
    }

    /**
     * The live sweep may only WIDEN the set. Asserted structurally rather than by mutating a
     * language file, because a test that rewrites `resources/lang/` leaves the repository dirty
     * when it fails.
     */
    public function test_the_live_sweep_contains_every_pinned_spelling(): void
    {
        $pinned = (new \ReflectionClass(PlanPaymentType::class))->getConstant('PINNED');
        $live = PlanPaymentType::spellings();

        foreach ($pinned as $canonical => $list) {
            foreach ($list as $spelling) {
                $this->assertContains($spelling, $live[$canonical], $spelling . ' was dropped by the live sweep');
            }
        }
    }
}
