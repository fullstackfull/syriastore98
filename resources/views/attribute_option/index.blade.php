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
    <li class="breadcrumb-item"><a href="{{ route('product-attributes.index') }}">{{ __('Attribute') }}</a></li>
    <li class="breadcrumb-item">{{ __('Attribute-options') }}</li>
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
                                {{ __('Drag an option to change the order customers see it in.') }}
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
                             two are not. --}}
                        <ul class="ss-sort-list sortable stage list-unstyled" role="list">
                            @foreach ($attribute_option as $option)
                                <li class="ss-sort-item" data-id="{{ $option->id }}">
                                    <span class="ss-sort-item__handle" aria-hidden="true"
                                        title="{{ __('Drag to reorder') }}">
                                        <i class="ti ti-arrows-maximize" data-feather="move"></i>
                                    </span>

                                    <span class="ss-sort-item__label ss-grow">{{ $option->terms }}</span>

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

    {{-- Announced to screen readers when the order is saved. Lives outside the
         list so re-ordering never moves or re-creates it. --}}
    <div class="ss-visually-hidden" role="status" aria-live="polite" id="ss-sort-status"></div>
@endsection

@push('custom-script')
    <script src="{{ asset('public/js/jquery-ui.min.js') }}"></script>
    <script>
        $(function () {
            var $status = $('#ss-sort-status');

            $(".sortable").sortable({
                // Lift the row being dragged so it reads as detached from the list.
                start: function (event, ui) {
                    ui.item.addClass('ss-sort-item--dragging');
                },
                stop: function (event, ui) {
                    ui.item.removeClass('ss-sort-item--dragging');

                    var terms = [];
                    $(this).find('li').each(function (index, data) {
                        terms[index] = $(data).attr('data-id');
                    });

                    $.ajax({
                        url: "{{ route('attribute-option') }}",
                        data: { terms: terms, _token: $('meta[name="csrf-token"]').attr('content') },
                        type: 'POST',
                        success: function (data) {
                            $('#loader').fadeOut();
                            $status.text(@json(__('Order saved.')));
                        },
                        error: function (xhr) {
                            // responseJSON is undefined on a network failure or
                            // an HTML error page; the original read .error off
                            // it unguarded and threw.
                            var json = xhr.responseJSON || {};
                            var message = json.error || @json(__('Order could not be saved.'));
                            $('#loader').fadeOut();
                            $status.text(message);
                            show_toastr('Error', message, 'error');
                        }
                    })
                }
            });
            $(".sortable").disableSelection();
        });
    </script>
@endpush
