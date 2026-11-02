{{--
    Switch. A real checkbox underneath, so it keeps every native keyboard and
    form behaviour; this only draws it.
--}}
@props(['name', 'label', 'checked' => false, 'value' => 1, 'id' => null, 'disabled' => false])

@php $inputId = $id ?? 'ss-switch-' . $name; @endphp

<label class="ss-switch" for="{{ $inputId }}">
    <input type="checkbox"
           class="ss-switch__input"
           id="{{ $inputId }}"
           name="{{ $name }}"
           value="{{ $value }}"
           @checked($checked)
           @disabled($disabled)
           {{ $attributes }}>
    <span class="ss-switch__track" aria-hidden="true"></span>
    <span class="ss-switch__label">{{ $label }}</span>
</label>
