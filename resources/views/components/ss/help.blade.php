{{-- Short, task-oriented guidance. One line per item, no tutorial. --}}
@props(['title' => null])

<aside {{ $attributes->merge(['class' => 'ss-help']) }}>
    @if ($title)<p class="ss-help__title">{{ $title }}</p>@endif
    {{ $slot }}
</aside>
