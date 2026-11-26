@extends('layouts.app')

@section('page-title', __('Theme Customize'))

@section('breadcrumb')
<li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('theme.index') }}">{{ __('Themes') }}</a></li>
<li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('theme.pages', $theme) }}">{{ __('Pages') }}</a></li>
<li aria-current="page" class="ss-breadcrumb__item">{{ __('Customize') }}</li>
@endsection


@section('content')
<div class="card">
    <div class="card-header p-3">
        <h2 class="h3">{{ ucfirst($theme) }}</h2>
        <span>{{ __('Organize and adjust all settings about') }} {{ $theme }}.</span>
    </div>
    <div class="card-body p-3">
        <div class="row row-gap-2">
            <div class="col-xxl-2 col-sm-6 col-12">
                <div class="card mb-0">
                    <div class="card-header p-3">
                        <h4 class="mb-0">{{ __('Jump To Page') }}</h4>
                    </div>
                    <div class="card-body setting-tab p-3">
                        <ul class="nav nav-pills flex-column gap-1">

                            @foreach ($page_json as $page)
                            @if(isset($page['is_order']) && $page['is_order'] == true)
                            @php
                                if (empty($orders)) {
                                    $orders = $page['orders'];
                                }
                            @endphp
                            <li class="nav-item">
                                <a href="{{ route('theme.customize.order', [$theme, $page['slug']]) }}"
                                    class="nav-link {{ $page['slug'] == $slug ? 'active' : '' }}">{{ __($page['title']) }}</a>
                            </li>
                            @endif
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-xxl-10 col-12">
                <div class="card mb-0">
                    <div class="card-header p-3">
                        <h4 class="mb-0">{{ __('Change :page Page Order', ['page' => ucfirst($slug)]) }}</h4>
                    </div>
                     {!! Form::open(['route' => ['theme.customize.update.order', $theme], 'enctype' => 'multipart/form-data']) !!}
                    @csrf
                    <div class="card-body setting-tab p-3">
                        <input type="hidden" name="page_slug" value="{{ $slug }}" class="ui-sortable-handle">
                        <input type="hidden" name="orders" value="{{ $orders }}" id="hidden_order" class="ui-sortable-handle">

                        {{-- GAP-100 / P03-B-07. The list used to be read straight out of
                             `explode(',', $orders)`, which yields a single EMPTY token for a theme
                             that declares no orderable page and therefore rendered one nameless
                             row. The tokens are normalised once here instead, so the row count,
                             the position text and the end stops all count the same thing. --}}
                        @php
                            $sections = array_values(array_filter(
                                array_map('trim', explode(',', (string) $orders)),
                                static fn ($token) => $token !== ''
                            ));
                            $sectionTotal = count($sections);
                        @endphp

                        {{-- `role="list"` is not redundant beside `list-unstyled`: that class sets
                             `list-style: none`, which drops list semantics from the accessibility
                             tree in Safari, and the position announcements below are only
                             meaningful inside a list. --}}
                        <ul class="list-unstyled list-group sortable ui-sortable" role="list"
                            aria-label="{{ __('Page sections, in the order they appear') }}">
                            @foreach ($sections as $sectionIndex => $sectionKey)
                            @php $sectionLabel = ucfirst(str_replace('_', ' ', $sectionKey)); @endphp
                            <li class="list-group-item d-flex align-items-center justify-content-between ui-sortable-handle"
                                data-id="{{ $sectionKey }}" data-ss-order-item
                                data-ss-order-label="{{ $sectionLabel }}">
                                <h6 class="mb-0">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round" class="feather feather-move me-3" aria-hidden="true">
                                        <polyline points="5 9 2 12 5 15"></polyline>
                                        <polyline points="9 5 12 2 15 5"></polyline>
                                        <polyline points="15 19 12 22 9 19"></polyline>
                                        <polyline points="19 9 22 12 19 15"></polyline>
                                        <line x1="2" y1="12" x2="22" y2="12"></line>
                                        <line x1="12" y1="2" x2="12" y2="22"></line>
                                    </svg>
                                    <span>{{ $sectionLabel }}</span>
                                </h6>

                                {{-- THE POSITION IS A DESCRIPTION, NOT PART OF THE NAME. A control
                                     whose accessible name changes under the user is re-announced
                                     as a different control; a description can change freely. Both
                                     buttons point at this one element, and the script rewrites its
                                     text after every move, drag included. --}}
                                <span class="ss-visually-hidden" data-ss-order-position
                                    id="ss-order-position-{{ $sectionIndex }}">{{ __('Position :n of :total', ['n' => $sectionIndex + 1, 'total' => $sectionTotal]) }}</span>

                                {{-- GAP-100 / P03-B-07 — THE KEYBOARD PATH.
                                     `PHASE-03-M4-THEME-EDITOR-PLAN.md:298` measured this screen at
                                     `keydown` count 0 and called it a WCAG 2.1.1 and 2.5.7 failure.
                                     Two real buttons answer both: Enter and Space drive them with
                                     no key convention to discover, and a pointer user can reorder
                                     without a dragging movement, which is what 2.5.7 asks for and
                                     an arrow-key handler alone would not give.

                                     THE END STOPS ARE `aria-disabled`, NOT `disabled`, and that is
                                     a focus decision rather than a style one. `disabled` takes the
                                     control out of the tab order, so moving a row to the last
                                     position would disable the very button that was pressed and
                                     drop focus to the document. The design system paints both
                                     states identically, so the script has to refuse an inert
                                     control itself.

                                     The colours are tokens, measured rather than assumed:
                                     `ss-btn--ghost` resolves to 6.42:1 on the light card and
                                     6.18:1 on the dark one. The host's own `bs-body-color` is
                                     byte-identical in both stylesheets and reads 1.10:1 on the
                                     dark card, which is why nothing added here inherits it. --}}
                                <span class="ss-scope ss-table__actions">
                                    <button type="button" class="ss-btn ss-btn--ghost ss-btn--sm ss-btn--icon"
                                        data-ss-order-move="up"
                                        aria-describedby="ss-order-position-{{ $sectionIndex }}"
                                        @if ($sectionIndex === 0) aria-disabled="true" @endif
                                        title="{{ __('Move :section up', ['section' => $sectionLabel]) }}">
                                        <i class="ti ti-chevron-up" aria-hidden="true"></i>
                                        <span class="ss-visually-hidden">{{ __('Move :section up', ['section' => $sectionLabel]) }}</span>
                                    </button>

                                    <button type="button" class="ss-btn ss-btn--ghost ss-btn--sm ss-btn--icon"
                                        data-ss-order-move="down"
                                        aria-describedby="ss-order-position-{{ $sectionIndex }}"
                                        @if ($sectionIndex === $sectionTotal - 1) aria-disabled="true" @endif
                                        title="{{ __('Move :section down', ['section' => $sectionLabel]) }}">
                                        <i class="ti ti-chevron-down" aria-hidden="true"></i>
                                        <span class="ss-visually-hidden">{{ __('Move :section down', ['section' => $sectionLabel]) }}</span>
                                    </button>
                                </span>
                            </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="card-footer p-3">
                        {{-- GAP-098: 2.58:1 dark on the host fill; `.ss-btn--primary` 7.92:1. --}}
                        {!! Form::button(__('Save Changes'), ['type' => 'submit', 'class' => 'ss-btn ss-btn--primary']) !!}
                    </div>
                     {!! Form::close() !!}
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Outside the list, so a reorder never moves or re-creates the region a screen reader is
     already watching. Same placement and reason as the sibling sortable at
     `attribute_option/index.blade.php`. --}}
