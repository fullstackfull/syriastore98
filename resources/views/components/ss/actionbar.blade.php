{{--
    Sticky action bar for a full-page editor.

    A sticky bar is an accessibility hazard before it is a convenience: WCAG
    2.2 "Focus Not Obscured (Minimum)" is violated the moment it covers a
    focused field. The offset that prevents that is .ss-has-actionbar, which
    goes on the SCROLLING ANCESTOR -- usually the <form>. The stylesheet ships
    it so a screen cannot forget it.

    role="region" + a label makes it a landmark, so a screen-reader user can
    jump to the save controls from a long form instead of tabbing to the end.
--}}
@props(['label' => null])

<div {{ $attributes->merge(['class' => 'ss-actionbar']) }}
     role="region" aria-label="{{ $label ?? __('Save or discard your changes') }}">
    <div class="ss-actionbar__status">
        <span class="ss-dirty-marker">
            <i class="ti ti-alert-circle" aria-hidden="true"></i>
            {{ __('You have unsaved changes') }}
        </span>
        @isset($status){{ $status }}@endisset
    </div>
    <div class="ss-actionbar__actions">
        {{ $slot }}
    </div>
</div>
