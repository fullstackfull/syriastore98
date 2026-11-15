@extends('platform.layout')

@section('title', $category['title'])
@section('meta_description', $category['summary'] !== '' ? $category['summary'] : __('How to use Store Syria.'))

@section('content')

    <section class="ss-ps-section ss-ps-section--plain">
        <div class="ss-ps-wrap">

            <nav class="ss-ps-breadcrumb" aria-label="{{ __('Breadcrumb') }}">
                <ol>
                    <li><a href="{{ route('platform.docs.index') }}">{{ __('Documentation') }}</a></li>
                    <li aria-current="page">{{ $category['title'] }}</li>
                </ol>
            </nav>

            <div class="ss-ps-docs">
                @include('platform.docs._aside', ['categories' => $categories, 'current' => $category['slug']])

                <div class="ss-ps-docs__main">
                    <h1 class="ss-ps-h2">{{ $category['title'] }}</h1>
                    @if ($category['summary'] !== '')
                        <p class="ss-ps-lede">{{ $category['summary'] }}</p>
                    @endif

                    @include('platform.docs._search-form', ['query' => ''])

                    @if (count($category['articles']) === 0)
                        <p class="ss-ps-note">{{ __('No documentation has been published yet.') }}</p>
                    @else
                        <ul class="ss-ps-doccards">
                            @foreach ($category['articles'] as $entry)
                                <li class="ss-ps-card">
                                    <h2 class="ss-ps-h3">
                                        <a class="ss-ps-link-plain" href="{{ route('platform.docs.article', [$category['slug'], $entry['slug']]) }}">{{ $entry['title'] }}</a>
                                    </h2>
                                    @if ($entry['summary'] !== '')
                                        <p class="ss-ps-body">{{ $entry['summary'] }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

        </div>
    </section>

@endsection
