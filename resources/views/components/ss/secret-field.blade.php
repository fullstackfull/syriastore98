@props([
    'name',
    'label',
    'stored' => null,
    'placeholder' => null,
    'hint' => null,
    'required' => false,
])

{{--
    A stored credential's INPUT: never carries the value, and says so.
    =============================================================================================

    F117: 16 credential fields across 11 settings panels rendered `value="{{ $setting[...] }}"`,
    putting live API keys, tokens and mail passwords into the HTML — readable by anyone who can
    open the page, by any browser extension, and in any screenshot or screen-share of it. Several
    sit on panels a merchant opens to change something unrelated.

    WHAT REACHES THE PAGE IS WHETHER A SECRET EXISTS, NEVER WHAT IT IS. No value, no length, and
    no partial mask: a prefix is a meaningful hint for several providers' key formats, so
    `sk_live_••••` leaks more than it hides.

    AND THE FIELD SAYS WHAT HAPPENS IF IT IS LEFT BLANK, because this component only works paired
    with `SecretSetting::resolve()` on the save path. A masked box over an unconditional
    `$post[$k] = $request->$k` ERASES the credential on the next save of anything else on the same
    panel. The hint is the user-visible half of that contract.

    `autocomplete="new-password"` keeps the browser from filling a saved site password into an API
    key box, which is a real way to overwrite a credential by accident.
--}}

@php
    $hasSecret = \App\Support\SecretSetting::isSet($stored);
    $fieldId = 'secret-' . \Illuminate\Support\Str::slug($name);
@endphp

<div class="ss-field">
    <label class="ss-label" for="{{ $fieldId }}">
        {{ $label }}
        @if ($required)
            <span class="ss-label__required" aria-hidden="true">*</span>
        @endif
    </label>

    <input
        type="password"
        class="ss-input"
        id="{{ $fieldId }}"
        name="{{ $name }}"
        value="{{ \App\Support\SecretSetting::display() }}"
        autocomplete="new-password"
        spellcheck="false"
        placeholder="{{ $placeholder ?? ($hasSecret ? __('Saved — leave blank to keep it') : __('Not set')) }}"
        aria-describedby="{{ $fieldId }}-hint"
        {{ $attributes->except(['class', 'id', 'name', 'value', 'type']) }}>

    <p class="ss-field__hint" id="{{ $fieldId }}-hint">
        @if ($hasSecret)
            {{ __('A value is saved. Leave this blank to keep it, or type a new one to replace it.') }}
        @else
            {{ __('No value is saved yet.') }}
        @endif
        @if ($hint)
            {{ $hint }}
        @endif
    </p>
</div>
