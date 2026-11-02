{{--
    Stat grid. Measures get no card: a number does not need a box, and if
    every region on a dashboard is a card then nothing on it has emphasis.
    Cells are separated by hairlines instead.

    Usage:
        <x-ss.stats>
            <x-ss.stat :label="__('Orders')" :value="1284" :period="__('all time')" />
        </x-ss.stats>
--}}
@props(['label' => null])

<div {{ $attributes->merge(['class' => 'ss-stats']) }}
     @if ($label) role="group" aria-label="{{ $label }}" @endif>
    {{ $slot }}
</div>
