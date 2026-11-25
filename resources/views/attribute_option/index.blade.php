@extends('layouts.app')

{{--
    Pilot screen for the Store Syria web design system (Phase 01 / Sprint 01).

    Everything this screen could do before, it still does: the same three
    permission gates (Create, Edit and Delete Attributes Option), the same
    ajax-popup create/edit contract, the same DELETE form, and the same
    jQuery-UI sortable contract (a `.sortable` list of `li[data-id]`, posted to
    the `attribute-option` route). Only presentation and markup semantics
    changed.

    The design system is pulled in per-page through @push('css'), which
    layouts/app.blade.php renders at :176. No shared layout file is touched, so
    no other screen is affected.

    GAP-100 / P03-B-07 (2026-11-25) then added the keyboard path. See the block
    above the move controls below.
--}}

@section('page-title', __('Attribute-Option'))

@push('css')
    @include('layouts.includes.store-syria-ds')
@endpush

@section('action-button')
    @if ($attribute)
        @permission('Create Attributes Option')
            <div class="ss-scope ss-page-header__actions justify-content-md-end justify-content-center">
                <a href="#" class="ss-btn ss-btn--primary ss-btn--sm add_attribute" data-ajax-popup="true"
                    data-size="md" data-title="{{ __('Add Attribute Option') }}"
                    data-url="{{ route('product-attribute-option.create', $attribute->id) }}"
                    data-bs-toggle="tooltip" title="{{ __('Add Attribute Option') }}">
                    <i class="ti ti-plus" aria-hidden="true"></i>
                    <span>{{ __('Add Attribute Option') }}</span>
                </a>
            </div>
        @endpermission
    @endif
@endsection

@section('breadcrumb')
    <li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('product-attributes.index') }}">{{ __('Attribute') }}</a></li>
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Attribute-options') }}</li>
@endsection

