#!/usr/bin/env python3
"""
GAP-149 / `P05-01` -- the mutation group for the six entitlement states on the finance screen.

Four mutations, each the smallest change that should make one case fail, and each named for the
sentence in the gap's next action it is evidence for:

  1. DROP A STATE FROM THE VALIDATOR  -- `EntitlementStateLabel::SELECTABLE` loses `unpaid`. The
     validator, the `<option>` list and the badge all read that one constant, so this is the single
     edit an owner answer to `D-N43` would make; it must not be possible to make it unnoticed.
  2. DROP A STATE FROM THE VIEW        -- the per-row `<select>` stops marking the engine-written
     state `selected disabled`, which is exactly the defect P5-1 and P5-R4 fixed: the browser then
     renders the FIRST option and tells the merchant a cancelled row is "Not Received".
  3. BREAK ONE LABEL                   -- `cancelled` is relabelled to a phrase with no Arabic, so
     the screen silently falls back to English for an Arabic merchant. This is the mutation the
     old file-reading assertion could not catch, which is why it now goes through the translator.
  4. REMOVE THE PRODUCTION FIX         -- the validator stops declaring its attribute name, so the
     Arabic refusal carries the raw snake-cased column name again.

Run: python3 tests/Support/bin/mutate-gap149.py
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from mutate import Mutation, run  # noqa: E402

BILINGUAL = 'tests/Feature/Commerce/EntitlementStateBilingualScreenTest.php'
LABEL_UNIT = 'tests/Unit/Finance/EntitlementStateLabelTest.php'

MUTATIONS = [
    Mutation(
        name='1. a state dropped from the validator (SELECTABLE loses `unpaid`)',
        path='app/Support/Finance/EntitlementStateLabel.php',
        old="private const SELECTABLE = ['paid', 'unpaid'];",
        new="private const SELECTABLE = ['paid'];",
        test_file=BILINGUAL,
        filter='test_both_selectable_states_are_offered_in_arabic_and_neither_is_preselected_on_a_reversed_row',
    ),
    Mutation(
        name='2. a state dropped from the view (no selected+disabled option)',
        path='resources/views/finance/index.blade.php',
        old='<option value="{{ $financial->payment_status }}" selected disabled>',
        new='<option value="{{ $financial->payment_status }}">',
        test_file=BILINGUAL,
        filter='test_an_engine_written_row_renders_its_state_in_arabic_on_the_finance_screen',
    ),
    Mutation(
        name='3. one label broken (`Cancelled` -> a phrase with no Arabic)',
        path='app/Support/Finance/EntitlementStateLabel.php',
        old="'cancelled' => ['warning', 'Cancelled'],",
        new="'cancelled' => ['warning', 'Voided'],",
        test_file=LABEL_UNIT,
        filter='test_every_phrase_resolves_in_arabic_through_the_translator',
    ),
    Mutation(
        name='4. the validator stops naming its field (the production fix removed)',
        path='app/Http/Controllers/StoreFinanceController.php',
        old="            'payment_status' => __('Payment Status'),\n        ]);",
        new='        ]);',
        test_file=BILINGUAL,
        filter='test_the_refusal_names_the_field_in_the_merchants_own_language',
    ),
]

if __name__ == '__main__':
    sys.exit(run(MUTATIONS))
