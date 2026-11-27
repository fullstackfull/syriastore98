{{--
    PHASE 06 SUPPORT-002 -- «مساعدة سياقية»: THE HELP FOR THE SCREEN YOU ARE ON, FROM THE HEADER.

    A LINK, NOT A DROPDOWN, AND THAT IS THIS FILE'S OWN PRECEDENT RATHER THAN A SHORTCUT. The
    notification bell beside it records the decision in full: a dropdown would be a second surface
    showing the same rows with its own focus trap, its own RTL placement and its own dark-mode
    contrast to verify. One surface, checked once. So this navigates to `merchant.help`, carrying the
    surface it resolved, and the help screen is the one surface.

    THE SURFACE IS RESOLVED SERVER-SIDE FROM THE CURRENT ROUTE NAME, so a screen gains contextual
    help by a one-line addition to `ContextualHelp::routeSegments()` and no view is touched at all.
    That is the whole point of keying on the route rather than asking each of the 334 merchant
    screens to declare itself.

    THE CONTROL IS ALWAYS PRESENT, EVEN WHERE THE ROUTE MAPS TO NOTHING. A help affordance that
    appears and disappears as a merchant moves around the panel is worse than one that is always
    where they left it; with no mapped surface the link carries no `surface` and the help screen
    shows the whole documentation map. `ContextualHelp::surfaceForRoute()` answers null for most of
    the panel and that is expected, not a defect.

    `Request::route()` CAN BE NULL. The header partial is rendered by `layouts/app.blade.php`, which
    is also what an exception page can extend, and `Request::route()` outside a matched route is
    null -- calling `getName()` on it is a 500 on an error page, which is the worst possible place
    for one. `optional()` rather than a bare call.

    `aria-label` ALONGSIDE `title`, for the reason the Store Link control in this partial records:
    `title` alone is not a reliable accessible name here. Both keys are declared in `en.json` AND
    `ar.json` -- `ArabicUiCoverageTest` scans this partial's siblings for `__()` keys absent from
    `ar.json`.

    NO DIRECTION IS PINNED: no `ml-`/`mr-`/`ps-`/`pe-`. The header mirrors as a whole for an Arabic
    principal and this mirrors with it.
--}}
@php
    $helpSurface = \App\Support\Help\ContextualHelp::surfaceForRoute(
        optional(\Illuminate\Support\Facades\Request::route())->getName()
    );
@endphp

<li class="dash-h-item">
    <a href="{{ route('merchant.help', $helpSurface === null ? [] : ['surface' => $helpSurface]) }}"
       class="dash-head-link h-100"
       data-bs-toggle="tooltip"
       title="{{ __('Help') }}"
       aria-label="{{ __('Help for this screen') }}">
        <i class="ti ti-help" aria-hidden="true"></i>
    </a>
</li>
