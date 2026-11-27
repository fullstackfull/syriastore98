{{--
    IAM-003 -- THE SECOND OPERATOR'S APPROVAL QUEUE.

    `@extends` + `@section`, NEVER the `<x-*-layout>` slot shape. `layouts/app.blade.php` is the
    vendored WorkDo layout and contains zero `$slot` references, so a view written in the component
    shape renders perfect chrome, HTTP 200 and an EMPTY content area -- measured, in all four
    language/theme combinations. tests/Unit/Ui/SlotLayoutViewsRenderTest fails on that shape because
    the failure is otherwise invisible.

    BILINGUAL BY CONSTRUCTION: every string is `__()`, and direction comes from the layout's own
    `dir`. No hard-coded ltr/rtl and no directional margin in this file, so the page mirrors for
    Arabic with the layout.

    EVERY VALUE IS PRINTED WITH BLADE'S ESCAPING SHORT ECHO. The payload, the refusal reason and
    the approver's words all originate from a request, and `OrderNote`/`SettingController` are the
    measured precedents for a stored string reaching a sink. This file uses no raw-echo directive
    anywhere and must not gain one -- the spelling is left out of this comment on purpose, because
    CLAUDE.md records eight occasions where a census was satisfied, or broken, by prose about the
    very thing it measures.

    IT NAMES ITS OWN LIMITS. The banner at the top says plainly whether the control is switched on,
    because a queue that is permanently empty because enforcement is off looks identical to one that
    is empty because nobody has asked for anything.
--}}
@extends('layouts.app')

