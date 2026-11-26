{{--
    PHASE 06 CTRL-003 — THE OPERATOR'S MAINTENANCE ANNOUNCEMENTS.

    `@extends` + `@section`, NEVER the `<x-*-layout>` slot shape. `layouts/app.blade.php` is the
    vendored WorkDo layout and contains zero `$slot` references, so a view written in the component
    shape renders perfect chrome, HTTP 200 and an EMPTY content area — measured, in all four
    language/theme combinations. tests/Unit/Ui/SlotLayoutViewsRenderTest fails on that shape.

    NOT TAILWIND: `.ss-` classes plus the vendored Bootstrap 5 layout utilities, nothing else.

    BOTH LANGUAGES ARE SHOWN SIDE BY SIDE, UNRESOLVED, and that is the one thing this screen does
    that the merchant's banner does not. `MaintenanceAnnouncements::live()` falls back from the
    reader's language to the other one, so a merchant never sees an empty notice — which means the
    operator cannot tell from the banner that one half is missing. This screen is where they can,
    so it prints `title_en` and `title_ar` as stored and leaves the blank one blank.

    THE STATE IS A WORD, NOT A FLAG. `draft`, `scheduled`, `live` and `ended` are four situations,
    and three of them look identical on a screen that prints only `is_published`: an unpublished
    draft, a published announcement whose window has not opened, and a published one whose window
    has closed all show a merchant nothing. An operator asking "why can't they see it" is told
    which — and the window is printed beside it, which is the other half of the same answer.

    THERE IS NO DELETE, DELIBERATELY. Withdrawing unpublishes and keeps the wording and the dates,
    because the words the platform published and when it published them are the record somebody
    asks for after an incident. That is CTRL-004's shape applied to this surface, and
    `MaintenanceAnnouncementTest` asserts the absence rather than leaving it to be noticed.

    BILINGUAL BY CONSTRUCTION: every string this screen says ITSELF is `__()` and declared in both
    `en.json` and `ar.json`; direction comes from the layout's own `dir`. There is no hard-coded
    direction and no directional margin here.
--}}
@extends('layouts.app')