<div class="ss-visually-hidden" role="status" aria-live="polite" id="ss-order-status"
    data-ss-order-announce></div>
@endsection

@push('scripts')
{{-- PHASE 03 M4-9. This loaded jquery-ui from the VENDOR'S PUBLIC DEMO SITE
     (demo.workdo.io/vcardgo-saas/...) -- a third party serving executable JavaScript into an
     authenticated merchant's admin page, on every view of this screen. The identical library
     ships locally at public/js/jquery-ui.min.js and the sibling view in this very directory,
     customize-script.blade.php:1, already loads it correctly. --}}
<script src="{{ asset('js/jquery-ui.min.js') }}"></script>
<script>
(function () {
    var list = document.querySelector('.sortable');
    var hidden = document.getElementById('hidden_order');
    var announcer = document.getElementById('ss-order-status');

    if (!list || !hidden) { return; }

    var POSITION = @json(__('Position :n of :total'));
    var MOVED = @json(__(':section moved to position :n of :total'));

    function rows() {
        return Array.prototype.slice.call(list.querySelectorAll('[data-ss-order-item]'));
    }

    function control(row, direction) {
        return row.querySelector('[data-ss-order-move="' + direction + '"]');
    }

    /* Longest placeholder first, so a shorter name can never be substituted inside a longer one. */
    function fill(template, values) {
        return Object.keys(values)
            .sort(function (a, b) { return b.length - a.length; })
            .reduce(function (out, key) {
                return out.split(':' + key).join(values[key]);
            }, template);
    }

    function setInert(button, inert) {
        if (!button) { return; }

        if (inert) {
            button.setAttribute('aria-disabled', 'true');
        } else {
            button.removeAttribute('aria-disabled');
        }
    }

    /*
     * ONE WRITER for the hidden input, the per-row position text and the two end stops.
     *
     * The persistence path is unchanged by this slice: the input is what the form posts to
     * updateOrder, which validates the tokens against the theme and upserts theme_customize_orders.
     * The keyboard path writes it through here, and so does the drag, which is the point. The
     * drag used to build the same string itself, and a third copy for the keyboard would have
     * left the positions and the end stops correct on one path and stale on the other.
     */
    function sync(moved) {
        var items = rows();
        var ids = [];

        items.forEach(function (row, index) {
            ids.push(row.getAttribute('data-id'));

            var position = row.querySelector('[data-ss-order-position]');

            if (position) {
                position.textContent = fill(POSITION, { n: index + 1, total: items.length });
            }

            setInert(control(row, 'up'), index === 0);
            setInert(control(row, 'down'), index === items.length - 1);
        });

        hidden.value = ids.join(',');

        if (!moved || !announcer) { return; }

        var at = items.indexOf(moved) + 1;

        /* Blanking first is what makes two moves in the same direction both audible: a live
           region does not re-announce an identical string. Same technique as announceLive in
           store-syria-ds.js. */
        announcer.textContent = '';
        window.setTimeout(function () {
            announcer.textContent = fill(MOVED, {
                section: moved.getAttribute('data-ss-order-label') || moved.getAttribute('data-id'),
                n: at,
                total: items.length
            });
        }, 50);
    }

    /* Delegated, so the handler survives the rows being reparented by either path. */
    list.addEventListener('click', function (event) {
        var button = event.target && event.target.closest
            ? event.target.closest('[data-ss-order-move]')
            : null;

        if (!button || !list.contains(button)) { return; }

        event.preventDefault();

        /* An inert end stop stays in the tab order on purpose, so Enter and Space still arrive
           here. The stylesheet's pointer-events rule only stops a mouse. */
        if (button.getAttribute('aria-disabled') === 'true') { return; }

        var row = button.closest('[data-ss-order-item]');

        if (!row) { return; }

        var direction = button.getAttribute('data-ss-order-move');
        var neighbour = direction === 'up' ? row.previousElementSibling : row.nextElementSibling;

        if (!neighbour) { return; }

        /*
         * UP AND DOWN ARE THE SAME IN ARABIC. Right-to-left mirrors the inline axis and leaves
         * the block axis alone, so there is deliberately no direction test anywhere in this file.
         * The table-column reorder in store-syria-ds.js does invert its keys, and is right to:
         * Left and Right are inline-axis keys and the first column paints on the right in Arabic.
         * Carrying that inversion onto a vertical list would be the same bug with the sign
         * flipped, visible only to an Arabic merchant.
         */
        if (direction === 'up') {
            list.insertBefore(row, neighbour);
        } else {
            list.insertBefore(neighbour, row);
        }

        sync(row);

        /* Reparenting a row is a remove and an insert, and the browser blurs on the remove, so
           without this the first press would be the last one that worked. Focus follows the
           ELEMENT rather than the position, so a second press moves the same section again. */
        var again = control(row, direction);

        if (again) { again.focus(); }
    });

    if (window.jQuery && window.jQuery.fn && window.jQuery.fn.sortable) {
        window.jQuery(list).sortable({
            stop: function (event, ui) {
                sync(ui && ui.item ? ui.item[0] : null);
            }
        });
        window.jQuery(list).disableSelection();
    }

    /* The initial hidden-input fill the page has always done, now through the one writer. */
    sync(null);
})();
</script>
@endpush
