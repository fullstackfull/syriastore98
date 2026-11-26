#!/usr/bin/env python3
"""
GAP-060 WS5 — THE REVERT-AND-CONFIRM RUN for the settings remainder.

Sixteen instructions and one defect fix. This proves each is noticed, in the three ways an
instruction can be half-present:

  * the ASSOCIATION is dropped  -> aria-describedby removed from the control
  * the HINT ITSELF is dropped  -> the <p>/component removed, leaving a dangling reference
  * the SENTENCE is hollowed    -> the measured claim replaced by a restatement of the label
  * the ARABIC is undeclared    -> the ar.json value replaced by its English text, which is
                                   EXACTLY what an undeclared key renders as. This is the mutation
                                   that matters most here: the commit before this one shipped a
                                   sentence that passed every source assertion while rendering in
                                   English on the Arabic page, and only a language assertion
                                   driven through the real route caught it.

Plus the reverted type fix on the Decimal Format select, which is the one behaviour change in the
slice.

TWO OF THESE NINETEEN SURVIVED ON THE FIRST RUN, and they are recorded here rather than quietly
fixed, because the survivors were the two Arabic ones — the single most important pair in the list.
The language assertion was `assertMatchesRegularExpression('/\\p{Arabic}/u', $text)`, so replacing
the OPENING CLAUSE of an Arabic value with its English text left Arabic letters further along the
sentence and the check could not fail. 17 CAUGHT, 2 SURVIVED.

The fix is `DeferredChoiceGuidanceTest::assertArabicIsNotLeakingEnglish()`, whose threshold is
measured rather than chosen: the longest legitimate run of consecutive Latin-script words across the
sixteen Arabic sentences is ONE token, and the English sentences run 44 to 55. Re-run after that
change: 19 CAUGHT, 0 SURVIVED.

A NON-MUTANT WORTH NAMING, since this harness counts a survivor as a failure: removing the
`-group-help` SUFFIX convention is not mutable here, because the id lives in the view and in the
test's own table, so changing one without the other is a test edit rather than a code mutation.
That convention is instead held by `test_the_instructed_lists_cannot_drift_from_the_census`.
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from mutate import Mutation, run  # noqa: E402

V = 'resources/views/setting/'
T = 'tests/Feature/Merchant/DeferredChoiceGuidanceTest.php'
SELECTS = 'test_each_instructed_settings_select_is_described_by_its_own_instruction'
GROUPS = 'test_each_instructed_switch_group_is_described_on_its_fieldset'
DANGLE = 'test_no_settings_instruction_dangles_or_is_orphaned_on_any_touched_panel'

MUTATIONS = [
    # ---- the one behaviour fix, reverted -------------------------------------------------------
    Mutation(
        'decimal format: revert the string/int comparison fix',
        V + 'currency_setting.blade.php',
        "@selected((string) ($setting['currency_format'] ?? '') === (string) $ssValue)",
        "@selected(($setting['currency_format'] ?? '') === $ssValue)",
        T, 'test_the_decimal_format_select_marks_the_format_the_store_has_saved',
    ),

    # ---- the association, once per file --------------------------------------------------------
    Mutation(
        'tax: checkout_price loses aria-describedby',
        V + 'tax_opt_setting.blade.php',
        '<select class="ss-input" id="checkout_price" name="checkout_price"\n'
        '                            aria-describedby="checkout_price-help">',
        '<select class="ss-input" id="checkout_price" name="checkout_price">',
        T, SELECTS,
    ),
    Mutation(
        'tax: tax_id loses aria-describedby',
        V + 'tax_opt_setting.blade.php',
        "'aria-describedby' => 'tax_id-help',\n",
        '',
        T, SELECTS,
    ),
    Mutation(
        'currency: defult_currancy loses aria-describedby',
        V + 'currency_setting.blade.php',
        'name="defult_currancy" id="defult_currancy"\n'
        '                            aria-describedby="defult_currancy-help"',
        'name="defult_currancy" id="defult_currancy"',
        T, SELECTS,
    ),
    Mutation(
        'currency: thousand_separator loses aria-describedby',
        V + 'currency_setting.blade.php',
        'id="thousand_separator"\n'
        '                            aria-describedby="thousand_separator-help"',
        'id="thousand_separator"',
        T, SELECTS,
    ),
    Mutation(
        'system: default_language loses aria-describedby',
        V + 'system_setting.blade.php',
        'class="ss-input"\n'
        '                                aria-describedby="default_language-help"',
        'class="ss-input"',
        T, SELECTS,
    ),
    Mutation(
        'system: site_date_format loses aria-describedby',
        V + 'system_setting.blade.php',
        'id="site_date_format"\n'
        '                            aria-describedby="site_date_format-help"',
        'id="site_date_format"',
        T, SELECTS,
    ),
    Mutation(
        'recaptcha: version loses aria-describedby',
        V + 'recaptcha_setting.blade.php',
        'name="google_recaptcha_version"\n'
        '                                aria-describedby="google_recaptcha_version-help"',
        'name="google_recaptcha_version"',
        T, SELECTS,
    ),
    Mutation(
        'chatgpt: model loses aria-describedby (operator-only panel)',
        V + 'chatgpt_setting.blade.php',
        'required\n                        aria-describedby="chat_gpt_model-help"',
        'required',
        T, SELECTS,
    ),
    Mutation(
        'email: provider loses aria-describedby',
        V + 'email_setting.blade.php',
        "'aria-describedby' => 'email_setting-help',\n",
        '',
        T, SELECTS,
    ),

    # ---- the hint element itself: a dangling reference ------------------------------------------
    Mutation(
        'system: defult_timezone hint deleted, leaving a dangling reference',
        V + 'system_setting.blade.php',
        "<x-ss.field-help for=\"defult_timezone\">{{ __('Used by your storefront",
        "<x-ss.field-help for=\"defult_timezone_removed\">{{ __('Used by your storefront",
        T, DANGLE,
    ),

    # ---- the sentence hollowed out --------------------------------------------------------------
    Mutation(
        'currency: defult_currancy sentence reduced to its own label',
        V + 'currency_setting.blade.php',
        "{{ __('Saving a different currency converts the money already in your store:",
        "{{ __('Choose the default currency for this store, which is also the one shown:",
        T, SELECTS,
    ),

    # ---- the four groups -----------------------------------------------------------------------
    Mutation(
        'brand: Sidebar Settings fieldset loses aria-describedby',
        V + 'brand_setting.blade.php',
        '<fieldset class="ss-field" aria-describedby="cust_theme_bg-group-help">',
        '<fieldset class="ss-field">',
        T, GROUPS,
    ),
    Mutation(
        'brand: Layout Settings fieldset loses aria-describedby',
        V + 'brand_setting.blade.php',
        '<fieldset class="ss-field" aria-describedby="cust_darklayout-group-help">',
        '<fieldset class="ss-field">',
        T, GROUPS,
    ),
    Mutation(
        'email notify: fieldset loses aria-describedby',
        V + 'email_notify_setting.blade.php',
        '<fieldset class="ss-field" aria-describedby="email_notify-group-help">',
        '<fieldset class="ss-field">',
        T, GROUPS,
    ),
    Mutation(
        'stock: Notifications fieldset loses aria-describedby',
        V + 'stock_setting.blade.php',
        '<fieldset class="ss-field" aria-describedby="stock_notification-group-help">',
        '<fieldset class="ss-field">',
        T, GROUPS,
    ),
    Mutation(
        'stock: the measured "refuses to save" claim is softened away',
        V + 'stock_setting.blade.php',
        "{{ __('At least one box must be ticked or the whole panel refuses to save,",
        "{{ __('Tick the notifications you would like to receive for this store,",
        T, GROUPS,
    ),

    # ---- a quoted label that the control does not have -----------------------------------------
    # THE EXACT HISTORICAL DEFECT. The previous slice's draft told the merchant to set a paired
    # control to "Inclusive", which is not what the radio says. This reproduces that shape against
    # the real panel: "Inclusive Tax" is not an option label anywhere on tax_opt_setting.
    Mutation(
        'tax: checkout_price quotes a label the control does not have ("Inclusive Tax")',
        V + 'tax_opt_setting.blade.php',
        'inclusive stores Including Tax both here and in Display prices in the shop',
        'inclusive stores Inclusive Tax both here and in Display prices in the shop',
        T, 'test_every_label_an_instruction_quotes_is_a_label_that_really_exists',
    ),
    # And the same in the other language, which the English check cannot see.
    Mutation(
        'ar.json: the stock instruction names a Twilio toggle that is not on that panel',
        'resources/lang/ar.json',
        'عندما يكون تفعيل Twilio مشغّلاً في لوحة إعدادات Twilio',
        'عندما يكون تشغيل Twilio مشغّلاً في لوحة إعدادات Twilio',
        T, 'test_every_label_an_instruction_quotes_is_a_label_that_really_exists',
    ),

    # ---- the Arabic ----------------------------------------------------------------------------
    Mutation(
        'ar.json: the stock group instruction renders as its English self',
        'resources/lang/ar.json',
        'يجب تحديد مربع واحد على الأقل وإلا رفضت اللوحة بأكملها الحفظ',
        'At least one box must be ticked or the whole panel refuses to save',
        T, GROUPS,
    ),
    Mutation(
        'ar.json: the currency conversion instruction renders as its English self',
        'resources/lang/ar.json',
        'حفظ عملة مختلفة يحوّل الأموال الموجودة في متجرك فعلاً',
        'Saving a different currency converts the money already in your store',
        T, SELECTS,
    ),
]

if __name__ == '__main__':
    print(__doc__)
    sys.exit(run(MUTATIONS))
