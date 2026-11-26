{{--
    PHASE 06 CTRL-001 / CTRL-002 / CTRL-003 — THE OPERATOR'S FEATURE CONTROL SCREEN.

    `@extends` + `@section`, NEVER the `<x-*-layout>` slot shape. `layouts/app.blade.php` is the
    vendored WorkDo layout and contains zero `$slot` references, so a view written in the component
    shape renders perfect chrome, HTTP 200 and an EMPTY content area — measured, in all four
    language/theme combinations. tests/Unit/Ui/SlotLayoutViewsRenderTest fails on that shape because
    the failure is otherwise invisible.

    NOT TAILWIND. The merchant admin is Blade plus the vendored Bootstrap 5 WorkDo theme plus
    `public/css/store-syria-ds.css`; `@vite` appears in zero views and there is no `public/build`,
    so a utility class written here would simply not paint. `.ss-btn`, `.ss-table`, `.ss-input`,
    `.ss-select` and `.ss-badge` and nothing else — the host's `.btn btn-primary` and an unclassed
    `<code>` are both measured contrast failures in this admin.

    BILINGUAL BY CONSTRUCTION: every string is `__()` and direction comes from the layout's own
    `dir`, which `layouts/app.blade.php` forces to rtl for an `ar` or `he` principal. There is no
    hard-coded direction and no directional margin in this file, so it mirrors with the page. Every
    key is declared in BOTH `resources/lang/en.json` and `resources/lang/ar.json` and
    `FeatureControlMessagesAreDeclaredTest` fails if one is not — `__()` returns its own KEY when
    nothing is declared, so an undeclared sentence reads correctly in English by accident and reads
    IN ENGLISH on the Arabic page.

    NO COLOUR CARRIES MEANING ALONE. The availability of a feature is a `<x-ss.status-badge>`, whose
    dot is explicitly not decoration (WCAG 1.4.1) — the dot plus the TEXT is the signal. The reach
    sentence beside it repeats the same fact in words, so a reader who cannot distinguish the hues
    loses nothing.

    THE IDENTIFIERS ARE PRINTED. A key, a plan id, a store id and a pilot slug are all printed
    verbatim in `.ss-code`, because they are what makes a row checkable by hand and what a developer
    types at a call site. `.ss-code` and not a bare `<code>`: Bootstrap paints an unclassed `<code>`
    at #e83e8c, measured at 3.65:1 in the light admin and 4.05:1 in the dark one — both below 4.5:1,
    and the dark figure failing by LESS is why it lasted.

    THE WRITES ARE POSTs WITH `@csrf`, never links, so a crawled URL or a stale bookmark cannot
    switch a platform feature off.
--}}
@extends('layouts.app')

