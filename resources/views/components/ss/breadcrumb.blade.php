{{--
    The one breadcrumb for every merchant screen — P2-01-14 / WS A3.

    WHY A COMPONENT AND NOT THE HOST THEME'S `<ul class="breadcrumb">`. The layout rendered
    Bootstrap's breadcrumb, whose `.breadcrumb` rule sets `--bs-breadcrumb-item-active-color` and
    `--bs-breadcrumb-divider-color` to `#6c757d` — and those declarations are BYTE-IDENTICAL in
    `public/assets/css/style.css:5343` and `public/assets/css/style-dark.css:5350`. A colour that
    is the same in both stylesheets cannot be legible in both themes, which is **F151**: measured
    at 4.03:1 in dark, below AA. The `.ss-breadcrumb` tokens are redefined per theme, so this is
    fixed at the shared component rather than on one screen.

    STRUCTURE IS THE ARIA PATTERN, not decoration: a labelled `<nav>` around an ORDERED list,
    because a breadcrumb trail is a sequence. The separator is a CSS pseudo-element on
    `.ss-breadcrumb__item + .ss-breadcrumb__item`, so it is never announced and it mirrors under
    `[dir="rtl"]` by `transform` rather than by a hard-coded glyph pointing one way.

    THE TRAIL COMES IN AS A SLOT, which is what makes this adoptable: all 107 screens keep their
    own `@section('breadcrumb')` and supply only their own items. The Home item lives here so it
    is identical everywhere and cannot drift.

    `aria-current="page"` BELONGS ON EXACTLY ONE ITEM AND IT MUST NOT BE A LINK. Five screens
    carried it on both the parent link and the leaf — `product/{create,edit,show}` and
    `plans/{create,edit}` — so a screen reader announced two current pages, one of them a link to
    somewhere else. That is corrected in those screens, and `BreadcrumbContractTest` asserts the
    rule across every screen that defines the section so it cannot come back.
--}}
<nav aria-label="{{ __('Breadcrumb') }}">
    <ol class="ss-breadcrumb mt-2">
        <li class="ss-breadcrumb__item">
            <a class="ss-breadcrumb__link" href="{{ route('dashboard') }}">{{ __('Home') }}</a>
        </li>
        {{ $slot }}
    </ol>
</nav>
