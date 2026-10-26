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
    the page and nothing in the server log.

    THAT RULE IS A GUARD AGAINST A BROWSER BEHAVIOUR THE PRODUCT EDITOR DOES
    NOT CURRENTLY TRIP, and saying so is the honest version. `#submit-all` is
    an <a href="#"> whose handler posts a hand-built FormData by AJAX, so the
    editor has NO native submit path and native constraint validation never
    runs on it: `form.checkValidity()` is already false on page load, because
    `cost_price` is `required` and empty, and Save works anyway. The editor
    also ALREADY hides a `required` control with `display:none` -- `cost_price`
    sits in a `.product-weight` column, which variant mode hides -- and
    `display:none` and a closed <details> have identical effects on constraint
    validation. So the failure above would already be firing if it could. The
    rule is kept because it costs nothing and because the day somebody wires a
    real submit, it is already satisfied. `tag_id[]` carries `required` in the
    markup and therefore stays outside.

    `inventory-product-fields.cjs` diffs the validation attributes, so a
    change that adds `required` to one of these SIX fields shows up there. It
    does not currently record whether a field is inside the <details>, so a
    NEW field added inside it carrying `required` is reported as an addition
    and passes -- which is the likelier shape of the mistake. The static test
    in `AdvancedFieldsDisclosureTest` is what covers that case.

    `fields` names the error-bag keys this section owns -- write them as they
    appear in the markup, `tax_id[]` included; the brackets become the dots
    Laravel uses, so `options[0][price]` matches `options.0.price`. When any of
    them failed validation the section renders OPEN with a textual badge, from
    the server, with no JavaScript and so no flash of the closed state.

    IN THE PRODUCT EDITOR THAT PATH IS LATENT, NOT LIVE, and the comment said
    otherwise before an independent review checked it. Two independent reasons:
    `ProductController` NEVER calls `withErrors()` -- `store()` and `update()`
    return `['flag' => 'error', 'msg' => ...]` as JSON for the AJAX handler to
    toast, so `$errors` is empty on every render of these two pages -- and of
    the six fields this section owns, four have no validation rule at all and
    `brand_id`/`label_id` are `nullable`, so none of them can fail in the first
    place. The capability is correct, tested, and waiting for the first section
    that owns a validated field. What a merchant sees today when a save is
    rejected is a `notifier` toast, with this section left closed; closing that
    gap means wiring the AJAX error back to the section, which is recorded in
    the ledger rather than claimed here.
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
