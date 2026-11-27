#!/usr/bin/env python3
"""
`SUPPORT-002` — MUTATION RUN. «مساعدة سياقية وإعلانات للويب وFlutter».

Each mutation below is a one-line change that SHOULD break a named test. A mutation that SURVIVES
means the test it is paired with passes for a reason other than the one it claims.

The mutations are chosen for the properties this slice actually promises, not for coverage:

  THE FOUR-STATE RULE. A merchant must never see a draft and never a window that has not opened,
  and must still see an ended announcement, flagged. Four mutations, one per state boundary.

  THE DOOR. `custom.auth` logs a token's owner into the guard matching its TOKENABLE CLASS, so a
  shopper's and a driver's token both pass it on an `api/admin` route. The mutation gives the
  handler a principal that is NOT derived from the request's own authentication, which is the shape
  of thirty-one measured defects in this repository.

  THE REFUSAL IS NOT A 401. The shipped Flutter app force-logs-out on any 401, so this is a
  user-visible property and not an implementation detail.

  THE ESCAPING. `CTRL-003`'s write routes drop `xss` deliberately, so Blade's escaping is the only
  control on operator prose reaching a merchant's screen.

  THE SEGMENT MATCH. `plan-coupon.index` is the platform OPERATOR's screen and
  `str_starts_with($name, 'plan')` is true of it. The mutation is the prefix match a reasonable
  author would have written.

  THE LANGUAGE FALLBACK, IN BOTH DIRECTIONS, and the flag that reports the substitution.

TWO THINGS THIS FILE CAUGHT IN ITSELF, both worth recording because they are the failure modes the
harness exists for.

MUTATION 5's ANCHOR DID NOT MATCH ON THE FIRST RUN, and the harness refused it rather than reporting
a pass: the anchor was written as `$row->resolvedSeverity()`, which is `CTRL-003`'s MODEL api, not
this class's static one. `mutate.py`'s first guard ("the anchor did not match") is exactly this, and
without it the run would have printed SURVIVED for a mutation that was never applied -- which reads
like a weak test instead of a typo in the harness.

ONE GAP WAS FOUND BY WRITING THIS FILE AND IS NOW CLOSED. Mutation 7 (the page-size cap) SURVIVED
the first run: both the Unit and the Feature case drove a limit above the row count, so they
returned the same number with and without `min()`. `a_caller_asking_for_everything_is_capped_at_a_
hundred` was added, with 105 rows, and both halves of the cap (`feed()`'s argument and `live()`'s
constant) are now driven.

Run: `python3 tests/Support/bin/mutate-support002.py` from the repository root.
"""

from __future__ import annotations

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from mutate import Mutation, run  # noqa: E402

FEED = 'app/Support/Help/MerchantAnnouncements.php'
HELP = 'app/Support/Help/ContextualHelp.php'
API = 'app/Http/Controllers/Api/MerchantHelpController.php'
WEB_ANNOUNCE = 'app/Http/Controllers/MerchantAnnouncementController.php'
WEB_HELP = 'app/Http/Controllers/ContextualHelpController.php'
ANNOUNCE_VIEW = 'resources/views/announcements/index.blade.php'
HELP_VIEW = 'resources/views/help/index.blade.php'
HEADER_LINK = 'resources/views/components/ss/contextual-help-link.blade.php'

FEED_TEST = 'tests/Unit/Support/MerchantAnnouncementFeedTest.php'
HELP_TEST = 'tests/Unit/Support/ContextualHelpTest.php'
API_TEST = 'tests/Feature/Support/MerchantHelpApiTest.php'
SCREEN_TEST = 'tests/Feature/Support/MerchantAnnouncementScreenTest.php'
HELP_SCREEN_TEST = 'tests/Feature/Support/ContextualHelpScreenTest.php'
CONTRACT_TEST = 'tests/Contract/SupportHelpEndpointContractTest.php'

