{{--
    Advanced-fields disclosure (P2-03-8).

    The product editor's main card carried thirteen controls in one
    undifferentiated grid, so the six a merchant sets once for the whole
    catalogue -- taxes, tax status, brand, label, shipping class, weight --
    sat with exactly the same weight as the five they set on every single
    product. This collapses the first group behind one control. It does not
    move anything out of the form, rename anything, or change a single value.

    IT IS A NATIVE <details>, DELIBERATELY, and not the DS's
    [data-ss-disclose] button/target pair:

      - A closed <details> STILL SUBMITS the controls inside it. That is the
        whole guarantee this component exists to give. `disabled` would drop
        them from the POST body silently, and because they would then be
        absent rather than empty, a `nullable` rule would pass and the
        merchant's saved product would quietly lose its brand and its tax.
      - It opens, closes and announces its own state with no JavaScript, so
        the editor still works on a page whose JS failed to load -- which is
        not hypothetical here: F16 had Dropzone and select2 404ing on every
        admin page.
      - Chrome's find-in-page reaches text inside a closed <details>, so a
        merchant hunting for "weight" still finds it.
      - <summary> is a button to the accessibility tree already, with the
        expanded state exposed. Do NOT add aria-expanded by hand; a native
        details that also carries the attribute reports its state twice and
        the two can disagree.

    NEVER PUT A CLIENT-SIDE `required` CONTROL INSIDE ONE. A closed <details>
    hides the control from the browser's own constraint UI, so Chrome refuses
    to submit and logs "An invalid form control with name='...' is not
    focusable" -- a Save button that does nothing at all, with no message on
    the page and nothing in the server log. In the product editor `tag_id[]`
    carries `required` in the markup (while the server rules do not require
    it), so it stays outside this component. The six fields that go in carry
    no constraint attributes; `inventory-product-fields.cjs` records them, so
    a future change that adds one to a collapsed field shows up as a
    validation-attribute diff rather than as a dead button.

    `fields` names the error-bag keys this section owns -- write them as they
    appear in the markup, `tax_id[]` included, and the brackets are stripped
    here. When any of them failed validation the section renders OPEN, from
    the server, so a merchant who submits a bad tax id lands on the field
    instead of on a closed box. That is owner condition 2 of 2026-10-26, and
    it is done without JavaScript so there is no flash of the closed state.
--}}
@props([
    'title',
    'fields' => [],
    'open' => false,
    'hint' => null,
])

@php
    // `$errors` is shared with every view by ShareErrorsFromSession; guard it
    // anyway so this component is renderable in isolation by a test.
    $ssBag = $errors ?? null;

    // A markup name is not an error-bag key. `tax_id[]` is `tax_id`, and a NESTED name
    // like `options[0][price]` is `options.0.price` -- Laravel keys the bag with dots.
    // `rtrim($f, '[]')` gets the first right and the second wrong (`options[0][price`),
    // silently, which matters because this editor is full of
    // `custom_field_repeater_basic[0][custom_field]`-shaped names that a later slice may
    // want to collapse. Six fields use this today and only one of them is bracketed, so
    // the bug would not have shown up until then.
    $ssKeys = array_map(static function ($f) {
        $f = str_replace('[]', '', (string) $f);

        return trim(str_replace(['][', '[', ']'], ['.', '.', ''], $f), '.');
    }, (array) $fields);

    $ssHasError = false;
    foreach ($ssKeys as $ssKey) {
        if ($ssBag && $ssBag->has($ssKey)) { $ssHasError = true; break; }
    }
@endphp

<details {{ $attributes->merge(['class' => 'ss-advanced']) }}
         @if ($open || $ssHasError) open @endif
         @if ($ssHasError) data-ss-advanced-error="true" @endif>
    <summary class="ss-advanced__summary">
        <i class="ti ti-chevron-right ss-advanced__chevron" aria-hidden="true"></i>
        <span class="ss-advanced__title">{{ $title }}</span>
        @if ($ssHasError)
            {{-- The badge is a second, textual signal. The open state alone
                 would be carried by shape only, and a merchant who had
                 already opened the section would see no difference at all. --}}
            <span class="ss-advanced__badge">{{ __('Needs attention') }}</span>
        @endif
    </summary>

    @if ($hint)
        <p class="ss-advanced__hint">{{ $hint }}</p>
    @endif

    <div class="ss-advanced__body">{{ $slot }}</div>
</details>
