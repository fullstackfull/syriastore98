{{--
    Sidebar navigation ZONES (P2-01-11).

    Groups the top-level entries by the merchant's job -- Sell, Catalogue,
    Grow, Storefront, Channels, Administration -- per
    docs/development/phase-2/design/P2-DESIGN-FOUNDATION.md §3d. The grouping
    itself is config/merchant-navigation.php; menuZones() in app/Helper/helper.php
    applies it. Nothing here decides who sees what: App\Classes\Menu::add()
    applied the module and permission gates long before a node reached $zones.

    ────────────────────────────────────────────────────────────────────────
    WHY THE HEADING IS A SIBLING <li class="dash-item">, AND NOT A WRAPPER
    ────────────────────────────────────────────────────────────────────────

    The obvious markup for a group is a wrapper:

        <li class="ss-nav-zone"><h3>Sell</h3><ul>…items…</ul></li>   ← NO

    That breaks the sidebar search SILENTLY. layouts/app.blade.php:426 selects
    `.dash-navbar > .dash-item` with a DIRECT-CHILD combinator, so a wrapped
    item is no longer a direct child, the `.each()` never visits it, and typing
    in the search box simply does nothing for that item. No error, no console
    warning -- it just stops working. Every item therefore stays a direct child
    of `ul.dash-navbar`, exactly as the flat renderer left it, and the heading
    is emitted as a SIBLING before the zone's first item.

    The heading carries `dash-item` as well as `ss-nav-zone`, and that is
    deliberate rather than sloppy. Read what the search does with it
    (app.blade.php:421-461):

      - it is a `.dash-navbar > .dash-item`, so the `.each()` visits it;
      - `$item.find('> .dash-link .dash-mtext')` finds nothing -- a heading has
        no link -- so `mainText` is '', and `''.includes(value)` is false for
        any non-empty query, so `isMatch` stays false and the heading HIDES
        while the merchant is searching. That is what you want: search results
        should not be interrupted by the headings of zones they came from.
      - clearing the box runs `.dash-navbar > .dash-item').show()`, which brings
        every heading back.

    So grouping needs NO change to the shared search JavaScript. That matters:
    the search is host chrome used by all 70 WorkDo modules, and the cheapest
    change to it is still a change to all of them.

    The `dash-item` class brings the host's item styling with it, which is why
    `.ss-nav-zone` neutralises hover, pointer and spacing in
    public/css/store-syria-ds.css.

    ────────────────────────────────────────────────────────────────────────
    ACCESSIBILITY
    ────────────────────────────────────────────────────────────────────────

    The heading's typography is `.ss-navgroup`, the design system's own
    nav-group component -- not a new class. That matters beyond tidiness: the
    first version of this file wrote its own rule with
    `text-transform: uppercase`, and .ss-navgroup exists precisely to refuse
    that. Its comment says why: an ALL-CAPS eyebrow label is a template tell,
    and capitals are MEANINGLESS IN ARABIC, so half this product's merchants
    would get the tell and none of the hierarchy.

    The heading is a real <h3>, not a styled <span>, so a screen-reader user
    can jump between groups with the headings rota instead of arrowing through
    every link. The <li> takes role="presentation" because it is a label inside
    a list of links and is not itself a list item of that list; the <h3> inside
    keeps its own semantics.

    `aria-hidden` is NOT used here: the text is the group's name and a screen
    reader should read it.

    @param array $zones  from menuZones(): key, title (null = no heading), nodes
--}}
@foreach ($zones as $zone)
    @if (!empty($zone['nodes']))
        @if (!empty($zone['title']))
            <li class="dash-item ss-nav-zone" role="presentation">
                <h3 class="ss-navgroup">{{ $zone['title'] }}</h3>
            </li>
        @endif
        @include('partision.menu-tree', ['nodes' => $zone['nodes']])
    @endif
@endforeach
