{{--
    A gateway's logo upload. Extracted because the two branches of `payment_gateway.blade.php`
    had the same markup twice, and the copy that was edited first drifted from the other.

    THE UPLOAD CONTROL HAD NO NAME. It was an `<input type="file" class="d-none">` with a
    `<label>` holding only an upload ICON -- so the file input's accessible name was the empty
    string, and a screen reader announced "button" with nothing to say what it uploads. The
    label now carries the gateway's name as text, visually hidden so the icon-only design is
    unchanged.

    `d-none` WAS REMOVED HERE AND THE PARAGRAPH THAT DEFENDED IT WAS WRONG. `.d-none` is
    `display: none !important` (public/assets/css/style.css:8212), and a `display: none` element
    is not rendered -- so it is out of the tab order AND out of the accessibility tree. A
    `<label for>` forwards a CLICK; it does not restore focusability, and the label is not
    focusable itself without `tabindex`. There was no keyboard path to this picker at all.
    `.ss-visually-hidden` (store-syria-ds.css:1582) hides it visually while keeping it rendered,
    focusable and announced; the focus ring comes from store-syria-a11y.css's
    `:has(+ input[type="file"]:focus-visible)` rule, because the browser would otherwise paint it
    on a 1px clipped box.

    `aria-labelledby` WAS ALSO REMOVED, and not because it was dead. The span it named has always
    been rendered and reachable -- it was the INPUT that was absent from the tree. Once the input
    IS in the tree, `aria-labelledby` outranks the native `<label for>` in the accessible-name
    algorithm, so it would have named all twenty-odd gateways identically, "Gateway Image", and
    suppressed the per-gateway text below that exists to tell them apart. That is the same defect
    VariantDeleteScopeTest:313-317 measured on the variant panels, where the panel whose own term
    was `M/L` was announced as `M`. The name now comes from the label, which carries the
    gateway's own name. The visible caption stays; its `id` is simply no longer referenced.
--}}
<div class="ss-field">
    <span class="ss-label" id="{{ $gateway['image_key'] }}-label">{{ __('Gateway Image') }}</span>
    <div class="ss-gateway-image">
        <label for="{{ $gateway['image_key'] }}" class="ss-btn ss-btn--secondary ss-btn--sm ss-gateway-image__pick">
            <i class="ti ti-upload" aria-hidden="true"></i>
            <span class="ss-visually-hidden">{{ __('Upload an image for :gateway', ['gateway' => __($gateway['name'])]) }}</span>
        </label>
        <input type="file" name="{{ $gateway['image_key'] }}" id="{{ $gateway['image_key'] }}"
               class="ss-visually-hidden" accept="image/*">
        <img class="ss-gateway-image__img"
             alt="{{ __($gateway['name']) . ' ' . __('Image') }}"
             src="{{ get_file($setting[$gateway['image_key']] ?? Storage::url($gateway['image_default'])) ?? asset(Storage::url($gateway['image_default'])) }}">
    </div>
</div>
