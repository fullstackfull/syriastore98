@extends('platform.layout')

@section('title', __('Search the documentation'))

@section('content')

    <section class="ss-ps-section ss-ps-section--plain">
        <div class="ss-ps-wrap">

            <nav class="ss-ps-breadcrumb" aria-label="{{ __('Breadcrumb') }}">
                <ol>
                    <li><a href="{{ route('platform.docs.index') }}">{{ __('Documentation') }}</a></li>
                    <li aria-current="page">{{ __('Search') }}</li>
                </ol>
            </nav>

            <div class="ss-ps-docs">
                @include('platform.docs._aside', ['categories' => $categories, 'current' => null])

                <div class="ss-ps-docs__main">
                    <h1 class="ss-ps-h2">{{ __('Search the documentation') }}</h1>

                    @include('platform.docs._search-form', ['query' => $query])

                    @if ($query === '')
                        <p class="ss-ps-body">{{ __('Type what you are stuck on. Searching works in Arabic and in English.') }}</p>
                    @elseif (count($results) === 0)
                        <p class="ss-ps-note">{{ __('Nothing matched that. Try a shorter phrase, or browse the categories beside this.') }}</p>
                    @else
                        <p class="ss-ps-body" aria-live="polite">
                            {{ __('Results for') }} <strong>{{ $query }}</strong>
                        </p>
                        <ul class="ss-ps-doccards">
                            @foreach ($results as $result)
                                <li class="ss-ps-card">
                                    <h2 class="ss-ps-h3">
                                        <a class="ss-ps-link-plain"
                                           href="{{ route('platform.docs.article', [$result['article']['category'], $result['article']['slug']]) }}">{{ $result['article']['title'] }}</a>
                                    </h2>
                                    @if ($result['snippet'] !== '')
                                        <p class="ss-ps-body">{{ $result['snippet'] }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        {{-- Said plainly rather than implied: the search matches words as they are
                             written, after folding away the Arabic orthography a reader will not
                             type. It does not stem and it does not guess. --}}
                        <p class="ss-ps-help">{{ __('Search matches whole words. It does not correct spelling or search across languages.') }}</p>
                    @endif
                </div>
            </div>

        </div>
    </section>

@endsection
