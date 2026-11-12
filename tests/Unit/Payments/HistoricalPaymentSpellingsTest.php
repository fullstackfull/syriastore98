<?php

namespace Tests\Unit\Payments;

use App\Http\Controllers\Concerns\GuardsPlanActivation;
use ReflectionClass;
use Tests\TestCase;

/**
 * THE PLAN-ACTIVATION REPLAY GUARD STILL FINDS A ROW WRITTEN UNDER AN OLD SPELLING.
 *
 * `plan_orders.payment_type` holds whatever `__('Paystack')` returned on the day the row was
 * written. That is a fact about the past, and no edit to a language file can change it -- so the
 * set of spellings `GuardsPlanActivation` must search is the set this application has EVER
 * produced, not the set it produces now.
 *
 * The guard used to derive that set by globbing `resources/lang/*.json`, which quietly assumed the
 * two were the same. They stopped being the same when P2.5-SET-03 removed 32 mistranslated brand
 * names from `ar.json` (`Stripe` had been stored as `الشريط`, "the ribbon"). The lookup set shrank
 * to the canonical spelling, a row written by an Arabic-locale buyer became invisible to the
 * guard, and a replayed gateway return could have activated a plan twice.
 *
 * `GuardsPlanActivation::HISTORICAL_SPELLINGS` is the fix, and this test is the ratchet on it.
 * `PlanActivationReplayGuardTest` and `PaystackPlanActivationTest` drive four providers end to
 * end; this one asserts the WIRING holds for every pinned entry, so a future edit to the constant
 * or to the lookup cannot silently stop reaching them.
 */
class HistoricalPaymentSpellingsTest extends TestCase
{
    /** The trait's lookup is protected, so drive it through an anonymous user of the trait. */
    private function spellingsFor(string $canonical): array
    {
        $host = new class {
            use GuardsPlanActivation;

            public function spellings(string $canonical): array
            {
                return $this->planProviderTypeSpellings($canonical);
            }
        };

        return $host->spellings($canonical);
    }

    private function pinned(): array
    {
        $pinned = (new ReflectionClass(GuardsPlanActivation::class))->getConstant('HISTORICAL_SPELLINGS');

        $this->assertIsArray($pinned);
        $this->assertNotEmpty(
            $pinned,
            'HISTORICAL_SPELLINGS is empty, so every assertion below would pass vacuously.'
        );

        return $pinned;
    }

    public function test_every_pinned_historical_spelling_is_reachable_by_the_guard(): void
    {
        $missing = [];

        foreach ($this->pinned() as $canonical => $spellings) {
            $found = $this->spellingsFor($canonical);

            foreach ((array) $spellings as $historical) {
                if (! in_array($historical, $found, true)) {
                    $missing[$canonical][] = $historical;
                }
            }
        }

        $this->assertSame([], $missing, sprintf(
            "The replay guard no longer searches these historical spellings, so a plan order "
            . "written under one is invisible to it and the gateway return can be replayed:\n%s",
            json_encode($missing, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        ));
    }

    public function test_the_canonical_spelling_is_searched_first(): void
    {
        // The ordinary case -- a row written in English -- must cost one lookup, not thirty-six.
        foreach (array_keys($this->pinned()) as $canonical) {
            $found = $this->spellingsFor($canonical);
            $this->assertSame($canonical, $found[0] ?? null);
        }
    }

    public function test_the_guard_searches_no_duplicate_spelling(): void
    {
        // Each spelling costs a query on every gateway return. A duplicate is a wasted one, and
        // duplicates are what happen when a pinned value is also still in a language file.
        foreach (array_keys($this->pinned()) as $canonical) {
            $found = $this->spellingsFor($canonical);
            $this->assertSame(
                $found,
                array_values(array_unique($found)),
                $canonical . ' is searched under a duplicated spelling.'
            );
        }
    }

    public function test_the_arabic_file_no_longer_supplies_the_spellings_that_are_pinned(): void
    {
        /*
         * THE PREMISE OF THE WHOLE CONSTANT. If `ar.json` still held these keys, the locale sweep
         * would find them and the pinning would be untested belt-and-braces -- and the day the key
         * was removed, nobody would know whether the pin worked. This asserts the pins are the
         * ONLY source for these values, which is what makes the test above meaningful.
         */
        $ar = json_decode(file_get_contents(resource_path('lang/ar.json')), true);
        $this->assertIsArray($ar);

        $stillTranslated = [];
        foreach ($this->pinned() as $canonical => $spellings) {
            if (array_key_exists($canonical, $ar) && in_array($ar[$canonical], (array) $spellings, true)) {
                $stillTranslated[$canonical] = $ar[$canonical];
            }
        }

        $this->assertSame([], $stillTranslated, sprintf(
            "These brand names are pinned as HISTORICAL spellings yet ar.json still renders them "
            . "that way, so the mistranslation is still live in the UI:\n%s",
            json_encode($stillTranslated, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        ));
    }
}