@section('page-title')
    {{ __('Maintenance announcements') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">
        {{ __('Maintenance announcements') }}
    </li>
@endsection

@section('content')
@php
    $severityLabels = [
        'notice' => __('Notice'),
        'urgent' => __('Urgent'),
    ];

    $stateLabels = [
        'draft' => __('Draft — no merchant sees it'),
        'scheduled' => __('Scheduled — not started yet'),
        'live' => __('Live — merchants see it now'),
        'ended' => __('Ended — its window has closed'),
    ];

    $stateTones = ['draft' => 'neutral', 'scheduled' => 'info', 'live' => 'success', 'ended' => 'neutral'];

    $actionLabels = [
        'defined' => __('Written or edited'),
        'published' => __('Published'),
        'withdrawn' => __('Withdrawn'),
    ];

    /* `datetime-local` wants `Y-m-d\TH:i`; the stored value is a Carbon or null. */
    $forInput = fn ($at) => $at === null ? '' : $at->format('Y-m-d\TH:i');
@endphp

<div class="ss-maintenance-admin">

<div class="row">
    <div class="col-12">
        <x-ss.card :title="__('Maintenance announcements')"
                   :note="__('What the platform tells its merchants, written here rather than in a release. Every announcement reaches every merchant: an announcement is about the platform they share.')">
            <p class="ss-muted">
                {{ __('An announcement is written first and published second, so a half-typed notice cannot reach anybody. A published announcement is shown only inside its window; leave a date empty for no bound at that end.') }}
            </p>

            <p class="ss-muted">
                {{ __('Withdrawing an announcement keeps its wording and its dates, so it can be published again. Nothing here is ever deleted: what the platform said, and when, is the record somebody asks for afterwards.') }}
            </p>
        </x-ss.card>
    </div>
</div>

{{-- ================================ what exists ================================ --}}
<div class="row">
    <div class="col-12">
        <x-ss.card :title="__('Announcements')">
            @if ($announcements === [])
                <p class="ss-muted">{{ __('No announcement has been written yet.') }}</p>
            @else
                <div class="ss-table-wrap">
                    <table class="ss-table">
                        <caption class="ss-visually-hidden">
                            {{ __('Every announcement, in both languages as stored, with whether merchants can see it and when.') }}
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('English') }}</th>
                                <th scope="col">{{ __('Arabic') }}</th>
                                <th scope="col">{{ __('Importance') }}</th>
                                <th scope="col">{{ __('Who can see it') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($announcements as $announcement)
                                <tr>
                                    <th scope="row" lang="en">
                                        @if ($announcement['title_en'] === '')
                                            <span class="ss-muted">{{ __('Not written in English') }}</span>
                                        @else
                                            {{ $announcement['title_en'] }}
                                        @endif
                                        @if ($announcement['body_en'] !== '')
                                            <p class="ss-field__hint ss-maintenance-notice__body">{{ $announcement['body_en'] }}</p>
                                        @endif
                                    </th>
                                    <td lang="ar">
                                        @if ($announcement['title_ar'] === '')
                                            <span class="ss-muted">{{ __('Not written in Arabic') }}</span>
                                        @else
                                            {{ $announcement['title_ar'] }}
                                        @endif
                                        @if ($announcement['body_ar'] !== '')
                                            <p class="ss-field__hint ss-maintenance-notice__body">{{ $announcement['body_ar'] }}</p>
                                        @endif
                                    </td>
                                    <td>
                                        <x-ss.status-badge tone="neutral"
                                                           :label="$severityLabels[$announcement['severity']] ?? $announcement['severity']" />
                                    </td>
                                    <td>
                                        <x-ss.status-badge :tone="$stateTones[$announcement['state']] ?? 'neutral'"
                                                           :label="$stateLabels[$announcement['state']] ?? $announcement['state']" />

                                        <p class="ss-field__hint mb-0">
                                            @if ($announcement['starts_at'] !== null && $announcement['ends_at'] !== null)
                                                {{ __('From :from until :until.', [
                                                    'from' => $announcement['starts_at']->toDateTimeString(),
                                                    'until' => $announcement['ends_at']->toDateTimeString(),
                                                ]) }}
                                            @elseif ($announcement['ends_at'] !== null)
                                                {{ __('Until :until.', ['until' => $announcement['ends_at']->toDateTimeString()]) }}
                                            @elseif ($announcement['starts_at'] !== null)
                                                {{ __('From :from.', ['from' => $announcement['starts_at']->toDateTimeString()]) }}
                                            @else
                                                {{ __('No start or end date, so it is shown for as long as it is published.') }}
                                            @endif
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="4">
                                        {{-- EACH ANNOUNCEMENT'S OWN EDIT FORM, PREFILLED, rather than
                                             a separate edit page. Three fields and two dates do not
                                             need a round trip, and an operator editing a window at
                                             2am should not lose the list they were reading. --}}
                                        <form method="POST" action="{{ route('platform.maintenance.apply') }}">
                                            @csrf
                                            <input type="hidden" name="action" value="save">
                                            <input type="hidden" name="id" value="{{ $announcement['id'] }}">

                                            <div class="d-flex flex-wrap gap-3 align-items-end mb-3">
                                                <div class="ss-field">
                                                    <label class="ss-label" for="t-en-{{ $announcement['id'] }}" lang="en">
                                                        {{ __('Title in English') }}
                                                    </label>
                                                    <input class="ss-input" id="t-en-{{ $announcement['id'] }}" type="text"
                                                           name="title_en" maxlength="160" lang="en"
                                                           value="{{ $announcement['title_en'] }}">
                                                </div>

                                                <div class="ss-field">
                                                    <label class="ss-label" for="t-ar-{{ $announcement['id'] }}" lang="ar">
                                                        {{ __('Title in Arabic') }}
                                                    </label>
                                                    <input class="ss-input" id="t-ar-{{ $announcement['id'] }}" type="text"
                                                           name="title_ar" maxlength="160" lang="ar"
                                                           value="{{ $announcement['title_ar'] }}">
                                                </div>

                                                <div class="ss-field">
                                                    <label class="ss-label" for="sev-{{ $announcement['id'] }}">
                                                        {{ __('Importance') }}
                                                    </label>
                                                    <select class="ss-select" id="sev-{{ $announcement['id'] }}" name="severity">
                                                        @foreach ($severities as $severity)
                                                            <option value="{{ $severity }}"
                                                                    @selected($severity === $announcement['severity'])>
                                                                {{ $severityLabels[$severity] ?? $severity }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                </div>

                                                <div class="ss-field">
                                                    <label class="ss-label" for="from-{{ $announcement['id'] }}">
                                                        {{ __('Shown from') }}
                                                    </label>
                                                    <input class="ss-input" id="from-{{ $announcement['id'] }}"
                                                           type="datetime-local" name="starts_at"
                                                           value="{{ $forInput($announcement['starts_at']) }}">
                                                </div>

                                                <div class="ss-field">
                                                    <label class="ss-label" for="until-{{ $announcement['id'] }}">
                                                        {{ __('Shown until') }}
                                                    </label>
                                                    <input class="ss-input" id="until-{{ $announcement['id'] }}"
                                                           type="datetime-local" name="ends_at"
                                                           value="{{ $forInput($announcement['ends_at']) }}">
                                                </div>
                                            </div>

                                            <div class="ss-field mb-3">
                                                <label class="ss-label" for="b-en-{{ $announcement['id'] }}" lang="en">
                                                    {{ __('Wording in English') }}
                                                </label>
                                                <textarea class="ss-textarea" id="b-en-{{ $announcement['id'] }}"
                                                          name="body_en" rows="3" maxlength="20000"
                                                          lang="en">{{ $announcement['body_en'] }}</textarea>
                                            </div>

                                            <div class="ss-field mb-3">
                                                <label class="ss-label" for="b-ar-{{ $announcement['id'] }}" lang="ar">
                                                    {{ __('Wording in Arabic') }}
                                                </label>
                                                <textarea class="ss-textarea" id="b-ar-{{ $announcement['id'] }}"
                                                          name="body_ar" rows="3" maxlength="20000"
                                                          lang="ar">{{ $announcement['body_ar'] }}</textarea>
                                            </div>

                                            <button type="submit" class="ss-btn ss-btn--secondary ss-btn--sm">
                                                {{ __('Save this announcement') }}
                                            </button>
                                        </form>

                                        {{-- PUBLISH AND WITHDRAW ARE THEIR OWN FORMS, so neither
                                             carries the edit fields. A single form with three submit
                                             buttons would publish whatever was in the boxes at the
                                             time, which is not what "publish" says. --}}
                                        <div class="d-flex flex-wrap gap-3 align-items-end">
                                            @if ($announcement['is_published'])
                                                <form method="POST" action="{{ route('platform.maintenance.apply') }}">
                                                    @csrf
                                                    <input type="hidden" name="action" value="withdraw">
                                                    <input type="hidden" name="id" value="{{ $announcement['id'] }}">
                                                    <button type="submit" class="ss-btn ss-btn--danger-quiet ss-btn--sm">
                                                        {{ __('Withdraw') }}
                                                    </button>
                                                </form>
                                            @else
                                                <form method="POST" action="{{ route('platform.maintenance.apply') }}">
                                                    @csrf
                                                    <input type="hidden" name="action" value="publish">
                                                    <input type="hidden" name="id" value="{{ $announcement['id'] }}">
                                                    <button type="submit" class="ss-btn ss-btn--primary ss-btn--sm">
                                                        {{ __('Publish') }}
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ss.card>
    </div>
</div>
<!-- ss-maintenance-admin-list-end -->

{{-- ================================ write a new one ================================ --}}
<div class="row">
    <div class="col-12">
        <x-ss.card :title="__('Write an announcement')"
                   :note="__('A new announcement is saved as a draft. No merchant sees it until it is published.')">
            <form method="POST" action="{{ route('platform.maintenance.apply') }}">
                @csrf
                <input type="hidden" name="action" value="save">

                <div class="d-flex flex-wrap gap-3 align-items-end mb-3">
                    <div class="ss-field">
                        <label class="ss-label" for="new-title-en" lang="en">{{ __('Title in English') }}</label>
                        <input class="ss-input" id="new-title-en" type="text" name="title_en" maxlength="160" lang="en">
                    </div>

                    <div class="ss-field">
                        <label class="ss-label" for="new-title-ar" lang="ar">{{ __('Title in Arabic') }}</label>
                        <input class="ss-input" id="new-title-ar" type="text" name="title_ar" maxlength="160" lang="ar">
                    </div>

                    <div class="ss-field">
                        <label class="ss-label" for="new-severity">{{ __('Importance') }}</label>
                        <select class="ss-select" id="new-severity" name="severity">
                            @foreach ($severities as $severity)
                                <option value="{{ $severity }}">{{ $severityLabels[$severity] ?? $severity }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="ss-field">
                        <label class="ss-label" for="new-from">{{ __('Shown from') }}</label>
                        <input class="ss-input" id="new-from" type="datetime-local" name="starts_at">
                    </div>

                    <div class="ss-field">
                        <label class="ss-label" for="new-until">{{ __('Shown until') }}</label>
                        <input class="ss-input" id="new-until" type="datetime-local" name="ends_at">
                    </div>
                </div>

                <div class="ss-field mb-3">
                    <label class="ss-label" for="new-body-en" lang="en">{{ __('Wording in English') }}</label>
                    <textarea class="ss-textarea" id="new-body-en" name="body_en" rows="3" maxlength="20000" lang="en"></textarea>
                </div>

                <div class="ss-field mb-3">
                    <label class="ss-label" for="new-body-ar" lang="ar">{{ __('Wording in Arabic') }}</label>
                    <textarea class="ss-textarea" id="new-body-ar" name="body_ar" rows="3" maxlength="20000" lang="ar"></textarea>
                </div>

                <p class="ss-field__hint">
                    {{ __('A title in one language is enough: a merchant whose language is missing is shown the other one rather than nothing. Leave a date empty for no bound at that end.') }}
                </p>

                <button type="submit" class="ss-btn ss-btn--primary">{{ __('Save as a draft') }}</button>
            </form>
        </x-ss.card>
    </div>
</div>

{{-- ================================ the change log, this subject only ================================ --}}
<div class="row">
    <div class="col-12">
        <x-ss.card :title="__('Announcement history')"
                   :note="__('What was written, published and withdrawn, and by whom. These entries are kept and cannot be edited or removed.')">
            @if ($changes->isEmpty())
                <p class="ss-muted">{{ __('Nothing has been changed yet.') }}</p>
            @else
                <div class="ss-table-wrap">
                    <table class="ss-table">
                        <caption class="ss-visually-hidden">
                            {{ __('Every change made to the platform\'s maintenance announcements, newest first.') }}
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('When') }}</th>
                                <th scope="col">{{ __('What') }}</th>
                                <th scope="col">{{ __('By whom') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($changes as $change)
                                <tr>
                                    <td>{{ $change->created_at === null ? '—' : $change->created_at->toDateTimeString() }}</td>
                                    <td>
                                        {{ $actionLabels[$change->action] ?? $change->action }}
                                        <br>
                                        <code class="ss-code">{{ $change->subject_key }}</code>
                                    </td>
                                    <td>
                                        @if ($change->actor_name === null)
                                            <span class="ss-muted">{{ __('Not a signed-in operator') }}</span>
                                        @else
                                            {{ $change->actor_name }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ss.card>
    </div>
</div>

</div>
<!-- ss-maintenance-admin-end -->
@endsection
