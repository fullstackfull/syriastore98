{{--
    M6 — THE DOCUMENTATION NAVIGATION, ONCE.

    Rendered twice per page by design: as a sidebar on a wide screen and inside a <details>
    disclosure on a narrow one. The MARKUP is shared so the two cannot drift; what differs is only
    which wrapper is visible, and that is decided in CSS.

    `$current` is the article id (`category/slug`) or the category slug, or null. It drives
    `aria-current`, which is the part a screen-reader user depends on and the part that is easiest
    to leave out.
--}}
@php
    $current = $current ?? null;
@endphp
<nav class="ss-ps-docnav" aria-label="{{ __('Documentation') }}">
    @foreach ($categories as $cat)
        <div class="ss-ps-docnav__group">
            <h2 class="ss-ps-docnav__title">
                <a href="{{ route('platform.docs.category', $cat['slug']) }}"
                   @if ($current === $cat['slug']) aria-current="page" @endif>{{ $cat['title'] }}</a>
            </h2>
            @if (count($cat['articles']) > 0)
                <ul class="ss-ps-docnav__list">
                    @foreach ($cat['articles'] as $entry)
                        <li>
                            <a class="ss-ps-docnav__link"
                               href="{{ route('platform.docs.article', [$cat['slug'], $entry['slug']]) }}"
                               @if ($current === $entry['id']) aria-current="page" @endif>{{ $entry['title'] }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endforeach
</nav>
