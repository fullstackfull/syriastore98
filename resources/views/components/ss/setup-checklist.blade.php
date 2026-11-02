{{--
    The new-store setup checklist — P2-06 onboarding.

    EVERY STEP IS DERIVED FROM DATA THE SCREEN ALREADY HAS. No step asks a
    question the controller cannot already answer, and none of them triggers a
    query of its own: a checklist that costs five COUNT(*) on every dashboard
    load to tell an established merchant something they finished a year ago is
    a bad trade. The caller passes the facts; this renders them.

    IT DISAPPEARS WHEN IT IS DONE, and the caller decides that — see
    `$complete`. An onboarding panel that stays forever stops being onboarding
    and becomes furniture. It is also not dismissible-while-incomplete on
    purpose: a dismiss control would need to persist a preference per user per
    store, and the honest way to make this go away is to finish it.

    A STEP THE VIEWER CANNOT ACT ON IS NOT SHOWN. Each step carries its own
    `can`; the caller gates it on the permission the destination requires, so a
    staff member is never invited to a screen that will refuse them. Steps are
    filtered, and the counter counts what is actually shown — a "2 of 5" over
    three visible rows reads as a bug.

    ACCESSIBILITY. The state of a step is an icon AND a word, never colour
    alone: `.ss-visually-hidden` carries "Done" or "To do" so the list reads
    correctly aloud, and the icons are `aria-hidden`. A done step renders as a
    `<span>`, not a link — revisiting it is allowed but it is not an
    invitation, and a link that looks like a completed task is a confusing
    target. The whole block is a `<nav>`-less `<ul>` inside the card's own
    heading, so it inherits that structure rather than inventing one.

    @param array $steps  [['label'=>…, 'done'=>bool, 'href'=>?string, 'can'=>bool], …]
--}}
@props(['steps' => []])

@php
    // Only steps this viewer may act on. Done first? No -- declaration order is
    // the order a merchant would do them in, and reordering as they complete
    // would move the row under the cursor.
    $ssSteps = array_values(array_filter($steps, fn ($s) => ($s['can'] ?? true) === true));
    $ssDone  = count(array_filter($ssSteps, fn ($s) => !empty($s['done'])));
    $ssTotal = count($ssSteps);
    $ssPct   = $ssTotal > 0 ? (int) round($ssDone / $ssTotal * 100) : 0;
@endphp

@if ($ssTotal > 0)
    <div {{ $attributes->merge(['class' => 'ss-setup']) }}>
        <p class="ss-setup__count">
            {{ __(':done of :total done', ['done' => $ssDone, 'total' => $ssTotal]) }}
        </p>

        {{-- The bar repeats the count above; it is decoration for a figure that
             is already stated in words, so it is hidden from assistive
             technology rather than read twice. --}}
        <div class="ss-progress" aria-hidden="true">
            <div class="ss-progress__fill" style="inline-size: {{ $ssPct }}%"></div>
        </div>

        <ul class="ss-setup__list">
            @foreach ($ssSteps as $ssStep)
                @php
                    $ssIsDone = !empty($ssStep['done']);
                    $ssTag    = (!$ssIsDone && !empty($ssStep['href'])) ? 'a' : 'span';
                @endphp
                <li>
                    <{{ $ssTag }}
                        @if ($ssTag === 'a') href="{{ $ssStep['href'] }}" @endif
                        class="ss-setup__item @if ($ssIsDone) ss-setup__item--done @endif">
                        {{-- AN INLINE SVG, after two other approaches failed under
                             RTL — the history is worth keeping because both
                             failures were invisible to the DOM.

                             1. `<i class="ti ti-circle">`: the icon font's
                                ::before computed to an EMPTY string.
                             2. A <span> with `border-radius` drawn in CSS:
                                the element reported ONE client rect, had no
                                box-shadow, transform or reflection, and only
                                box-sizing rules matched it — yet under
                                `dir="rtl"` it painted TWICE, once in its own
                                box and once at the far inline-end of the row.
                                Styling the span turned BOTH marks red, so both
                                were that one element. LTR rendered one.

                             An inline SVG has its own geometry and no
                             dependence on the text or border paint path, and
                             renders identically in both directions. --}}
                        <svg class="ss-setup__mark" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                            <circle cx="10" cy="10" r="8" fill="none" stroke="currentColor" stroke-width="2" />
                            @if ($ssIsDone)
                                <path d="M6 10.5l2.8 2.8L14.5 7.6" fill="none" stroke="currentColor"
                                      stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                            @endif
                        </svg>
                        <span class="ss-visually-hidden">{{ $ssIsDone ? __('Done') : __('To do') }}:</span>
                        <span class="ss-setup__label">{{ $ssStep['label'] }}</span>
                    </{{ $ssTag }}>
                </li>
            @endforeach
        </ul>
    </div>
@endif
