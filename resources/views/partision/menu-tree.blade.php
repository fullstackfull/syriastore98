{{--
    The merchant / Super Admin sidebar navigation.

    Replaces the hand-concatenated HTML string that `generateMenu()` built in
    app/Helper/helper.php. `menuTree()` supplies the data; this renders it.
    Nothing about who sees what lives here -- App\Classes\Menu::add() applied
    the module-entitlement and Laratrust permission gates long before an item
    reached $nodes.

    THE DOM CONTRACT, which is not cosmetic:

      .dash-navbar > .dash-item        the inline sidebar search at
      .dash-link                       layouts/app.blade.php:358-398 selects
      .dash-mtext                      these, and `.dash-navbar > .dash-item`
      .dash-submenu                    uses a DIRECT-CHILD combinator. Wrap an
      .dash-arrow                      item in any new element and search stops
                                       working with NO error.

    So: do not add a wrapper, do not rename a class, and do not change the
    nesting depth. Add a `group` key to the data and render a sibling heading
    if navigation grouping is wanted -- never a wrapper around .dash-item.

    Recursion is via @include of this same file, which is how a submenu gets
    the identical markup rather than a second copy of it.

    DELIBERATELY NOT HERE YET: aria-hidden="true" on the decorative <i>. It is
    the right attribute -- the icon sits beside its own text label -- but this
    commit's contract is that ONLY THE RENDERING MOVES, and the parity proof is
    worth more than the attribute. It belongs in its own change, with the
    accessibility checks behind it.

    TWO things this changes on purpose, both fixes, both asserted in
    tests/Support/Security/bin/verify-menu-render-parity.php:

      1. The title is ESCAPED. The string builder interpolated
         __($item['title']) straight into HTML. Scope, stated honestly: every
         title reaching generateMenu() is a hardcoded literal in a module
         listener and no lang value carries markup, so this is hardening, not
         the closure of a live XSS. generateSideMenu()'s database-sourced
         titles are still unescaped (helper.php:2267) -- a separate change.

      2. The arrow and the submenu <ul> appear only when children ACTUALLY
         rendered. The old builder asked hasChildren() and then rendered a list
         that filters, so a parent whose only child was excluded got a
         disclosure arrow opening onto an empty <ul> -- a control promising a
         submenu with nothing in it.

      3. No STRAY </span> on child items. The old builder opened
         <span class="dash-mtext"> only for a top-level item
         (`if ($item['parent'] == null)`) but appended '</span>' for EVERY
         item, so each submenu link carried an unmatched closing tag. Browsers
         and libxml both discard it, which is why the DOM-tree comparison in
         verify-menu-render-parity.php is green across this difference rather
         than in spite of it -- the trees really are equal. Recorded because an
         independent review asked, reasonably, whether the parity proof was
         hiding a markup change; this is the one place the byte streams differ
         and it differs by removing malformed output.

    @param array $nodes  from menuTree(): name, title, icon, href, isTop, children
--}}
@foreach ($nodes as $node)
    <li class="dash-item{{ $node['isTop'] ? ' dash-hasmenu' : '' }}">
        <a href="{{ $node['href'] }}" class="dash-link">
            @if ($node['isTop'])
                <i class="ti ti-{{ $node['icon'] }}"></i>
                <span class="dash-mtext">{{ $node['title'] }}</span>
            @else
                {{ $node['title'] }}
            @endif
            @if (! empty($node['children']))
                <span class="dash-arrow"><i data-feather="chevron-right"></i></span>
            @endif
        </a>
        @if (! empty($node['children']))
            <ul class="dash-submenu">
                @include('partision.menu-tree', ['nodes' => $node['children']])
            </ul>
        @endif
    </li>
@endforeach
