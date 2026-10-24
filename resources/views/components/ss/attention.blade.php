{{--
    The "needs attention" block: the one region on a dashboard that earns an
    accent bar.

    It is the CALLER's job not to render this when there is nothing in it. A
    permanent "0 items need attention" panel teaches merchants to stop looking
    at this part of the screen, so there is deliberately no empty state here.
--}}
@props(['title' => null, 'severity' => 'brand'])

@php
    $severityClass = match ($severity) {
        'danger'  => ' ss-attention--danger',
        'warning' => ' ss-attention--warning',
        default   => '',
    };
@endphp

<section {{ $attributes->merge(['class' => 'ss-attention' . $severityClass]) }}
         aria-labelledby="{{ $id = 'ss-attention-' . uniqid() }}">
    <h2 class="ss-attention__title" id="{{ $id }}">{{ $title ?? __('Needs attention') }}</h2>
    <ul class="ss-attention__list">
        {{ $slot }}
    </ul>
</section>
