#!/usr/bin/env python3
"""
CTRL-003 limb two: the maintenance announcements' mutation run.

The hard part of this surface is not the CRUD, it is the four situations -- a draft, a schedule
that has not opened, a live window and one that has closed -- three of which show a merchant
nothing. A test suite that cannot tell them apart passes against an implementation that reads only
`is_published`, so most of the list below breaks one of the three invisible cases and checks that
something goes red.

Run from the repository root:  python3 tests/Support/bin/mutate-ctrl003-announcements.py
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from mutate import Mutation, run  # noqa: E402

TEST = 'tests/Feature/Control/MaintenanceAnnouncementTest.php'
LANG = 'tests/Unit/Control/FeatureControlMessagesAreDeclaredTest.php'

RESOLVER = 'app/Support/Control/MaintenanceAnnouncements.php'
MODEL = 'app/Models/PlatformMaintenanceAnnouncement.php'
CONTROLLER = 'app/Http/Controllers/PlatformMaintenanceController.php'
BANNER = 'resources/views/components/ss/maintenance-notice.blade.php'
LAYOUT = 'resources/views/layouts/app.blade.php'
CHANGELOG = 'app/Support/Control/PlatformChangeLog.php'
ROUTES = 'routes/web.php'

MUTATIONS = [
    # ---------------------------------------------------------------------------------------------
    # the four situations
    # ---------------------------------------------------------------------------------------------
    Mutation(
        name='a draft reaches every merchant',
        path=RESOLVER,
        old="""                ->where('is_published', true)""",
        new="""                ->whereNotNull('id')""",
        test_file=TEST,
        filter='test_a_draft_reaches_nobody',
    ),
    Mutation(
        name='the start of the window is ignored, so a schedule means nothing',
        path=RESOLVER,
        old="""                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))""",
        new="""                ->whereNotNull('id')""",
        test_file=TEST,
        filter='test_an_announcement_whose_window_has_not_opened_reaches_nobody',
    ),
    Mutation(
        name='the end of the window is ignored, so last week\'s notice is still up',
        path=RESOLVER,
        old="""                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))""",
        new="""                ->whereNotNull('id')""",
        test_file=TEST,
        filter='test_an_announcement_whose_window_has_closed_reaches_nobody',
    ),
    Mutation(
        name='the window bounds are reversed, so only announcements OUTSIDE it are shown',
        path=RESOLVER,
        old="""                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))""",
        new="""                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '>=', $now))""",
        test_file=TEST,
        filter='test_an_announcement_inside_its_window_reaches_a_merchant',
    ),
    Mutation(
        name='the operator screen cannot tell a draft from a live announcement',
        path=RESOLVER,
        old="""        if (! $row->is_published) {
            return 'draft';
        }""",
        new="""        // mutation: everything reads as live""",
        test_file=TEST,
        filter='test_the_screen_distinguishes_a_draft_a_schedule_a_live_one_and_an_ended_one',
    ),
    Mutation(
        name='the operator screen cannot tell a schedule from a live announcement',
        path=RESOLVER,
        old="""        if ($row->starts_at !== null && $row->starts_at->greaterThan($now)) {
            return 'scheduled';
        }""",
        new="""        // mutation: a schedule reads as live""",
        test_file=TEST,
        filter='test_the_screen_distinguishes_a_draft_a_schedule_a_live_one_and_an_ended_one',
    ),
    Mutation(
        name='the operator screen cannot tell an ended announcement from a live one',
        path=RESOLVER,
        old="""        if ($row->ends_at !== null && $row->ends_at->lessThan($now)) {
            return 'ended';
        }""",
        new="""        // mutation: an ended announcement reads as live""",
        test_file=TEST,
        filter='test_the_screen_distinguishes_a_draft_a_schedule_a_live_one_and_an_ended_one',
    ),

    # ---------------------------------------------------------------------------------------------
    # the language, and the fallback
    # ---------------------------------------------------------------------------------------------
    Mutation(
        name='the fallback is dropped, so a merchant whose language is missing sees nothing',
        path=RESOLVER,
        old="""        $other = $locale === 'ar' ? 'en' : 'ar';

        return trim((string) ($row->getAttribute($field . '_' . $other) ?? ''));""",
        new="""        return '';""",
        test_file=TEST,
        filter='test_a_reader_whose_language_is_missing_is_shown_the_other_one',
    ),
    Mutation(
        name='the resolver always answers in English',
        path=RESOLVER,
        old="""        return str_starts_with($locale, 'ar') ? 'ar' : 'en';""",
        new="""        return 'en';""",
        test_file=TEST,
        filter='test_an_arabic_merchant_reads_the_arabic_wording',
    ),
    Mutation(
        name='a titleless announcement renders an empty banner',
        path=RESOLVER,
        old="""            if ($title === '') {
                continue;   // nothing to show; see the class docblock
            }""",
        new="""            // mutation: a titleless announcement is shown anyway""",
        test_file=TEST,
        filter='test_an_announcement_with_no_title_in_either_language_is_skipped',
    ),
    Mutation(
        name='the severity sort is dropped, so the quiet announcement is on top because it is newer',
        path=RESOLVER,
        old="""        usort(
            $rows,
            fn (PlatformMaintenanceAnnouncement $a, PlatformMaintenanceAnnouncement $b): int
                => self::severityRank($a) <=> self::severityRank($b)
        );""",
        new="""        // mutation: no severity sort, so the database's id DESC is the whole order""",
        test_file=TEST,
        filter='test_an_urgent_announcement_is_shown_above_a_quiet_one',
    ),
    Mutation(
        name='the severity sort reads the column, where `notice` sorts before `urgent`',
        path=RESOLVER,
        old="""        return $row->resolvedSeverity() === PlatformMaintenanceAnnouncement::SEVERITY_URGENT ? 0 : 1;""",
        new="""        return $row->resolvedSeverity() === PlatformMaintenanceAnnouncement::SEVERITY_URGENT ? 1 : 0;""",
        test_file=TEST,
        filter='test_an_urgent_announcement_is_shown_above_a_quiet_one',
    ),
    Mutation(
        name='a truncated severity shouts `urgent` at every merchant',
        path=MODEL,
        old="""        return in_array($severity, self::SEVERITIES, true) ? $severity : self::SEVERITY_NOTICE;""",
        new="""        return in_array($severity, self::SEVERITIES, true) ? $severity : self::SEVERITY_URGENT;""",
        test_file=TEST,
        filter='test_a_severity_the_code_does_not_recognise_reads_as_the_quiet_one',
    ),

    # ---------------------------------------------------------------------------------------------
    # the banner itself
    # ---------------------------------------------------------------------------------------------
    Mutation(
        name='the banner renders its wrapper with nothing to say',
        path=BANNER,
        old="""@if ($announcements !== [])""",
        new="""@if (true)""",
        test_file=TEST,
        filter='test_a_merchant_sees_no_banner_when_nothing_is_live',
    ),
    Mutation(
        name='the banner prints the operator\'s title raw',
        path=BANNER,
        old="""                    {{ $announcement['title'] }}""",
        new="""                    {!! $announcement['title'] !!}""",
        test_file=TEST,
        filter='test_markup_in_an_announcement_is_escaped_in_the_merchant_banner',
    ),
    Mutation(
        name='the layout stops including the banner, so no merchant ever sees one',
        path=LAYOUT,
        old="""            <x-ss.maintenance-notice />""",
        new="""            {{-- mutation: the banner is not included --}}""",
        test_file=TEST,
        filter='test_a_merchant_sees_a_live_announcement',
    ),

    # ---------------------------------------------------------------------------------------------
    # the operator's writes
    # ---------------------------------------------------------------------------------------------
    Mutation(
        name='a newly written announcement is published at once',
        path=RESOLVER,
        old="""            $row = PlatformMaintenanceAnnouncement::create($clean + [
                'created_by' => self::actorId($actor),
            ]);""",
        new="""            $row = PlatformMaintenanceAnnouncement::create($clean + [
                'created_by' => self::actorId($actor),
                'is_published' => true,
            ]);""",
        test_file=TEST,
        filter='test_the_operator_can_write_publish_and_withdraw_an_announcement',
    ),
    Mutation(
        name='withdrawing DELETES the announcement',
        path=RESOLVER,
        old="""        $row->is_published = $published;
        $row->save();""",
        new="""        if (! $published) {
            $row->delete();
        } else {
            $row->is_published = $published;
            $row->save();
        }""",
        test_file=TEST,
        filter='test_the_operator_can_write_publish_and_withdraw_an_announcement',
    ),
    Mutation(
        name='publishing something that is not there reports success',
        path=RESOLVER,
        old="""        if (! $row instanceof PlatformMaintenanceAnnouncement) {
            return false;
        }

        $before = self::snapshot($row);""",
        new="""        if (! $row instanceof PlatformMaintenanceAnnouncement) {
            return true;
        }

        $before = self::snapshot($row);""",
        test_file=TEST,
        filter='test_publishing_an_announcement_that_does_not_exist_says_so',
    ),
    Mutation(
        name='an announcement with no title in either language is accepted',
        path=CONTROLLER,
        old="""            if (trim((string) ($validated['title_en'] ?? '')) === ''
                && trim((string) ($validated['title_ar'] ?? '')) === '') {""",
        new="""            if (false) {""",
        test_file=TEST,
        filter='test_an_announcement_with_no_title_at_all_is_refused_by_the_screen',
    ),
    Mutation(
        name='a window that ends before it starts is accepted',
        path=CONTROLLER,
        old="""            'ends_at' => 'nullable|date|after_or_equal:starts_at',""",
        new="""            'ends_at' => 'nullable|date',""",
        test_file=TEST,
        filter='test_a_window_that_ends_before_it_starts_is_refused',
    ),
    Mutation(
        name='the screen grows a delete action',
        path=CONTROLLER,
        old="""            'action' => 'required|string|in:save,publish,withdraw',""",
        new="""            'action' => 'required|string|in:save,publish,withdraw,delete',""",
        test_file=TEST,
        filter='test_there_is_no_way_to_delete_an_announcement',
    ),

    # ---------------------------------------------------------------------------------------------
    # the `xss` exemption, both halves
    # ---------------------------------------------------------------------------------------------
    Mutation(
        name='`xss` is put back, so the operator\'s prose is silently rewritten',
        path=ROUTES,
        old="""    Route::post('platform/maintenance', [\\App\\Http\\Controllers\\PlatformMaintenanceController::class, 'apply'])
        ->middleware(\\App\\Http\\Middleware\\PlatformOperatorOnly::class)
        ->withoutMiddleware('xss')""",
        new="""    Route::post('platform/maintenance', [\\App\\Http\\Controllers\\PlatformMaintenanceController::class, 'apply'])
        ->middleware(\\App\\Http\\Middleware\\PlatformOperatorOnly::class)""",
        test_file=TEST,
        filter='test_prose_containing_a_stripped_substring_is_published_as_typed',
    ),
    Mutation(
        name='the operator gate is removed from the write route',
        path=ROUTES,
        old="""    Route::post('platform/maintenance', [\\App\\Http\\Controllers\\PlatformMaintenanceController::class, 'apply'])
        ->middleware(\\App\\Http\\Middleware\\PlatformOperatorOnly::class)
        ->withoutMiddleware('xss')""",
        new="""    Route::post('platform/maintenance', [\\App\\Http\\Controllers\\PlatformMaintenanceController::class, 'apply'])
        ->withoutMiddleware('xss')""",
        test_file=TEST,
        filter='test_both_routes_resolve_to_this_controller_and_carry_the_operator_gate',
    ),
    Mutation(
        name='the handler stops checking the principal\'s CLASS',
        path=CONTROLLER,
        old="""        if (! $user instanceof User || ! UserPolicy::isOperator($user)) {
            abort(403);
        }""",
        new="""        if (! \\App\\Policies\\UserPolicy::isOperator($user)) {
            abort(403);
        }""",
        test_file=TEST,
        filter='test_a_customer_typed_super_admin_is_refused_by_the_handler',
    ),

    # ---------------------------------------------------------------------------------------------
    # the record
    # ---------------------------------------------------------------------------------------------
    Mutation(
        name='the announcement history shows every subject\'s changes',
        path=CHANGELOG,
        old="""        return PlatformControlChange::query()
            ->where('subject_type', $subjectType)
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * The platform-wide feed, newest first.""",
        new="""        return PlatformControlChange::query()
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * The platform-wide feed, newest first.""",
        test_file=TEST,
        filter='test_the_announcement_history_does_not_show_feature_flag_changes',
    ),
    Mutation(
        name='publishing and withdrawing share one action, so the feed cannot be read',
        path=RESOLVER,
        old="""        return self::setPublished($id, false, PlatformChangeLog::ACTION_WITHDRAWN, $actor);""",
        new="""        return self::setPublished($id, false, PlatformChangeLog::ACTION_PUBLISHED, $actor);""",
        test_file=TEST,
        filter='test_writing_publishing_and_withdrawing_are_each_on_the_record',
    ),

    # ---------------------------------------------------------------------------------------------
    # bilingual, and the views' own discipline
    # ---------------------------------------------------------------------------------------------
    Mutation(
        name='the banner pins a side, so it is wrong in one of the two languages',
        path=BANNER,
        old="""                <h2 class="h6 mb-2">""",
        new="""                <h2 class="h6 mb-2 ms-3">""",
        test_file=TEST,
        filter='test_neither_view_declares_a_colour_or_pins_a_direction',
    ),
    Mutation(
        name='the banner uses a host colour class the dark admin never measured',
        path=BANNER,
        old="""    <section class="ss-maintenance-notice mb-4" aria-label="{{ __('Announcements from Store Syria') }}">""",
        new="""    <section class="ss-maintenance-notice mb-4 text-danger" aria-label="{{ __('Announcements from Store Syria') }}">""",
        test_file=TEST,
        filter='test_neither_view_declares_a_colour_or_pins_a_direction',
    ),
    Mutation(
        name='an Arabic declaration is removed, so the operator screen renders English',
        path='resources/lang/ar.json',
        old='''    "Announcement history": ''',
        new='''    "Announcement history REMOVED": ''',
        test_file=TEST,
        filter='test_the_operator_screen_renders_in_arabic',
    ),
    Mutation(
        name='an Arabic declaration is removed and the census sees it',
        path='resources/lang/ar.json',
        old='''    "Maintenance announcements": ''',
        new='''    "Maintenance announcements REMOVED": ''',
        test_file=LANG,
        filter='test_every_key_this_surface_prints_is_declared_in_english_and_arabic',
    ),
]

if __name__ == '__main__':
    sys.exit(run(MUTATIONS))
