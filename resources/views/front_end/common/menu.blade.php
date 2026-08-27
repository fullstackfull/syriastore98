@php
    $hasChildren = count($item['children']);
    if ($hasChildren) {
        $class .= ' relative';
    }
@endphp

<li class="{{ $hasChildren ? 'has-item' : '' }}">
    <a href="{{ $item['url'] ?? '#' }}" target="{{ $item['target'] }}"
       class="{{ $class ?? 'text-gray-700 relative hover:text-primary font-medium transition-all duration-300' }}">
        {{ $item['title'] }}
    </a>

    @if ($hasChildren)
        <ul class="menu-dropdown absolute top-full min-w-[200px] mt-2 py-2  border border-gray-200 rounded-lg shadow-lg bg-white">
            @foreach ($item['children'] as $ckey => $child)
                @include('front_end.common.menu', [
                    'item' => $child,
                    'key' => $ckey,
                    'class' => 'block px-4 py-2 text-gray-700 hover:bg-gray-100'
                ])
            @endforeach
        </ul>
    @endif
</li>
