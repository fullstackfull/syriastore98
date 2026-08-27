@php
    // Detect if this item has children
    $hasChildren = isset($item['children']) && is_array($item['children']) && count($item['children']) > 0;

    // Set dynamic classes
    $baseClass = 'block py-3 px-4 rounded-lg transition-colors';
    $itemClass = $class ?? 'text-gray-700 font-medium hover:bg-gray-100';

    // Icon support
    $icon = $item['icon'] ?? 'fas fa-link'; // fallback if no icon
@endphp

@if ($hasChildren)
    <li>
        <div class="mobile-dropdown-toggle {{ $baseClass }} {{ $itemClass }} cursor-pointer flex justify-between items-center">
            <div>
                <i class="{{ $icon }} mr-3"></i>{{ $item['title'] ?? '' }}
            </div>
            <i class="fas fa-chevron-down text-xs transition-transform duration-200"></i>
        </div>
        <div class="mobile-dropdown-content pl-4 mt-1">
            @foreach ($item['children'] as $child)
                <a href="{{ $child['url'] ?? '#' }}"
                   class="block py-2 px-4 rounded-lg text-gray-600 hover:bg-gray-100 flex items-center transition-colors">
                    <i class="{{ $child['icon'] ?? 'fas fa-circle' }} mr-3 text-green-800 w-4 text-center"></i>
                    {{ $child['title'] ?? '' }}
                </a>
            @endforeach
        </div>
    </li>
@else
    <li>
        <a href="{{ $item['url'] ?? '#' }}" class="{{ $baseClass }} {{ $itemClass }}">
            <i class="{{ $icon }} ltr:mr-3 rtl:ml-3"></i>{{ $item['title'] ?? '' }}
        </a>
    </li>
@endif