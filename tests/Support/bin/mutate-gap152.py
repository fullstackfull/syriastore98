#!/usr/bin/env python3
"""
GAP-152 / P05-04 — MUTATIONS FOR THE CAVEAT CARRIER.

Sixteen one-change mutations, each applied alone, run, and reverted. They attack the four claims
this slice makes, because a gate that cannot fail is not a gate:

  1. the carrier's hint is DERIVED from its caveat list (2, 3, 4, 5, 17)
  2. a caveat cannot be answered with silence, nor claimed for a figure that does not carry it
     (6, 7)
  3. every surface -- three screens AND the export file -- states every caveat the figure carries
     (8, 9, 10, 11, 12, 15)
  4. the sentences are resolved through the translator, so an Arabic merchant reads Arabic
     (13, 14)

ONE MUTATION IS DELIBERATELY A PREMISE ATTACK (1). Removing `EXCLUDES_PAYMENT_FEES` from
`StoreRevenueMargin` leaves every "the screen states each caveat" loop PASSING -- correctly, since
there is then no caveat to state -- so the thing that must notice is the premise test. If it did
not, the whole gate could be silently emptied by a change in the metric layer.

THE TRANSLATION MUTATIONS DO NOT TOUCH `resources/lang/`. Another workstream owns those two files
in this batch, and a concurrent append would be lost by a revert. (13) breaks the RESOLUTION
instead -- `message()` stops calling `__()`, which is exactly what an Arabic merchant would
experience -- and (14) changes the English sentence, which is what a missing translation looks like
to the ratchet. Both are the same property from the two sides.

Run: php tests/.. no -- `python3 tests/Support/bin/mutate-gap152.py` from the repository root.
"""

from __future__ import annotations

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from mutate import Mutation, run  # noqa: E402

CARRIER = 'app/Support/Metrics/CaveatedFigure.php'
LEDGER = 'app/Support/Metrics/FinanceLedgerFigures.php'
MARGIN = 'app/Support/Metrics/StoreRevenueMargin.php'
RESULT = 'app/Support/Metrics/MetricResult.php'
VOCAB = 'app/Support/Metrics/MetricCaveat.php'
CSV = 'app/Support/Metrics/FigureCsv.php'
FINANCE_CONTROLLER = 'app/Http/Controllers/StoreFinanceController.php'
FINANCE_VIEW = 'resources/views/finance/index.blade.php'
COMPONENT = 'resources/views/components/ss/figure.blade.php'

SURFACES = 'tests/Feature/Metrics/CaveatCarrierReachesEverySurfaceTest.php'
UNIT = 'tests/Unit/Metrics/CaveatedFigureTest.php'
COMPONENT_TEST = 'tests/Unit/Ui/CaveatedFigureRendersItsCaveatsTest.php'
EMPTY_CURRENCY = 'tests/Unit/Metrics/MetricResultWithoutEmptyCurrenciesTest.php'
TRANSLATED = 'tests/Unit/Localisation/MetricCaveatMessagesAreTranslatedTest.php'

