{{--
    P2-06-10 / WS D9 + I2 — CONTEXTUAL FIELD HELP, attached to the field it is about.

    ACCEPTANCE: "help attached to the field it is about via `aria-describedby`, in both languages,
    NOT A TOOLTIP ONLY". The last clause is the point. `product/create.blade.php:61` already
    carried `'data-bs-toggle' => 'tooltip', 'title' => __('Sku or Slug')` on the slug field — which
    is a tooltip, needs a hover or a long-press to reach, says nothing a merchant did not already
    know from the label, and is not associated with the input for assistive technology. This
    renders help that is on the page, in the reading order, and bound to its control.

    THE ASSOCIATION IS THE WHOLE JOB, so this component only owns one half of it: the `id`. The
    caller must put `aria-describedby="<for>-help"` on the control, and
    `ContextualFieldHelpTest` fails on any help element whose id nothing references and on any
    `aria-describedby` that points at nothing — an orphan hint is decoration and a dangling
    reference is worse than silence, because a screen reader announces neither.

    A `<p>`, NOT a `<div>`: it is a sentence. That also puts it in the host-override-defence list in
    `store-syria-ds.css` (`.ss-field__hint`), because the host's dark stylesheet forces every
    paragraph to #808191 with `!important` and the hint would otherwise sit at 3.42:1.
--}}
@props(['for'])

<p class="ss-field__hint" id="{{ $for }}-help" data-ss-field-help="{{ $for }}">{{ $slot }}</p>
