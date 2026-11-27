@extends('layouts.app')

@section('page-title')
    {{ __('Announcements from Store Syria') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Announcements from Store Syria') }}</li>
@endsection

@section('content')
    @include('layouts.includes.store-syria-ds')

    {{--
        PHASE 06 SUPPORT-002 -- THE MERCHANT'S DURABLE LIST OF WHAT THE PLATFORM ANNOUNCED.

        WHY THIS EXISTS BESIDE CTRL-003's BANNER. The banner shows what is live, on the page you
        happen to be on, while the window is open. A merchant who was not signed in on Thursday
        night has no other way to learn what the platform said about Thursday night. So this list
        carries the ENDED announcements too -- flagged, never silently mixed in with the live ones --
        and never a draft and never a window that has not opened.

        THE WORDS ARE THE OPERATOR'S, SO THEY ARE NOT `__()`. Everything this view says ITSELF is
        `__()` and declared in both language files; the title and body come out of the table in the
        reader's own language with the other language as a fallback. Both are printed through Blade
        `{{ }}`: CTRL-003's write route drops `xss` deliberately, so this escaping is the control
        and not a convenience.

        `nl2br` IS NOT USED, for the reason CTRL-003's banner records: it emits a `<br>` that Blade
        would escape, and emitting it raw would mean printing operator text through `{!! !!}`.
        `white-space: pre-line` on `.ss-announcement__body` gives the same result from CSS, where no
        markup is involved at all.

        NO DIRECTION IS PINNED HERE: no `ml-`/`mr-`/`ps-`/`pe-`, no `text-left`, no `dir`. The layout
        already forces `dir="rtl"` for an Arabic principal and this mirrors with it. `mb-*` is block
        direction and is safe.

        COLOUR CARRIES NOTHING ALONE. The severity and the live/ended state are both
        `x-ss.status-badge`, whose own contract is that the dot is not decoration (WCAG 1.4.1) -- the
        badge carries its TEXT, so the urgent and the quiet announcement differ by a word as well as
        by a hue, and so does last night's from tonight's.
    --}}

    @php
        $severityLabels = [
            'notice' => __('Notice'),
            'urgent' => __('Urgent'),
        ];

        $severityTones = ['notice' => 'info', 'urgent' => 'warning'];
    @endphp

    <div class="row">
        <div class="col-12">
            <x-ss.card>
                <h2 class="h5 mb-1">{{ __('Announcements from Store Syria') }}</h2>

                <p class="ss-muted mb-0">
                    {{ __('Maintenance windows and platform notices, newest first. Announcements that have ended are kept here so you can read what happened while you were away.') }}
                </p>
            </x-ss.card>
        </div>
    </div>

    @if ($announcements === [])
        <div class="row mt-4">
            <div class="col-12">
                {{-- `speakerphone` AND NOT `bell`: the bell is the NOTIFICATION surface, which
                     carries a tenant's own rows about its own orders. An announcement is the
                     platform talking to every tenant, and the two screens should not look like one
                     feature. Both glyphs were checked against `public/assets/fonts/tabler-icons.min.css`
                     -- `.ti-campaign` does NOT exist in the shipped font and would have rendered an
                     empty box. --}}
                <x-ss.empty icon="speakerphone"
                            :title="__('No announcements yet')"
                            :body="__('When Store Syria announces maintenance or a platform change, it will appear here.')" />
            </div>
        </div>
    @else
        <ul class="ss-announcement-list list-unstyled mt-4 mb-0">
            @foreach ($announcements as $announcement)
                <li class="mb-3">
                    <x-ss.card>
                        <h3 class="h6 mb-2">
                            <x-ss.status-badge :tone="$severityTones[$announcement['severity']] ?? 'info'"
                                               :label="$severityLabels[$announcement['severity']] ?? $announcement['severity']" />

                            {{-- THE LIVE/ENDED BADGE IS THE ONE THING THIS SCREEN ADDS OVER THE
                                 BANNER, so it carries a word rather than a tone alone. --}}
                            @if ($announcement['is_live'])
                                <x-ss.status-badge tone="success" :label="__('Happening now')" />
                            @else
                                <x-ss.status-badge tone="neutral" :label="__('Ended')" />
                            @endif

                            {{ $announcement['title'] }}
                        </h3>

                        @if ($announcement['body'] !== '')
                            <p class="ss-announcement__body mb-2">{{ $announcement['body'] }}</p>
                        @endif

                        @if ($announcement['starts_at'] !== null || $announcement['ends_at'] !== null)
                            <p class="ss-field__hint mb-0">
                                @if ($announcement['starts_at'] !== null && $announcement['ends_at'] !== null)
                                    {{ __('From :from until :until.', [
                                        'from' => $announcement['starts_at'],
                                        'until' => $announcement['ends_at'],
                                    ]) }}
                                @elseif ($announcement['ends_at'] !== null)
                                    {{ __('Until :until.', ['until' => $announcement['ends_at']]) }}
                                @else
                                    {{ __('From :from.', ['from' => $announcement['starts_at']]) }}
                                @endif
                            </p>
                        @endif
                    </x-ss.card>
                </li>
            @endforeach
        </ul>
    @endif
    <!-- ss-announcement-list-end -->
@endsection