MUTATIONS = [
    # ---------------------------------------------------------------- the four-state rule
    Mutation(
        name='1. a DRAFT reaches the merchant (the publish flag is ignored)',
        path=FEED,
        old="                ->where('is_published', true)",
        new="                ->whereNotNull('id')     // mutated: the publish flag is ignored",
        test_file=FEED_TEST,
        filter='a_draft_never_reaches_the_merchant_on_either_surface',
    ),
    Mutation(
        name='2. a SCHEDULED announcement reaches the merchant early',
        path=FEED,
        old="                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))",
        new="                ->whereNotNull('id')     // mutated: the start bound is ignored",
        test_file=FEED_TEST,
        filter='an_announcement_whose_window_has_not_opened_never_reaches_the_merchant',
    ),
    Mutation(
        name='3. an ENDED announcement stays in the banner feed',
        path=FEED,
        old='        return self::resolve(self::rows(true, self::MAX_LIMIT), $locale);',
        new='        return self::resolve(self::rows(false, self::MAX_LIMIT), $locale);',
        test_file=FEED_TEST,
        filter='an_ended_announcement_leaves_the_banner_and_stays_in_the_archive',
    ),
    Mutation(
        name='4. the ENDED badge is never shown on the screen',
        path=ANNOUNCE_VIEW,
        old="                            @if ($announcement['is_live'])",
        new='                            @if (true)     {{-- mutated --}}',
        test_file=SCREEN_TEST,
        filter='test_an_ended_announcement_is_kept_and_marked_ended',
    ),

    # ---------------------------------------------------------------- ordering and severity
    Mutation(
        name='5. the urgent announcement loses its place at the top',
        path=FEED,
        old='        return self::resolvedSeverity($row) === self::SEVERITY_URGENT ? 0 : 1;',
        new='        return 1;     // mutated: every severity ranks the same',
        test_file=FEED_TEST,
        filter='the_urgent_one_is_first_even_though_it_was_written_first',
    ),
    Mutation(
        name='6. an unrecognised severity shouts at every merchant',
        path=FEED,
        old='        return in_array($severity, self::SEVERITIES, true) ? $severity : self::SEVERITY_NOTICE;',
        new='        return $severity === self::SEVERITY_NOTICE ? $severity : self::SEVERITY_URGENT;',
        test_file=FEED_TEST,
        filter='an_unrecognised_severity_reads_as_the_quieter_one',
    ),

    # ---------------------------------------------------------------- the page-size cap
    Mutation(
        name='7. the caller-supplied page size is trusted',
        path=FEED,
        old='        $limit = max(1, min($limit, self::MAX_LIMIT));',
        new='        $limit = max(1, $limit);     // mutated: the cap is gone',
        test_file=FEED_TEST,
        filter='a_caller_asking_for_everything_is_capped_at_a_hundred',
    ),

    # ---------------------------------------------------------------- language
    Mutation(
        name='8. the language fallback is removed (an Arabic reader sees nothing)',
        path=FEED,
        old="        $other = $locale === 'ar' ? 'en' : 'ar';",
        new='        $other = $locale;     // mutated: no fallback',
        test_file=FEED_TEST,
        filter='an_arabic_reader_whose_half_is_empty_gets_the_english_one',
    ),
    Mutation(
        name='9. an empty announcement renders as a blank row',
        path=FEED,
        old="            if ($title === '') {",
        new='            if (false) {     // mutated: nothing is skipped',
        test_file=FEED_TEST,
        filter='an_announcement_with_no_title_in_either_language_is_skipped_entirely',
    ),
    Mutation(
        name='10. the language substitution is no longer reported',
        path=HELP,
        old="                'is_fallback' => $served !== $asked,",
        new="                'is_fallback' => $served !== $requested,     // mutated: the first draft's bug",
        test_file=HELP_TEST,
        filter='an_unshipped_locale_falls_back_and_says_so',
    ),

    # ---------------------------------------------------------------- the route match
    Mutation(
        name="11. the route match becomes a PREFIX match (claims the operator's plan-coupon screen)",
        path=HELP,
        old='        return self::routeSegments()[$segment] ?? null;',
        new=(
            '        foreach (self::routeSegments() as $prefix => $surface) {\n'
            '            if (str_starts_with($routeName, $prefix)) {\n'
            '                return $surface;     // mutated: a prefix match\n'
            '            }\n'
            '        }\n'
            '\n'
            '        return null;'
        ),
        test_file=HELP_TEST,
        filter='a_route_whose_first_segment_merely_starts_with_a_mapped_one_resolves_to_nothing',
    ),
    Mutation(
        name='12. an article id that names nothing is accepted',
        path=HELP,
        old="            'products/add-a-product',",
        new="            'products/add-a-prodcut',     // mutated: a typo'd id",
        test_file=HELP_TEST,
        filter='every_declared_article_resolves_in_both_languages',
    ),

    # ---------------------------------------------------------------- the door
    Mutation(
        name='13. the API principal is no longer derived from the request (shopper and driver admitted)',
        path=API,
        old="        $user = auth('web')->user();\n\n        return $user instanceof User ? $user : null;",
        new=(
            "        $user = auth('web')->user() ?? User::query()->first();     // mutated: not derived\n\n"
            '        return $user instanceof User ? $user : null;'
        ),
        test_file=API_TEST,
        filter='test_a_customer_token_passes_custom_auth_and_is_still_refused',
    ),
    Mutation(
        name='14. the same mutation, driven with a DRIVER token',
        path=API,
        old="        $user = auth('web')->user();\n\n        return $user instanceof User ? $user : null;",
        new=(
            "        $user = auth('web')->user() ?? User::query()->first();     // mutated: not derived\n\n"
            '        return $user instanceof User ? $user : null;'
        ),
        test_file=API_TEST,
        filter='test_a_driver_token_passes_custom_auth_and_is_still_refused',
    ),
    Mutation(
        name='15. the API refusal becomes a 401 (the shipped app logs the merchant out)',
        path=API,
        old="        return $this->error([], __('You do not have permission to perform this action.'));",
        new="        return $this->error([], __('You do not have permission to perform this action.'), 401);",
        test_file=CONTRACT_TEST,
        filter='test_a_refusal_is_a_200_and_never_a_401',
    ),
    Mutation(
        name='16. the web announcement handler admits a non-User principal',
        path=WEB_ANNOUNCE,
        old='        if (! $merchant instanceof User) {',
        new='        if (false) {     // mutated: the handler gate is gone',
        test_file=SCREEN_TEST,
        filter='test_the_handler_itself_refuses_a_customer',
    ),
    Mutation(
        name='17. the web help handler admits a non-User principal',
        path=WEB_HELP,
        old='        if (! $merchant instanceof User) {',
        new='        if (false) {     // mutated: the handler gate is gone',
        test_file=HELP_SCREEN_TEST,
        filter='test_the_handler_itself_refuses_a_customer',
    ),

    # ---------------------------------------------------------------- escaping and the screens
    Mutation(
        name='18. operator prose is printed raw (the only control on it)',
        path=ANNOUNCE_VIEW,
        old="                            {{ $announcement['title'] }}",
        new="                            {!! $announcement['title'] !!}     {{-- mutated --}}",
        test_file=SCREEN_TEST,
        filter='test_operator_prose_is_escaped_and_not_rendered_as_markup',
    ),
    Mutation(
        name='19. an unknown surface is silently treated as known',
        path=WEB_HELP,
        old="        $known = $surface !== '' && array_key_exists($surface, ContextualHelp::SURFACES);",
        new="        $known = $surface !== '';     // mutated: the drift is hidden",
        test_file=HELP_SCREEN_TEST,
        filter='test_a_surface_this_build_does_not_know_is_said_out_loud',
    ),
    Mutation(
        name='20. a surface loses its heading and renders its raw key at a merchant',
        path=HELP_VIEW,
        old="            'storefront' => __('Your storefront'),",
        new='            {{-- mutated: the heading is gone --}}',
        test_file=HELP_SCREEN_TEST,
        filter='test_every_surface_has_a_heading_on_the_screen',
    ),
    Mutation(
        name='21. the header help control stops resolving the current screen',
        path=HEADER_LINK,
        old='        optional(\\Illuminate\\Support\\Facades\\Request::route())->getName()',
        new='        null     {{-- mutated: no resolution --}}',
        test_file=HELP_SCREEN_TEST,
        filter='test_the_header_help_control_carries_the_surface_of_the_screen_it_is_on',
    ),
]

if __name__ == '__main__':
    print(__doc__)
    sys.exit(run(MUTATIONS))