@section('content')
    <div class="ss-scope">
        <div class="row justify-content-center">
            <div class="col-sm-12 col-md-10 col-xxl-8">
                <div class="ss-card">

                    <div class="ss-card__header">
                        <div class="ss-page-header__text">
                            <h2 class="ss-card__title">
                                {{ $attribute ? $attribute->name : __('Attribute-options') }}
                            </h2>
                            <p class="ss-page-header__subtitle">
                                {{-- REWORDED WITH THE KEYBOARD PATH. The old line read "Drag an
                                     option to change the order..." and was the accessibility
                                     failure in prose: it told a merchant who cannot drag to do
                                     the one thing that was not available to them. --}}
                                {{ __('Drag an option, or use its move buttons, to change the order customers see it in.') }}
                            </p>
                        </div>
                        @if (count($attribute_option) > 0)
                            {{-- The figure alone, not "N options": Arabic has six
                                 plural forms and an inline English plural string
                                 cannot be translated. The word is carried by the
                                 visually-hidden label instead. --}}
                            <span class="ss-badge ss-badge--brand">
                                {{ count($attribute_option) }}
                                <span class="ss-visually-hidden">{{ __('Options') }}</span>
                            </span>
                        @endif
                    </div>

                    @if (count($attribute_option) === 0)

                        {{-- The screen previously rendered an empty card with no
                             explanation when an attribute had no options yet. --}}
                        <div class="ss-empty">
                            <div class="ss-empty__icon" aria-hidden="true">
                                {{-- ti-list-check, not ti-list-details: the bundled
                                     tabler-icons.min.css does not ship the latter. --}}
                                <i class="ti ti-list-check"></i>
                            </div>
                            <h3 class="ss-empty__title">{{ __('No options yet') }}</h3>
                            <p class="ss-empty__body">
                                {{ __('Options are the values a customer picks from, such as Small, Medium and Large.') }}
                            </p>
                            @if ($attribute)
                                @permission('Create Attributes Option')
                                    <p class="ss-empty__body">{{ __('Add the first one to get started.') }}</p>
                                    <a href="#" class="ss-btn ss-btn--primary add_attribute" data-ajax-popup="true"
                                        data-size="md" data-title="{{ __('Add Attribute Option') }}"
                                        data-url="{{ route('product-attribute-option.create', $attribute->id) }}">
                                        <i class="ti ti-plus" aria-hidden="true"></i>
                                        <span>{{ __('Add Attribute Option') }}</span>
                                    </a>
                                @endpermission
                            @endif
                        </div>

                    @else

                        {{-- `.sortable` and `li[data-id]` are the jQuery-UI
                             contract the reorder script below depends on; the
                             class names around them are free to change, these
                             two are not.

                             `role="list"` is not redundant beside `list-unstyled`:
                             that class sets `list-style: none`, which drops list
                             semantics from the accessibility tree in Safari, and
                             the per-row position announcements are only meaningful
                             inside a list. The list therefore also needs a NAME --
                             "Position 1 of 4" otherwise describes a position in
                             nothing. --}}
                        <ul class="ss-sort-list sortable stage list-unstyled" role="list"
                            aria-label="{{ __('Attribute options, in the order they appear') }}">
                            @foreach ($attribute_option as $option)
                                <li class="ss-sort-item" data-id="{{ $option->id }}" data-ss-option-item
                                    data-ss-option-label="{{ $option->terms }}">
                                    <span class="ss-sort-item__handle" aria-hidden="true"
                                        title="{{ __('Drag to reorder') }}">
                                        <i class="ti ti-arrows-maximize" data-feather="move"></i>
                                    </span>

                                    <span class="ss-sort-item__label ss-grow">{{ $option->terms }}</span>

                                    {{-- THE POSITION IS A DESCRIPTION, NOT PART OF THE NAME. A
                                         control whose accessible name changes under the user is
                                         re-announced as a different control; a description can
                                         change freely. Both buttons point at this one element,
                                         and the script rewrites its text after every move, drag
                                         included. --}}
                                    <span class="ss-visually-hidden" data-ss-option-position
                                        id="ss-option-position-{{ $option->id }}">{{ __('Position :n of :total', ['n' => $loop->iteration, 'total' => $loop->count]) }}</span>

                                    {{-- GAP-100 / P03-B-07 -- THE KEYBOARD PATH, and the second
                                         of the two sortables the measurement covers.
                                         `PHASE-03-M4-THEME-EDITOR-PLAN.md:298` measured BOTH
                                         first-party sortables at `keydown` count 0 and called it
                                         a WCAG 2.1.1 and 2.5.7 failure. `5608bd54` answered
                                         `theme_customize/order.blade.php`; this is the other one,
                                         re-measured at 0 `keydown`, 0 `tabindex` and two buttons
                                         (Edit and Delete) immediately before this change.

                                         Two real buttons answer both criteria: Enter and Space
                                         drive them with no key convention to discover, and a
                                         pointer user can reorder without a dragging movement,
                                         which is what 2.5.7 asks for and an arrow-key handler
                                         alone would not give.

                                         THE END STOPS ARE `aria-disabled`, NOT `disabled`, and
                                         that is a focus decision rather than a style one.
                                         `disabled` takes the control out of the tab order, so
                                         moving a row to the last position would disable the very
                                         button that was pressed and drop focus to the document.
                                         The design system paints both states identically
                                         (store-syria-ds.css:891), so the script has to refuse an
                                         inert control itself.

                                         `type="button"` matters MORE here than on the sibling
                                         screen: the next span opens a DELETE form, so a button
                                         without a type inside this row is a submit button for it.

                                         PLAIN `title`, NO `data-bs-toggle="tooltip"`, unlike the
                                         Edit and Delete buttons beside it. A Bootstrap tooltip
                                         shows on FOCUS as well as hover, so it would pop on every
                                         tab stop; and the move handler reparents the row while
                                         the button inside it is focused, which strands the
                                         tooltip with no element to close it.

                                         The colours are tokens, not new ones: `ss-btn--ghost`
                                         with `ss-btn--sm ss-btn--icon` is the same combination
                                         the Edit button in this row already uses, so no new pair
                                         enters the contrast budget. --}}
                                    <span class="ss-table__actions">
                                        <button type="button" class="ss-btn ss-btn--ghost ss-btn--sm ss-btn--icon"
                                            data-ss-option-move="up"
                                            aria-describedby="ss-option-position-{{ $option->id }}"
                                            @if ($loop->first) aria-disabled="true" @endif
                                            title="{{ __('Move :option up', ['option' => $option->terms]) }}">
                                            <i class="ti ti-chevron-up" aria-hidden="true"></i>
                                            <span class="ss-visually-hidden">{{ __('Move :option up', ['option' => $option->terms]) }}</span>
                                        </button>

                                        <button type="button" class="ss-btn ss-btn--ghost ss-btn--sm ss-btn--icon"
                                            data-ss-option-move="down"
                                            aria-describedby="ss-option-position-{{ $option->id }}"
                                            @if ($loop->last) aria-disabled="true" @endif
                                            title="{{ __('Move :option down', ['option' => $option->terms]) }}">
                                            <i class="ti ti-chevron-down" aria-hidden="true"></i>
                                            <span class="ss-visually-hidden">{{ __('Move :option down', ['option' => $option->terms]) }}</span>
                                        </button>
                                    </span>

                                    <span class="ss-table__actions">
                                        @permission('Edit Attributes Option')
                                            <button type="button" class="ss-btn ss-btn--ghost ss-btn--sm ss-btn--icon"
                                                data-url="{{ route('product-attribute-option.edit', $option->id) }}"
                                                data-size="md" data-ajax-popup="true"
                                                data-title="{{ __('Edit Attribute Option') }}"
                                                data-bs-toggle="tooltip" title="{{ __('Edit') }}">
                                                <i class="ti ti-pencil" aria-hidden="true"></i>
                                                <span class="ss-visually-hidden">{{ __('Edit') }} {{ $option->terms }}</span>
                                            </button>
                                        @endpermission

                                        @permission('Delete Attributes Option')
                                            {!! Form::open([
                                                'method' => 'DELETE',
                                                'route' => ['product-attribute-option.destroy', $option->id],
                                                'class' => 'd-inline',
                                            ]) !!}
                                            <button type="button" class="ss-btn ss-btn--danger-quiet ss-btn--sm ss-btn--icon show_confirm"
                                                data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
                                                data-text="{{ __('This action can not be undone. Do you want to continue?') }}"
                                                data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"
                                                title="{{ __('Delete') }}">
                                                <i class="ti ti-trash" aria-hidden="true"></i>
                                                <span class="ss-visually-hidden">{{ __('Delete') }} {{ $option->terms }}</span>
                                            </button>
                                            {!! Form::close() !!}
                                        @endpermission
                                    </span>
                                </li>
                            @endforeach
                        </ul>

                        <div class="ss-card__footer">
                            <p class="ss-field__hint" style="margin:0">
                                <i class="ti ti-info-circle" aria-hidden="true"></i>
                                {{ __('You can easily change attribute option of attribute using drag & drop.') }}
                            </p>
                        </div>

                    @endif

                </div>
            </div>
        </div>
    </div>

    {{-- Announced to screen readers when the order changes. Lives outside the
         list so re-ordering never moves or re-creates it.

         THE ONE REGION, AND THE KEYBOARD PATH REUSES IT rather than adding its
         own. Two regions would be two elements a screen reader is watching and
         a race between the move announcement and the save announcement. --}}
    <div class="ss-visually-hidden" role="status" aria-live="polite" id="ss-sort-status"></div>
