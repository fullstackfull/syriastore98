@php
    $hasChildren = isset($item['children']) && count($item['children']);
    $title = $item['title'] ?? '';
    $url = $item['url'] ?? '#';
    $target = $item['target'] ?? '_self';
    $type = $item['type'] ?? ''; // Optional: use this to handle "megamenu"
@endphp

<li class="{{ $hasChildren ? 'has-item' : '' }}">
    <a href="{{ $url }}" target="{{ $target }}"
        class="relative text-gray-700 hover:text-primary font-medium transition-all duration-300 {{ $hasChildren ? 'pe-4' : '' }}">
        {{ $title }}
    </a>

    @if ($hasChildren)
        @if(isset($item['megamenu']) && $item['megamenu'])
            {{-- Megamenu --}}
            <div class="menu-dropdown w-full absolute left-0 top-full p-4 bg-white border-t shadow z-50">
                <div class="container mx-auto p-4">
                    <div class="grid grid-cols-4 gap-5">
                        @foreach($item['children'] as $childGroup)
                            @if(isset($childGroup['title']))
                                <ul>
                                    <li class="list-title font-semibold mb-3"><span>{{ $childGroup['title'] }}</span></li>
                                    @foreach($childGroup['children'] ?? [] as $link)
                                        <li class="mb-2">
                                            <a href="{{ $link['url'] ?? '#' }}"
                                                class="hover:text-primary transition-all duration-300">
                                                {{ $link['title'] ?? '' }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @elseif(isset($childGroup['image']))
                                <div class="megamenu-card-inner rounded-lg bg-white shadow-md border">
                                    <a href="{{ $childGroup['url'] ?? '#' }}" class="relative block w-full pt-[70%]">
                                        <img src="{{ $childGroup['image'] }}"
                                            class="absolute top-0 left-0 h-full w-full object-contain"
                                            alt="megamenu-image">
                                    </a>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>
        @else
            {{-- Simple Dropdown --}}
            <ul class="menu-dropdown absolute top-full min-w-[200px] mt-2 py-2 border border-gray-200 rounded-lg shadow-lg bg-white z-50">
                @foreach ($item['children'] as $ckey => $child)
                    @include('front_end.common.menu', [
                        'item' => $child,
                        'key' => $ckey
                    ])
                @endforeach
            </ul>
        @endif
    @endif
</li>
