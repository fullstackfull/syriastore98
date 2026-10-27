{{--
    A gateway's logo upload. Extracted because the two branches of `payment_gateway.blade.php`
    had the same markup twice, and the copy that was edited first drifted from the other.

    THE UPLOAD CONTROL HAD NO NAME. It was an `<input type="file" class="d-none">` with a
    `<label>` holding only an upload ICON -- so the file input's accessible name was the empty
    string, and a screen reader announced "button" with nothing to say what it uploads. The
    label now carries the gateway's name as text, visually hidden so the icon-only design is
    unchanged.

    `d-none` on a file input is deliberate and kept: it is the standard way to drive a file
    picker from a styled label. Unlike `display:none` on a radio, the input stays in the
    accessibility tree and in the tab order via its label.
--}}
<div class="ss-field">
    <span class="ss-label" id="{{ $gateway['image_key'] }}-label">{{ __('Gateway Image') }}</span>
    <div class="ss-gateway-image">
        <label for="{{ $gateway['image_key'] }}" class="ss-btn ss-btn--secondary ss-btn--sm ss-gateway-image__pick">
            <i class="ti ti-upload" aria-hidden="true"></i>
            <span class="ss-visually-hidden">{{ __('Upload an image for :gateway', ['gateway' => __($gateway['name'])]) }}</span>
        </label>
        <input type="file" name="{{ $gateway['image_key'] }}" id="{{ $gateway['image_key'] }}"
               class="d-none" accept="image/*"
               aria-labelledby="{{ $gateway['image_key'] }}-label">
        <img class="ss-gateway-image__img"
             alt="{{ __($gateway['name']) . ' ' . __('Image') }}"
             src="{{ get_file($setting[$gateway['image_key']] ?? Storage::url($gateway['image_default'])) ?? asset(Storage::url($gateway['image_default'])) }}">
    </div>
</div>