@endsection

@push('custom-script')
    {{-- F16: was asset('public/js/...'), which 404s. --}}
    <script src="{{ asset('js/jquery-ui.min.js') }}"></script>
    <script>
        $(function () {
            var list = document.querySelector('.sortable');
            var announcer = document.getElementById('ss-sort-status');

            if (!list) { return; }

            var POSITION = @json(__('Position :n of :total'));
            var MOVED = @json(__(':option moved to position :n of :total'));
            var FAILED = @json(__('Order could not be saved.'));

            /* AN ECHO EXPRESSION, NOT THE JSON DIRECTIVE THE THREE LINES ABOVE USE, and that is
               not a style choice. Laravel's json directive passes the four HEX flags and NOT
               JSON_UNESCAPED_SLASHES, so the URL renders with every slash escaped as its
               two-character form. The script still works -- JavaScript reads that back as the
               same string -- but the literal route then appears NOWHERE in the page, and
               PilotScreenContractTest asserts that it does. Measured: that test went red on
               exactly this line. The three strings above carry no slashes, so they are unaffected.

               (And the first draft of this comment spelled that directive out, with empty
               parentheses, inside this JavaScript block comment. Blade compiles a directive
               wherever it finds one -- a JS comment is not a Blade comment -- so it emitted
               json_encode with no first argument and the whole screen answered 500 with
               "Unclosed '(' does not match '}'". Thirteen tests, none of whose messages
               mentioned a comment. CLAUDE.md records this family of trap; this is a fresh
               member of it.) */
            var ENDPOINT = "{{ route('attribute-option') }}";

            /*
             * ONE REQUEST AT A TIME, AND THE LAST STATE ALWAYS WINS.
             *
             * This screen persists by AJAX, not by a hidden input and an explicit Save -- which
             * is the one structural difference from the sibling sortable. A drag is a single
             * gesture and so a single POST; a keyboard move is one POST PER PRESS, and a merchant
             * moving a row four places fires four. Each POST carries the WHOLE order, so two in
             * flight can be applied by the server in the wrong order and persist a list the
             * merchant never saw. The flags below serialise them and the resend reads the DOM
             * again rather than a captured snapshot, so whatever is on screen when the last
             * response lands is what is stored.
             */
            var inFlight = false;
            var queued = false;

            function rows() {
                return Array.prototype.slice.call(list.querySelectorAll('[data-ss-option-item]'));
            }

            function ids() {
                return rows().map(function (row) { return row.getAttribute('data-id'); });
            }

            function control(row, direction) {
                return row.querySelector('[data-ss-option-move="' + direction + '"]');
            }

            /*
             * ONE PASS, so a substituted value is never rescanned. The sibling screen replaces
             * longest-placeholder-first, which is enough there because its `:section` value is a
             * slug it derived itself. Here the value is `terms` -- free text the merchant typed --
             * so an option literally named ":total" would otherwise be substituted into and then
             * re-substituted. An unknown token is left alone rather than blanked, which is how a
             * mistranslation shows up as a visible `:token` instead of as a silently empty name.
             */
            function fill(template, values) {
                return String(template).replace(/:([a-zA-Z_][a-zA-Z0-9_]*)/g, function (match, key) {
                    return Object.prototype.hasOwnProperty.call(values, key)
                        ? String(values[key])
                        : match;
                });
            }

            function setInert(button, inert) {
                if (!button) { return; }

                if (inert) {
                    button.setAttribute('aria-disabled', 'true');
                } else {
                    button.removeAttribute('aria-disabled');
                }
            }

            /* Blanking first is what makes two moves in the same direction both audible: a live
               region does not re-announce an identical string. Same technique as announceLive in
               store-syria-ds.js. */
            function announce(message) {
                if (!announcer) { return; }

                announcer.textContent = '';
                window.setTimeout(function () { announcer.textContent = message; }, 50);
            }

            function persist() {
                if (inFlight) { queued = true; return; }

                inFlight = true;

                $.ajax({
                    url: ENDPOINT,
                    type: 'POST',
                    data: { terms: ids(), _token: $('meta[name="csrf-token"]').attr('content') },
                    error: function (xhr) {
                        // responseJSON is undefined on a network failure or an HTML error page;
                        // the original read .error off it unguarded and threw. The route answers
                        // 403 with {error} when a posted id is not this tenant's.
                        var json = xhr.responseJSON || {};
                        var message = json.error || FAILED;

                        announce(message);
                        show_toastr('Error', message, 'error');
                    },
                    complete: function () {
                        $('#loader').fadeOut();
                        inFlight = false;

                        if (queued) { queued = false; persist(); }
                    }
                });
            }

            /*
             * ONE WRITER for the persisted order, the per-row position text and the two end
             * stops. The keyboard path goes through here and so does the drag, which is the
             * point: before this slice the drag's `stop` handler built the posted id list itself,
             * and adding a second copy for the keyboard would have left the positions and the end
             * stops correct on one path and stale on the other.
             *
             * `moved` is null on page init -- the positions and end stops are computed, and
             * NOTHING is posted. A reorder POST on every page load would write the order the page
             * was already serving and make the route's refusal path reachable by merely looking
             * at the screen.
             *
             * THE SAVE IS ANNOUNCED ONLY WHEN IT FAILS. The move announcement below already tells
             * the user what happened, immediately, without waiting for a round trip; adding
             * "Order saved." on success would double every keypress in a polite region, and a
             * successful auto-save carries nothing the move announcement does not. A FAILURE
             * does, and it overrides.
             */
            function sync(moved) {
                var items = rows();

                items.forEach(function (row, index) {
                    var position = row.querySelector('[data-ss-option-position]');

                    if (position) {
                        position.textContent = fill(POSITION, { n: index + 1, total: items.length });
                    }

                    setInert(control(row, 'up'), index === 0);
                    setInert(control(row, 'down'), index === items.length - 1);
                });

                if (!moved) { return; }

                persist();

                announce(fill(MOVED, {
                    option: moved.getAttribute('data-ss-option-label') || moved.getAttribute('data-id'),
                    n: items.indexOf(moved) + 1,
                    total: items.length
                }));
            }

            /* Delegated, so the handler survives the rows being reparented by either path. A
               click on Edit or Delete finds no move control and falls straight through, leaving
               the host's own popup and delete-confirmation handlers untouched.

               THE PREVIOUS SENTENCE NAMED THOSE TWO HOOKS BY THEIR CLASS AND ATTRIBUTE, and that
               broke a census: this is a JavaScript comment, not a Blade one, so it is RENDERED,
               and PilotScreenContractTest counts the delete hook's class name over the whole page
               and asserts one per row. It read 6 for 5 rows. CLAUDE.md states this in both
               directions -- prose about the thing a census measures can satisfy it OR break it --
               and this is the second direction. Nothing in a rendered comment may spell a string
               the pilot contract counts. */
            list.addEventListener('click', function (event) {
                var button = event.target && event.target.closest
                    ? event.target.closest('[data-ss-option-move]')
                    : null;

                if (!button || !list.contains(button)) { return; }

                event.preventDefault();

                /* An inert end stop stays in the tab order on purpose, so Enter and Space still
                   arrive here. The stylesheet's pointer-events rule only stops a mouse. */
                if (button.getAttribute('aria-disabled') === 'true') { return; }

                var row = button.closest('[data-ss-option-item]');

                if (!row) { return; }

                var direction = button.getAttribute('data-ss-option-move');
                var neighbour = direction === 'up' ? row.previousElementSibling : row.nextElementSibling;

                if (!neighbour) { return; }

                /*
                 * UP AND DOWN ARE THE SAME IN ARABIC. Right-to-left mirrors the inline axis and
                 * leaves the block axis alone, so there is deliberately no direction test
                 * anywhere in this file. The table-column reorder in store-syria-ds.js does
                 * invert its keys, and is right to: Left and Right are inline-axis keys and the
                 * first column paints on the right in Arabic. Carrying that inversion onto a
                 * vertical list would be the same bug with the sign flipped, visible only to an
                 * Arabic merchant.
                 */
                if (direction === 'up') {
                    list.insertBefore(row, neighbour);
                } else {
                    list.insertBefore(neighbour, row);
                }

                sync(row);

                /* Reparenting a row is a remove and an insert, and the browser blurs on the
                   remove, so without this the first press would be the last one that worked.
                   Focus follows the ELEMENT rather than the position, so a second press moves the
                   same option again. */
                var again = control(row, direction);

                if (again) { again.focus(); }
            });

            $(list).sortable({
                // Lift the row being dragged so it reads as detached from the list.
                start: function (event, ui) {
                    ui.item.addClass('ss-sort-item--dragging');
                },
                stop: function (event, ui) {
                    ui.item.removeClass('ss-sort-item--dragging');

                    sync(ui && ui.item ? ui.item[0] : null);
                }
            });
            $(list).disableSelection();

            /* The end stops and the position text the page is serving, through the one writer. */
            sync(null);
        });
    </script>
@endpush
