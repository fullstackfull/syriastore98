{{--
    P2-06-12 / WS I4 + I5 — A GROUP OF CHOICES, named and explained for assistive technology.

    WHY A FIELDSET AND NOT ANOTHER `aria-describedby` ON THE INPUT. P2-06-10 solved the single
    field: one control, one description, `aria-describedby` on the control. A GROUP is a different
    problem — the thing being described is the SET, and a description hung off one radio is
    announced only when the reader happens to land on that radio. `<fieldset>` + `<legend>` is the
    native grouping for exactly this, so the group's name is announced when focus ENTERS the group,
    and `aria-describedby` on the fieldset carries the instruction with it.

    WHAT WAS THERE BEFORE, measured on the product editor: `Form::label('', __('Stock Status:'))`
    — a label with an EMPTY `for` — above a bare `<div>` of radios. A screen reader announced "In
    Stock, radio button 1 of 3" and nothing about what was being chosen. The group name existed
    visually and not programmatically, which is the failure this component exists to remove.

    THE `min-width: 0` RESET IS DEFENSIVE, NOT LOAD-BEARING HERE, and the distinction is recorded
    because the first version of this note claimed the stronger thing. A `<fieldset>` does have an
    implicit minimum content size in every engine, which makes one refuse to shrink inside a flex
    or grid row -- but removing the reset and re-running the live check changed NOTHING on either
    product screen, because both groups sit in block-level Bootstrap columns rather than as
    constrained flex children. So the reset is kept as cheap insurance against a future layout
    change, and the live check's overflow assertion is a guard for that case rather than evidence
    of a bug fixed. Measured by reverting it: 0 of 82 cases failed.

    THE INSTRUCTION IS OPTIONAL BUT THE ASSOCIATION IS NOT: when `hint` is given, the fieldset
    gets `aria-describedby` pointing at it, and `ChoiceGroupGuidanceTest` fails on a hint nothing
    references AND on an `aria-describedby` pointing at nothing. An orphan hint is decoration; a
    dangling reference is worse than silence, because a reader announces neither.
--}}
@props(['name', 'legend', 'hint' => null])

<fieldset class="ss-choicegroup"
          data-ss-choicegroup="{{ $name }}"
          @if ($hint) aria-describedby="{{ $name }}-group-help" @endif>

    <legend class="ss-choicegroup__legend">{{ $legend }}</legend>

    @if ($hint)
        <p class="ss-field__hint" id="{{ $name }}-group-help">{{ $hint }}</p>
    @endif

    {{ $slot }}
</fieldset>
