{{--
    M6 — THE NAVIGATION COLUMN, INCLUDED BY EACH DOCUMENTATION PAGE.

    NOT A `$slot` COMPONENT, DELIBERATELY. An earlier draft of this file wrapped the page in a
    shell that printed `{{ $slot }}`. `@include` does not provide `$slot`, and CLAUDE.md records
    what that failure looks like: perfect chrome, HTTP 200 and an EMPTY CONTENT AREA, with nothing
    anywhere to indicate it. `tests/Unit/Ui/SlotLayoutViewsRenderTest` fails on the shape for that
    reason. So the two-column grid is opened by the page itself and this file is only the column.

    Rendered twice: a disclosure for narrow screens and an aside for wide ones. The markup inside
    both is `_nav.blade.php`, so they cannot drift; CSS decides which is visible.
--}}
<details class="ss-ps-docnav-disclosure">
    <summary>{{ __('All documentation') }}</summary>
    <div class="ss-ps-docnav-disclosure__panel">
        @include('platform.docs._nav', ['categories' => $categories, 'current' => $current ?? null])
    </div>
</details>

{{-- A PLAIN <div>, NOT AN <aside>. Measured by axe in M7: an `<aside>` inside `<main>` trips
     `landmark-complementary-is-top-level` on every documentation page, in all four combinations.
     The element is not complementary content in the first place -- `_nav.blade.php` renders a
     real `<nav aria-label>` inside it, which is the landmark that belongs here. The wrapper only
     ever existed to hold the grid column. --}}
<div class="ss-ps-docs__aside">
    @include('platform.docs._nav', ['categories' => $categories, 'current' => $current ?? null])
</div>
