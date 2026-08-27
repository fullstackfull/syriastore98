@php
    $hasChildren = isset($item['children']) && count($item['children']);
    $baseClass = 'relative font-medium text-gray-700 hover:text-orange-500 py-3 block';
    $linkClass = $class ?? $baseClass;
@endphp

<li class="{{ $hasChildren ? 'relative dropdown has-item' : '' }}">
    <a href="{{ $item['url'] ?? '#' }}" target="{{ $item['target'] ?? '_self' }}" class="{{ $hasChildren ? $linkClass . ' pe-4 flex items-center' : $linkClass }}">
        {{ $item['title'] }}
    </a>

    @if ($hasChildren)
        <ul class="menu-dropdown absolute left-0 mt-2 w-40 bg-white shadow-lg rounded-md py-1 z-50">
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