@section('page-title')
    {{ __('Dangerous action approvals') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">
        {{ __('Dangerous action approvals') }}
    </li>
@endsection

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <p class="ss-muted">
            {{ __('A dangerous platform action is requested by one operator and takes effect only when a different operator approves it. Nobody can approve their own request.') }}
        </p>
    </div>
</div>

<div class="row mb-4">
    <div class="col-12">
        @if ($enforced)
            <x-ss.attention :title="__('Two-person approval is ON')">
                <x-ss.attention-item
                    :count="$quorum"
                    :text="__('distinct platform operators are required: the requester, plus that many minus one different approvers.')" />
            </x-ss.attention>
        @else
            <x-ss.card :title="__('Two-person approval is OFF')"
                       :note="__('Governed actions take effect immediately, exactly as they did before this mechanism existed. Nothing below is enforced.')">
                <p class="ss-muted mb-0">
                    {{ __('Switching it on needs a decision the application cannot make for you: at least two platform operator accounts must exist, and the action list below must be confirmed. With one operator account, two-person approval would make every governed action impossible.') }}
                </p>
            </x-ss.card>
        @endif
    </div>
</div>

<div class="row mb-4">
    <div class="col-12">
        <x-ss.card :title="__('Waiting for a second operator')" :flush="true">
            @if ($pending->isEmpty())
                <x-ss.empty icon="shield-check"
                            :title="__('Nothing is waiting')"
                            :body="$enforced
                                ? __('No operator has requested a governed action.')
                                : __('Two-person approval is off, so no request is ever recorded here.')" />
            @else
                <div class="table-responsive">
                    <table class="table ss-table mb-0">
                        <caption class="visually-hidden">
                            {{ __('Dangerous actions requested by one operator and waiting for a different operator to approve') }}
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Request') }}</th>
                                <th scope="col">{{ __('Action') }}</th>
                                <th scope="col">{{ __('Requested by') }}</th>
                                <th scope="col">{{ __('Requested') }}</th>
                                <th scope="col">{{ __('Decision') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pending as $approval)
                                @php
                                    // The viewer's OWN request. The server refuses self-approval
                                    // whatever this says -- TwoPersonApproval::approve() compares
                                    // the ids -- so this is an explanation, never the control.
                                    $isOwn = $viewer && (int) $viewer->id === (int) $approval->requested_by;
                                @endphp
                                <tr>
                                    <th scope="row">#{{ $approval->id }}</th>
                                    <td>
                                        {{ __(\App\Support\Iam\TwoPersonApproval::labelFor($approval->action)) }}
                                        <br />
                                        <small class="ss-muted">
                                            @foreach ((array) $approval->payload as $key => $value)
                                                {{ $key }}: {{ is_scalar($value) ? $value : json_encode($value) }}@if (! $loop->last), @endif
                                            @endforeach
                                        </small>
                                    </td>
                                    <td>{{ $approval->requester?->name ?? __('(user removed)') }}</td>
                                    <td>{{ $approval->created_at }}</td>
                                    <td>
                                        @if ($isOwn)
                                            <span class="ss-muted">
                                                {{ __('You requested this. A different operator must approve it.') }}
                                            </span>
                                        @else
                                            <form method="POST"
                                                  action="{{ route('platform.approvals.approve', $approval->id) }}"
                                                  class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-primary">
                                                    {{ __('Approve and perform') }}
                                                </button>
                                            </form>
                                        @endif
                                        <form method="POST"
                                              action="{{ route('platform.approvals.reject', $approval->id) }}"
                                              class="d-inline">
                                            @csrf
                                            <label class="visually-hidden" for="reason-{{ $approval->id }}">
                                                {{ __('Why are you rejecting this?') }}
                                            </label>
                                            <input type="text" id="reason-{{ $approval->id }}" name="reason"
                                                   maxlength="255" class="form-control form-control-sm d-inline w-auto"
                                                   placeholder="{{ __('Reason (optional)') }}" />
                                            {{-- NAV-004. THE DESIGN SYSTEM'S ROW-LEVEL DESTRUCTIVE
                                                 CONTROL, because the host theme's is not legible.
                                                 Measured in all four combinations: `btn-sm
                                                 btn-outline-danger` paints the host's `#ff3a6e`,
                                                 which is 3.45:1 on the light card and 4.12:1 on
                                                 the dark one against a 4.5:1 label floor -- so it
                                                 failed in BOTH themes, and the light reading is
                                                 the worse of the two, which is the opposite of
                                                 what a dark-mode-first review would expect.

                                                 `.ss-btn--danger-quiet` is the design system's own
                                                 prescription for this exact position: "Row-level
                                                 destructive actions use the quiet variant. A solid
                                                 red button repeated down every row of a table
                                                 out-shouts the records themselves." It takes
                                                 `--ss-danger`, which inverts between themes
                                                 (#b3261e light, #f3a9a4 dark) and measures 6.25:1
                                                 and 7.47:1 on these surfaces. No stylesheet
                                                 changes and no literal appears here.

                                                 The outline goes with it, deliberately: the quiet
                                                 variant draws its border only on hover, which is
                                                 the treatment every other row-level destructive
                                                 action in this product already has. --}}
                                            <button type="submit"
                                                    class="ss-btn ss-btn--sm ss-btn--danger-quiet">
                                                {{ __('Reject') }}
                                            </button>
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

<div class="row mb-4">
    <div class="col-12">
        <x-ss.card :title="__('Recently decided')"
                   :note="__('What was approved, what was rejected, and what was approved and then refused when it ran.')"
                   :flush="true">
            @if ($decided->isEmpty())
                <x-ss.empty icon="history" :title="__('Nothing has been decided yet')" />
            @else
                <div class="table-responsive">
                    <table class="table ss-table mb-0">
                        <caption class="visually-hidden">{{ __('Recently decided dangerous action requests') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Request') }}</th>
                                <th scope="col">{{ __('Action') }}</th>
                                <th scope="col">{{ __('Requested by') }}</th>
                                <th scope="col">{{ __('Decided by') }}</th>
                                <th scope="col">{{ __('Outcome') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($decided as $approval)
                                <tr>
                                    <th scope="row">#{{ $approval->id }}</th>
                                    <td>{{ __(\App\Support\Iam\TwoPersonApproval::labelFor($approval->action)) }}</td>
                                    <td>{{ $approval->requester?->name ?? __('(user removed)') }}</td>
                                    <td>{{ $approval->approver?->name ?? __('(user removed)') }}</td>
                                    <td>
                                        {{ $approval->state }}
                                        @if ($approval->refusal_reason)
                                            <br /><small class="ss-muted">{{ $approval->refusal_reason }}</small>
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

<div class="row">
    <div class="col-12">
        <x-ss.card :title="__('What is governed')"
                   :note="__('The wired set, and the actions proposed for the owner to confirm.')">
            {{-- NAV-004. `.ss-code` AND NOT A BARE `<code>`, MEASURED RATHER THAN PREFERRED, and
                 this is the same finding `platform_resources.blade.php` records against itself:
                 Bootstrap paints an unclassed `<code>` in its own `$pink` `#e83e8c`, which the
                 four-combination harness reads at 3.82:1 on the light card and 3.73:1 on the dark
                 one, against a 4.5:1 body-text floor. It is invisible in review because it reads
                 as a deliberate accent; only the pixels say otherwise.

                 FOURTEEN CELLS PER COMBINATION, FIFTY-SIX IN ALL -- the two loops below print one
                 `<code>` per governed action, and every action key on this screen was painted that
                 colour. `.ss-code` takes `--ss-fg` on `--ss-surface-sunken`, a designed pair that
                 re-themes, so no literal appears here and no stylesheet changes: the class already
                 exists for exactly this and every other operator screen with a `<code>` already
                 uses it (`platform_operations.blade.php`, `platform_incidents/_table.blade.php`,
                 `platform_resources.blade.php`). THE SECOND LOOP GETS THE SAME CHANGE IN THE SAME
                 COMMIT: repairing one of two byte-similar prints and measuring only that one is
                 how a defect survives its own repair, which this repository has recorded twice. --}}
            <h3 class="h6">{{ __('Wired') }}</h3>
            <ul>
                @foreach ($wired as $key => $definition)
                    <li>
                        <code class="ss-code">{{ $key }}</code> &mdash;
                        {{ __($definition['label'] ?? $key) }}
                    </li>
                @endforeach
            </ul>

            <h3 class="h6 mt-3">{{ __('Proposed, not wired') }}</h3>
            <p class="ss-muted">
                {{ __('Each of these is an operator action this application already guards as dangerous. Which ones require two people is the owner\'s decision, not the application\'s.') }}
            </p>
            <ul>
                @foreach ($proposed as $key => $definition)
                    <li class="mb-2">
                        <code class="ss-code">{{ $key }}</code> &mdash; {{ __($definition['label'] ?? $key) }}
                        <br /><small class="ss-muted">{{ $definition['not_wired_because'] ?? '' }}</small>
                    </li>
                @endforeach
            </ul>
        </x-ss.card>
    </div>
</div>
@endsection
