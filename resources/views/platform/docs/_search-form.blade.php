{{-- M6 — the search form. A GET form, so a result page is a shareable URL and the back button
     behaves. The label is real and visible to a screen reader; the placeholder is not a label. --}}
<form class="ss-ps-docsearch" action="{{ route('platform.docs.search') }}" method="get" role="search">
    <label class="ss-visually-hidden" for="ss-ps-docsearch-q">{{ __('Search the documentation') }}</label>
    <input class="ss-ps-docsearch__input"
           id="ss-ps-docsearch-q"
           type="search"
           name="q"
           value="{{ $query ?? '' }}"
           maxlength="120"
           placeholder="{{ __('Search the documentation') }}">
    <button class="ss-ps-btn ss-ps-btn--primary" type="submit">{{ __('Search') }}</button>
</form>
