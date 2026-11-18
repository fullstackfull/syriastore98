<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * THE SHARED FIXTURE MUST NOT BE ABLE TO SPELL THE DEFECT A TEST LOOKS FOR.
 *
 * Whole-page assertions that the epoch (`1970`) is absent -- OrderGridCardRendersTheDateTest -- ran against pages that print the fixture's order
 * reference and store name, both built from `uniqid()`, and one full-suite run failed because the reference read `ORD-6a0c1970...`. The fix is in
 * `SecurityTestCase::uniqueFixtureRef()`.
 *
 * These tests are DETERMINISTIC on purpose. A first version drew 20,000 real `uniqid()` values and asserted none contained the epoch; with the
 * fix reverted it still passed, because the values of a tight loop sweep the clock too evenly to hit a 1-in-3,000 pattern reliably -- a check
 * that could not disagree. So the candidate source is overridden here, handed a reference that spells the epoch, and the fixture is asked what it
 * did with it. Each test fails if its own link in the chain (the loop, the order fixture, the store fixture) is reverted.
 */
class SecurityTestCaseFixtureTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @var list<string> candidates served before falling back to the real source */
    private array $candidates = [];

    private int $drawn = 0;

    protected function nextFixtureRefCandidate(): string
    {
        if ($this->candidates === []) {
            return parent::nextFixtureRefCandidate();
        }

        $this->drawn++;

        return array_shift($this->candidates);
    }

    public function test_a_candidate_that_spells_the_epoch_is_refused_and_the_next_one_is_used(): void
    {
        $this->candidates = ['6a0c1970abcde', '6a0c1971abcde'];

        $this->assertSame('6a0c1971abcde', $this->uniqueFixtureRef());
        $this->assertSame(2, $this->drawn, 'the epoch candidate must have been drawn AND refused, not skipped over');
    }

    public function test_every_epoch_candidate_in_a_row_is_refused_not_just_the_first(): void
    {
        $this->candidates = ['1970000000000', 'ab1970cdef012', 'zzzz1970zzzz1', '6a0c1971abcde'];

        $this->assertSame('6a0c1971abcde', $this->uniqueFixtureRef());
        $this->assertSame(4, $this->drawn);
    }

    public function test_a_candidate_without_the_epoch_is_used_as_it_is(): void
    {
        $this->candidates = ['6a0c1971abcde'];

        $this->assertSame('6a0c1971abcde', $this->uniqueFixtureRef());
        $this->assertSame(1, $this->drawn, 'a good candidate must not be thrown away');
    }

    public function test_the_default_order_reference_goes_through_the_refusal(): void
    {
        [, $store] = $this->makeTenant();

        $this->candidates = ['6a0c1970abcde', '6a0c1971abcde'];
        $order = $this->makeOrder($store);

        $this->assertSame('ORD-6a0c1971abcde', $order->product_order_id);
    }

    public function test_an_order_reference_a_test_supplies_is_left_alone(): void
    {
        [, $store] = $this->makeTenant();

        $order = $this->makeOrder($store, ['product_order_id' => 'ORD-NODATE-1']);

        $this->assertSame('ORD-NODATE-1', $order->product_order_id);
    }

    public function test_the_store_name_email_and_slug_all_go_through_the_refusal(): void
    {
        $merchant = $this->makeMerchant();

        // name, email and slug are each drawn once, in that order; the first draw is the epoch and is refused.
        $this->candidates = ['6a0c1970abcde', 'nnnnn11111111', 'eeeee22222222', 'sssss33333333'];
        $store = $this->makeStore($merchant);

        $this->assertSame('Store '.$merchant->id.'-nnnnn11111111', $store->name);
        $this->assertSame('storeeeeee22222222@example.test', $store->email);
        $this->assertSame('store-'.$merchant->id.'-sssss33333333', $store->slug);
        $this->assertSame(4, $this->drawn);

        foreach ([$store->name, $store->email, $store->slug] as $printed) {
            $this->assertStringNotContainsString('1970', $printed);
        }
    }
}
