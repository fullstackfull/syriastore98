@props(['slug'])

<a href="{{ route('market.place.list.page', ['storeSlug' => $slug]) }}" class="text-gray-600 hover:text-primary-dark transition relative ltr:ml-2 rtl:mr-2"
            aria-label="{{__('MarketPlace')}}">
<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M3 21h18"/>
    <path d="M5 21V7l8-4v18"/>
    <path d="M19 21V11l-6-4"/>
    <path d="M9 9v.01"/>
    <path d="M9 12v.01"/>
    <path d="M9 15v.01"/>
    <path d="M9 18v.01"/>
</svg>
</a>