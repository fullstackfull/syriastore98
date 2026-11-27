{{--
    PHASE 06 CTRL-003 — WHAT THE PLATFORM IS TELLING ITS MERCHANTS, ON EVERY PANEL PAGE.

    RENDERS ABSOLUTELY NOTHING WHEN THERE IS NOTHING LIVE, and that is the first requirement rather
    than a nicety. This component is included from `layouts/app.blade.php`, so it is on all 334
    merchant screens: an empty wrapper, a margin, a border — anything at all — would be a layout
    change to every one of them, to be re-measured in two themes and two directions, in exchange
    for nothing. `@if ($announcements === []) ... @endif` around the whole body, and
    `MaintenanceAnnouncementTest` asserts the absence by looking for the marker.

    IT CANNOT 500 THE PANEL. `MaintenanceAnnouncements::live()` answers `[]` when the table is
    absent (the migration is approved for development and isolated testing only) and `[]` on any
    read failure, logging rather than swallowing. A banner that can take the whole merchant panel
    down is a worse outcome than a banner that misses an announcement.

    NOT TAILWIND, and `.ss-` classes only: the merchant admin is Blade plus the vendored Bootstrap 5
    WorkDo theme plus `public/css/store-syria-ds.css`; `@vite` appears in zero views and there is no
    `public/build`, so a utility class written here would not paint.

    COLOUR CARRIES NOTHING ALONE. The severity is an `x-ss.status-badge`, whose own docblock records
    that its dot is not decoration (WCAG 1.4.1) — the dot plus the TEXT is the signal. The urgent
    and the quiet announcement differ by a word as well as by a hue.

    NO DIRECTION IS PINNED HERE: no `ml-`/`mr-`/`ps-`/`pe-`, no `text-left`, no `dir`. The layout
    already forces `dir="rtl"` for an Arabic principal and this mirrors with it. `mb-*` is block
    direction and is safe.

    THE WORDS ARE THE OPERATOR'S, SO THEY ARE NOT `__()`. Everything the component says ITSELF is
    `__()` and declared in both language files; the title and body come out of the table in the
    reader's own language, with the other language as a fallback (an Arabic merchant seeing nothing
    at all about tonight's outage is worse than seeing it in English). Both are printed through
    Blade `{{ }}`: the write route drops `xss`, deliberately, so this escaping is the control and
    `MaintenanceAnnouncementTest` proves it rather than assuming it.
--}}
@php
    $announcements = \App\Support\Control\MaintenanceAnnouncements::live();

    $severityLabels = [
        'notice' => __('Notice'),
        'urgent' => __('Urgent'),
    ];

    $severityTones = ['notice' => 'info', 'urgent' => 'warning'];
@endphp

@if ($announcements !== [])
    <section class="ss-maintenance-notice mb-4" aria-label="{{ __('Announcements from Store Syria') }}">
        @foreach ($announcements as $announcement)
            <x-ss.card>
                <h2 class="h6 mb-2">
                    <x-ss.status-badge :tone="$severityTones[$announcement['severity']] ?? 'info'"
                                       :label="$severityLabels[$announcement['severity']] ?? $announcement['severity']" />
                    {{ $announcement['title'] }}
                </h2>

                @if ($announcement['body'] !== '')
                    {{-- `nl2br` IS NOT USED, AND THAT IS NOT AN OVERSIGHT. It emits a `<br>` that
                         Blade would escape, so the merchant would read the tag; emitting it raw
                         would mean printing operator text through `{!! !!}`, which is the one thing
                         this file must not do. `white-space: pre-line` on `.ss-maintenance-notice__body`
                         gives the same result from CSS, where no markup is involved at all. --}}
                    <p class="ss-maintenance-notice__body mb-2">{{ $announcement['body'] }}</p>
                @endif

                @if ($announcement['starts_at'] !== null || $announcement['ends_at'] !== null)
                    <p class="ss-field__hint mb-0">
                        @if ($announcement['starts_at'] !== null && $announcement['ends_at'] !== null)
                            {{ __('From :from until :until.', [
                                'from' => $announcement['starts_at']->toDateTimeString(),
                                'until' => $announcement['ends_at']->toDateTimeString(),
                            ]) }}
                        @elseif ($announcement['ends_at'] !== null)
                            {{ __('Until :until.', ['until' => $announcement['ends_at']->toDateTimeString()]) }}
                        @else
                            {{ __('From :from.', ['from' => $announcement['starts_at']->toDateTimeString()]) }}
                        @endif
                    </p>
                @endif
            </x-ss.card>
        @endforeach
    </section>
    <!-- ss-maintenance-notice-end -->
@endif