@section('page-title')
    {{ __('Feature control') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">
        {{ __('Feature control') }}
    </li>
@endsection

@section('content')
@php
    /*
     * THE VOCABULARY MAPS. Two reasons they are here and not in the producer: the producer is
     * asserted to carry no sentences (FeatureControlMessagesAreDeclaredTest declares it SILENT),
     * and a `__()` inside an array literal is readable by that same census, which is what makes
     * "declared in both languages" checkable rather than hoped for.
     *
     * A VALUE THE MAP DOES NOT CARRY FALLS BACK TO THE RAW STRING, never to a blank. Both columns
     * behind these maps are plain `varchar` -- the migrations record why, and production's
     * non-strict sql_mode truncates rather than refuses -- so an unrecognised value is a thing an
     * operator may really have to look at, and an empty cell is the one rendering that hides it.
     */
    $availabilityLabels = [
        'off' => __('Switched off'),
        'limited' => __('Limited'),
        'on' => __('On for every store'),
    ];

    $availabilityTones = ['off' => 'neutral', 'limited' => 'info', 'on' => 'success'];

    $reachSentences = [
        'off' => __('No store has this feature. Its pilot groups, named exceptions and rollout share are kept.'),
        'everyone' => __('Every store has this feature, except any that is explicitly refused.'),
        'targets_only' => __('Only the plans, stores and pilot groups named below have this feature.'),
        'share' => __('A share of stores has this feature, chosen by a fixed rule so the same store always gets the same answer.'),
        'share_and_targets' => __('A share of stores has this feature, plus the plans, stores and pilot groups named below.'),
        'nobody_yet' => __('The release has not started: the share is nil and nothing is named, so no store has this feature yet.'),
    ];

    $axisLabels = [
        'plan' => __('Plan'),
        'store' => __('One store'),
        'pilot_group' => __('Pilot group'),
    ];

    $effectLabels = [
        'allow' => __('Reaches it'),
        'deny' => __('Refused it'),
    ];

    $actionLabels = [
        'defined' => __('Declared'),
        'enabled' => __('Released to every store'),
        'disabled' => __('Switched off'),
        'rollout_changed' => __('Rollout share changed'),
        'target_added' => __('Named exception set'),
        'target_withdrawn' => __('Named exception withdrawn'),
        'member_added' => __('Store added to a pilot group'),
        'member_removed' => __('Store removed from a pilot group'),
    ];
@endphp

<div class="ss-feature-control">

<div class="row">
    <div class="col-12">
        <x-ss.card :title="__('Feature control')"
                   :note="__('Which features reach which stores, how a release is widened, and how one is switched off without losing where it had got to.')">

            <p class="ss-muted">
                {{ __('A feature can be reached by a whole plan, by a single store, or by a pilot group of stores — and, while it is limited, by a share of stores chosen by a fixed rule. Widening that share only ever adds stores: none of the stores that already have the feature lose it.') }}
            </p>

            <p class="ss-muted">
                {{ __('Switching a feature off refuses every store at once, and it is read before any pilot group, named exception or rollout share — so nothing that was left configured can partially undo it. Nothing is deleted, so resuming the feature restores exactly the reach it had.') }}
            </p>

            {{--
                WHAT THIS SCREEN IS NOT, said on the screen rather than only in a document. An
                operator who reads "Feature control" and expects the 70 installed applications here
                would conclude the page is broken. The three existing mechanisms are untouched by
                this page and a census asserts it (`module_is_active()` does not consult this
                control plane), so saying so here is a statement of fact rather than a caveat.
            --}}
            <p class="ss-muted">
                {{ __('This page does not govern the installed applications. An application is switched on for the whole platform under Modules, and a plan grants it to a merchant; both are unchanged. A feature that is not declared here is not affected by this page at all.') }}
            </p>
        </x-ss.card>
    </div>
</div>

{{-- ================================ the features ================================ --}}
<div class="row">
    <div class="col-12">
        <x-ss.card :title="__('Declared features')">
            @if ($features === [])
                <p class="ss-muted">{{ __('No feature has been declared yet. Declare one below.') }}</p>
            @else
                <div class="ss-table-wrap">
                    <table class="ss-table">
                        <caption class="ss-visually-hidden">
                            {{ __('Every declared feature, how far it has been released, and which plans, stores and pilot groups are named on it.') }}
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Feature') }}</th>
                                <th scope="col">{{ __('Availability') }}</th>
                                <th scope="col">{{ __('Who has it') }}</th>
                                <th scope="col">{{ __('Named exceptions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($features as $feature)
                                <tr>
                                    <th scope="row">
                                        {{ $feature['label'] }}
                                        <br>
                                        <code class="ss-code">{{ $feature['key'] }}</code>
                                        @if ($feature['description'] !== null)
                                            <p class="ss-field__hint">{{ $feature['description'] }}</p>
                                        @endif
                                    </th>
                                    <td>
                                        <x-ss.status-badge :tone="$availabilityTones[$feature['state']] ?? 'neutral'"
                                                           :label="$availabilityLabels[$feature['state']] ?? $feature['state']" />

                                        @if ($feature['state'] === 'limited')
                                            <p class="ss-field__hint">
                                                {{ __('Rollout share: :percentage%', ['percentage' => $feature['percentage']]) }}
                                            </p>
                                        @endif

                                        {{-- The RAW stored value, shown only when it is not one the
                                             code recognises. `resolvedState()` reports anything
                                             unexpected as `off`, which is right for the resolver
                                             and would be a confident lie here. --}}
                                        @if ($feature['stored_state'] !== $feature['state'])
                                            <p class="ss-field__hint">
                                                {{ __('The stored availability is not a value this platform recognises, so the feature is refused to every store. Stored value:') }}
                                                <code class="ss-code">{{ $feature['stored_state'] }}</code>
                                            </p>
                                        @endif
                                    </td>
                                    <td>{{ $reachSentences[$feature['reach']] ?? $feature['reach'] }}</td>
                                    <td>
                                        @if ($feature['targets'] === [])
                                            <span class="ss-muted">{{ __('None') }}</span>
                                        @else
                                            <ul class="list-unstyled mb-0">
                                                @foreach ($feature['targets'] as $target)
                                                    <li>
                                                        {{ $axisLabels[$target['type']] ?? $target['type'] }}
                                                        <code class="ss-code">{{ $target['value'] }}</code>
                                                        — {{ $effectLabels[$target['effect']] ?? $target['effect'] }}

                                                        <form method="POST"
                                                              action="{{ route('platform.feature_control.target') }}">
                                                            @csrf
                                                            <input type="hidden" name="action" value="withdraw">
                                                            <input type="hidden" name="key" value="{{ $feature['key'] }}">
                                                            <input type="hidden" name="axis" value="{{ $target['type'] }}">
                                                            <input type="hidden" name="effect" value="{{ $target['effect'] }}">
                                                            <input type="hidden" name="value" value="{{ $target['value'] }}">
                                                            <button type="submit" class="ss-btn ss-btn--secondary ss-btn--sm">
                                                                {{ __('Withdraw') }}
                                                            </button>
                                                        </form>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="4">
                                        {{--
                                            THREE SEPARATE FORMS AND NOT ONE WITH THREE SUBMIT
                                            BUTTONS. A single form would send the rollout field with
                                            "Switch off" too, and a handler that then ignored it
                                            would look like it had accepted a share it discarded.
                                            Each form carries exactly the fields its action uses.
                                        --}}
                                        <div class="d-flex flex-wrap gap-3 align-items-end mb-3">
                                            <form method="POST" action="{{ route('platform.feature_control.state') }}">
                                                @csrf
                                                <input type="hidden" name="action" value="disable">
                                                <input type="hidden" name="key" value="{{ $feature['key'] }}">
                                                <button type="submit" class="ss-btn ss-btn--danger-quiet ss-btn--sm">
                                                    {{ __('Switch off') }}
                                                </button>
                                            </form>

                                            <form method="POST" action="{{ route('platform.feature_control.state') }}">
                                                @csrf
                                                <input type="hidden" name="action" value="release_fully">
                                                <input type="hidden" name="key" value="{{ $feature['key'] }}">
                                                <button type="submit" class="ss-btn ss-btn--secondary ss-btn--sm">
                                                    {{ __('Release to every store') }}
                                                </button>
                                            </form>

                                            <form method="POST" action="{{ route('platform.feature_control.state') }}">
                                                @csrf
                                                <input type="hidden" name="action" value="release">
                                                <input type="hidden" name="key" value="{{ $feature['key'] }}">

                                                <div class="ss-field">
                                                    <label class="ss-label"
                                                           for="share-{{ $loop->index }}">
                                                        {{ __('Rollout share, as a percentage of stores') }}
                                                    </label>
                                                    <input class="ss-input"
                                                           id="share-{{ $loop->index }}"
                                                           type="number"
                                                           name="percentage"
                                                           min="0"
                                                           max="100"
                                                           value="{{ $feature['percentage'] }}">
                                                </div>

                                                <button type="submit" class="ss-btn ss-btn--primary ss-btn--sm">
                                                    {{ __('Set the rollout share') }}
                                                </button>
                                            </form>
                                        </div>

                                        {{-- ================ a named exception ================ --}}
                                        <form method="POST" action="{{ route('platform.feature_control.target') }}">
                                            @csrf
                                            <input type="hidden" name="action" value="add">
                                            <input type="hidden" name="key" value="{{ $feature['key'] }}">

                                            <div class="d-flex flex-wrap gap-3 align-items-end mb-3">
                                                <div class="ss-field">
                                                    <label class="ss-label" for="axis-{{ $loop->index }}">
                                                        {{ __('Reach it by') }}
                                                    </label>
                                                    <select class="ss-select" id="axis-{{ $loop->index }}" name="axis">
                                                        @foreach ($axes as $axis)
                                                            <option value="{{ $axis }}">
                                                                {{ $axisLabels[$axis] ?? $axis }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                </div>

                                                <div class="ss-field">
                                                    <label class="ss-label" for="value-{{ $loop->index }}">
                                                        {{ __('Plan number, store number or pilot group name') }}
                                                    </label>
                                                    <input class="ss-input"
                                                           id="value-{{ $loop->index }}"
                                                           type="text"
                                                           name="value"
                                                           maxlength="64">
                                                </div>

                                                <div class="ss-field">
                                                    <label class="ss-label" for="effect-{{ $loop->index }}">
                                                        {{ __('And then') }}
                                                    </label>
                                                    <select class="ss-select" id="effect-{{ $loop->index }}" name="effect">
                                                        @foreach ($effects as $effect)
                                                            <option value="{{ $effect }}">
                                                                {{ $effectLabels[$effect] ?? $effect }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                </div>

                                                <button type="submit" class="ss-btn ss-btn--secondary ss-btn--sm">
                                                    {{ __('Set the named exception') }}
                                                </button>
                                            </div>

                                            <p class="ss-field__hint">
                                                {{ __('A refusal is read before everything except the off switch, so it holds even while the feature is on for every store.') }}
                                            </p>
                                        </form>
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
{{-- A SECTION MARKER, AND AN HTML COMMENT RATHER THAN A BLADE ONE because a Blade comment never
     reaches the response. A SURVIVING MUTATION put it here: blanking the feature's visible key in
     the table above was invisible to a test that searched the WHOLE page, because the change log
     at the foot of this same page also prints that key in a `.ss-code`. CLAUDE.md's rule, verbatim:
     "Scope the search to the block too" -- an assertion over the whole surface answers "does this
     string exist anywhere". --}}
<!-- ss-feature-control-features-end -->

{{-- ================================ declare a feature ================================ --}}
<div class="row">
    <div class="col-12">
        <x-ss.card :title="__('Declare a feature')"
                   :note="__('A newly declared feature is switched off until it is released, so declaring one changes nothing for any store.')">
            <form method="POST" action="{{ route('platform.feature_control.state') }}">
                @csrf
                <input type="hidden" name="action" value="declare">

                <div class="d-flex flex-wrap gap-3 align-items-end mb-3">
                    <div class="ss-field">
                        {{-- THE DESIGN SYSTEM'S REQUIRED MARKER, AFTER THE LABEL AND NOT INSIDE
                             IT. `RequiredFieldMarkerTest` is the authority: every required control
                             in a merchant view carries the design system's required-marker class,
                             which takes the danger token and re-themes. `aria-hidden`, because what
                             announces the constraint to assistive technology is the control's own
                             attribute -- the asterisk is for people reading the page.

                             THE CLASS NAME IS DELIBERATELY NOT WRITTEN IN THIS COMMENT. That
                             census is a text search over the view, so a docblock quoting the
                             string it greps for would satisfy it with prose while the span was
                             missing -- CLAUDE.md records that happening eight times. --}}
                        <label class="ss-label" for="new-key">{{ __('Key, as the code asks for it') }}</label><span class="ss-label__required" aria-hidden="true">*</span>
                        <input class="ss-input" id="new-key" type="text" name="key" maxlength="64" required>
                        <p class="ss-field__hint">
                            {{ __('Letters, numbers, a dot, a dash or an underscore. This is what a developer types, so it cannot contain a space.') }}
                        </p>
                    </div>

                    <div class="ss-field">
                        <label class="ss-label" for="new-label">{{ __('Name, as this screen shows it') }}</label>
                        <input class="ss-input" id="new-label" type="text" name="label" maxlength="120">
                    </div>

                    <div class="ss-field">
                        <label class="ss-label" for="new-description">{{ __('What it is for') }}</label>
                        <input class="ss-input" id="new-description" type="text" name="description" maxlength="2000">
                    </div>

                    <button type="submit" class="ss-btn ss-btn--primary">{{ __('Declare') }}</button>
                </div>
            </form>
        </x-ss.card>
    </div>
</div>

{{-- ================================ pilot groups ================================ --}}
<div class="row">
    <div class="col-12">
        <x-ss.card :title="__('Pilot groups')"
                   :note="__('A named set of stores that receives a feature before everybody else. A store may be in several groups.')">
            @if ($pilotGroups === [])
                <p class="ss-muted">{{ __('No pilot group has any store in it yet.') }}</p>
            @else
                <div class="ss-table-wrap">
                    <table class="ss-table">
                        <caption class="ss-visually-hidden">
                            {{ __('Each pilot group and the stores in it.') }}
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Pilot group') }}</th>
                                <th scope="col">{{ __('Stores in it') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pilotGroups as $group)
                                <tr>
                                    <th scope="row"><code class="ss-code">{{ $group['group'] }}</code></th>
                                    <td>
                                        <ul class="list-unstyled mb-0">
                                            @foreach ($group['stores'] as $storeId)
                                                <li>
                                                    <code class="ss-code">{{ $storeId }}</code>
                                                    <form method="POST"
                                                          action="{{ route('platform.feature_control.pilot') }}">
                                                        @csrf
                                                        <input type="hidden" name="action" value="remove">
                                                        <input type="hidden" name="group" value="{{ $group['group'] }}">
                                                        <input type="hidden" name="store_id" value="{{ $storeId }}">
                                                        <button type="submit" class="ss-btn ss-btn--secondary ss-btn--sm">
                                                            {{ __('Take out of the group') }}
                                                        </button>
                                                    </form>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <form method="POST" action="{{ route('platform.feature_control.pilot') }}">
                @csrf
                <input type="hidden" name="action" value="add">

                <div class="d-flex flex-wrap gap-3 align-items-end mb-3">
                    <div class="ss-field">
                        <label class="ss-label" for="pilot-group">{{ __('Pilot group') }}</label><span class="ss-label__required" aria-hidden="true">*</span>
                        <input class="ss-input" id="pilot-group" type="text" name="group" maxlength="64" required>
                    </div>

                    <div class="ss-field">
                        <label class="ss-label" for="pilot-store">{{ __('Store number') }}</label><span class="ss-label__required" aria-hidden="true">*</span>
                        <input class="ss-input" id="pilot-store" type="number" name="store_id" min="1" required>
                    </div>

                    <button type="submit" class="ss-btn ss-btn--primary">{{ __('Put a store in the group') }}</button>
                </div>
            </form>
        </x-ss.card>
    </div>
</div>

{{-- ================================ the change log ================================ --}}
<div class="row">
    <div class="col-12">
        <x-ss.card :title="__('Change log')"
                   :note="__('What was switched, when, and by whom. These entries are kept and cannot be edited or removed.')">
            @if ($changes === [])
                <p class="ss-muted">{{ __('Nothing has been changed yet.') }}</p>
            @else
                <div class="ss-table-wrap">
                    <table class="ss-table">
                        <caption class="ss-visually-hidden">
                            {{ __('Every change made to the platform\'s feature control, newest first.') }}
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('When') }}</th>
                                <th scope="col">{{ __('What') }}</th>
                                <th scope="col">{{ __('Change') }}</th>
                                <th scope="col">{{ __('By whom') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($changes as $change)
                                <tr>
                                    <td>
                                        {{-- The stamp is printed as stored. A relative time ("two
                                             hours ago") is the wrong unit on an audit surface: the
                                             question after an incident is which minute, and a
                                             relative string cannot be compared with a log line. --}}
                                        {{ $change['at'] === null ? '—' : $change['at']->toDateTimeString() }}
                                    </td>
                                    <td>
                                        {{ $actionLabels[$change['action']] ?? $change['action'] }}
                                        <br>
                                        <code class="ss-code">{{ $change['subject_key'] }}</code>
                                    </td>
                                    <td>
                                        @if ($change['before'] !== [])
                                            <p class="ss-field__hint">
                                                {{ __('Before') }}: {{ implode(', ', $change['before']) }}
                                            </p>
                                        @endif
                                        @if ($change['after'] !== [])
                                            <p class="ss-field__hint">
                                                {{ __('After') }}: {{ implode(', ', $change['after']) }}
                                            </p>
                                        @endif
                                        @if ($change['note'] !== null)
                                            <p class="ss-field__hint">{{ $change['note'] }}</p>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($change['actor_name'] === null)
                                            <span class="ss-muted">{{ __('Not a signed-in operator') }}</span>
                                        @else
                                            {{ $change['actor_name'] }}
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
<!-- ss-feature-control-end -->
@endsection