MUTATIONS = [
    Mutation(
        '1. the metric stops attaching the fee caveat (premise attack)',
        MARGIN,
        '$caveats = [MetricCaveat::EXCLUDES_SHIPPING_AND_TAX, MetricCaveat::EXCLUDES_PAYMENT_FEES];',
        '$caveats = [MetricCaveat::EXCLUDES_SHIPPING_AND_TAX];',
        SURFACES,
        'test_the_premise_that_the_profit_figure_carries_a_caveat_at_all',
    ),
    Mutation(
        '2. the hint drops the caveat sentences (unit)',
        CARRIER,
        '$sentences = array_merge($this->meaning, $this->caveatSentences());',
        '$sentences = $this->meaning;',
        UNIT,
        'test_the_hint_states_every_caveat_the_metric_attached',
    ),
    Mutation(
        '3. the hint drops the caveat sentences (/finance)',
        CARRIER,
        '$sentences = array_merge($this->meaning, $this->caveatSentences());',
        '$sentences = $this->meaning;',
        SURFACES,
        'test_every_caveat_is_stated_on_the_finance_screen',
    ),
    Mutation(
        '4. the hint drops the caveat sentences (the export file)',
        CARRIER,
        '$sentences = array_merge($this->meaning, $this->caveatSentences());',
        '$sentences = $this->meaning;',
        SURFACES,
        'test_every_caveat_travels_into_the_analytics_export_on_the_figure_s_own_row',
    ),
    Mutation(
        '5. caveatSentences() answers nothing at all',
        CARRIER,
        """        return array_map(
            fn (string $caveat): string => $this->statedAs[$caveat] ?? MetricCaveat::message($caveat),
            $this->caveats
        );""",
        '        return [];',
        COMPONENT_TEST,
        'test_every_caveat_the_figure_carries_is_rendered_in_the_visible_hint',
    ),
    Mutation(
        '6. a caveat may be answered with silence',
        CARRIER,
        "            if (trim((string) $sentence) === '') {",
        '            if (false) {',
        UNIT,
        'test_a_surface_cannot_answer_a_caveat_with_silence',
    ),
    Mutation(
        '7. a surface may state a caveat the figure does not carry',
        CARRIER,
        '            if (! in_array($caveat, $caveats, true)) {',
        '            if (false) {',
        UNIT,
        'test_a_surface_cannot_state_a_caveat_the_figure_does_not_carry',
    ),
    Mutation(
        '8. the ledger tile builds its figure without the result\'s caveats',
        LEDGER,
        'return CaveatedFigure::from($result, MoneyText::amount($amount, $currency), $period, $notes, $statedAs);',
        'return CaveatedFigure::of(MoneyText::amount($amount, $currency), $period, [], $notes);',
        SURFACES,
        'test_every_caveat_is_stated_on_the_finance_screen',
    ),
    Mutation(
        '9. the component renders the figure with no hint',
        COMPONENT,
        '           :hint="$figure->hint()"\n',
        '',
        COMPONENT_TEST,
        'test_every_caveat_the_figure_carries_is_rendered_in_the_visible_hint',
    ),
    Mutation(
        '10. the /finance screen bypasses the carrier and prints the value alone',
        FINANCE_VIEW,
        """            <x-ss.figure :label="__('Estimated gross profit')" :figure="$ledgerFigures['profit']" />""",
        """            <x-ss.stat :label="__('Estimated gross profit')"
                   :value="$ledgerFigures['profit']['value']"
                   :period="$ledgerFigures['profit']['period']" />""",
        SURFACES,
        'test_every_caveat_is_stated_on_the_finance_screen',
    ),
    Mutation(
        '11. the export writes an empty disclosure column',
        CSV,
        "                self::plain((string) ($figure['hint'] ?? '')),",
        "                '',",
        SURFACES,
        'test_every_caveat_travels_into_the_analytics_export_on_the_figure_s_own_row',
    ),
    Mutation(
        '12. the inventory figures reach the view with no caveat list',
        FINANCE_CONTROLLER,
        '            $inventory->caveats(),',
        '            [],',
        SURFACES,
        'test_every_caveat_the_inventory_valuation_attaches_is_stated_on_the_finance_screen',
    ),
    Mutation(
        '13. the caveat sentence stops going through the translator',
        VOCAB,
        'return __(self::MESSAGES[$caveat]);',
        'return self::MESSAGES[$caveat];',
        COMPONENT_TEST,
        'test_the_caveats_render_in_arabic_on_an_arabic_page',
    ),
    Mutation(
        '14. the English sentence changes, so its translation no longer resolves',
        VOCAB,
        "self::EXCLUDES_PAYMENT_FEES => 'Payment-processing fees are not subtracted",
        "self::EXCLUDES_PAYMENT_FEES => 'Gateway fees are not subtracted",
        TRANSLATED,
        'test_every_caveat_message_exists_in_arabic',
    ),
    Mutation(
        '15. a caveat the surface answers is ALSO printed in the vocabulary\'s words',
        CARRIER,
        'fn (string $caveat): string => $this->statedAs[$caveat] ?? MetricCaveat::message($caveat),',
        'fn (string $caveat): string => MetricCaveat::message($caveat),',
        SURFACES,
        'test_a_caveat_the_surface_answers_itself_is_not_also_printed_generically',
    ),
    Mutation(
        '16. an empty currency bucket keeps the mixed-currency caveat it no longer earns',
        RESULT,
        """        if (count($kept) <= 1) {
            $caveats = array_values(array_filter(
                $caveats,
                static fn (string $c): bool => $c !== MetricCaveat::MIXED_CURRENCY
            ));
        }""",
        '        // mutation: the narrowing no longer withdraws the caveat',
        EMPTY_CURRENCY,
        'test_a_currency_that_rounds_to_zero_is_dropped_and_the_figure_is_no_longer_mixed',
    ),
    Mutation(
        '17. `from()` takes no caveats off the result',
        CARRIER,
        "return self::build($value, $period, $result->caveats(), $meaning, $statedAs, $result->isAuthoritative());",
        "return self::build($value, $period, [], $meaning, $statedAs, $result->isAuthoritative());",
        SURFACES,
        'test_every_caveat_is_stated_on_the_analytics_screen',
    ),
]

if __name__ == '__main__':
    sys.exit(run(MUTATIONS))
