@extends('layouts.app')
@section('page-title', __('Side Bar Customization'))
@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Side Bar Customization') }}</li>
@endsection
<link rel="stylesheet" href="{{ asset('assets/css/plugins/dragula.min.css') }}">
@section('action-button')
    @permission('Create Sidebar Menus')
        <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
            <a href="#" class="btn btn-sm btn-primary btn-badge" data-ajax-popup="true" data-size="lg" data-title="{{__('Add Side Menu')}}" data-url="{{ route('sidemenu.create') }}" data-bs-toggle="tooltip" title="{{ __('Add Side Menu') }}">
                <i class="ti ti-plus"></i>
            </a>
        </div>
   @endpermission
@endsection
<style>
    .product-bar-img {
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .product-bar-img .theme-main {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .product-bar-img .theme-main .center-text {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .module-card-handle .btn-group .btn {
        padding: 6px;
    }

    .module-card-handle .product-content {
        margin-top: 15px;
    }

    .module-card-handle .product-content p:not(:last-child) {
        margin-bottom: 10px !important;
    }

    .theme-avtar {
        background-color: #fff;
        box-shadow: -3px 4px 23px rgba(0, 0, 0, 0.1);
    }

    .product-content .btn {
        display: inline-table;
        padding: 10px 12px;
        border: 1px solid #d1d1d1;
        border-radius: 6px;
        text-align: center;
    }

    @media (min-width:1400px) and (max-width:1500px) {
        .event-sidebar-card .product-card {
            padding: 8px;
        }

        .event-sidebar-card {
            margin: -8px;
        }

        .module-card-handle .product-content h4 {
            font-size: 16px;
        }

        .product-bar-img .theme-main {
            gap: 10px;
        }

        .module-card-handle .product-content p {
            font-size: 10px !important;
        }
    }

    .menu.ui-sortable {
        padding: 0;
    }

    .menu.ui-sortable li {
        list-style: none;
    }

    .menu.ui-sortable>li>ul {
        padding: 0 0 0 15px;
    }

    .menu-item-bar {
        padding: 10px;
        border: 1px solid #d7d7d7;
        cursor: move;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }

    .menu-item-bar h6 {
        font-size: 14px;
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 1;
        overflow: hidden;
        word-break: break-all;
    }

    .menu-item-bar .edit-icon-wrp i {
        color: #060606;
    }

    .menu-item-bar .according-delete-input {
        padding: 0 !important;
    }

    #serialize_output {
        display: none;
    }

    body.dragging,
    body.dragging * {
        cursor: move !important;
    }

    .dragged {
        position: absolute;
        z-index: 1;
    }

    ol.example li.placeholder {
        position: relative;
    }

    ol.example li.placeholder:before {
        position: absolute;
    }

    #menuitem {
        list-style: none;
    }

    #menuitem ul {
        list-style: none;
    }

    .input-box {
        width: 75%;
        background: #fff;
        padding: 10px;
        box-sizing: border-box;
        margin-bottom: 5px;
    }

    .event-cards .product-card .product-content h5 {
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 1;
        overflow: hidden;
        word-break: break-all;
        ;
    }
</style>

@section('content')
<div class="row p-0 g-0">
    <!-- [ sample-page ] start -->
    <div class="col-sm-12">
        <div class="tab-content" id="pills-tabContent">
            <div class="tab-pane fade show active" id="pills-text" role="tabpanel" aria-labelledby="pills-text-tab">
                <div class="event-cards event-sidebar-card row px-0" id="text" data-toggle="dragula">
                    @foreach ($sortedModules as $module)
                    @php
                    if ($module->name == 'mobilescreensetting' && env('IS_MOBILE') != 'yes') {
                    continue;
                    }
                    @endphp
                    <div class="col-xxl-2 col-xl-3 col-md-4 col-sm-6 product-card All enabled" data-id="{{$module->id}}">
                        <div class="card module-card-handle {{ $module->is_enable == 'on' ? 'enable_module' : 'disable_module' }}">
                            <div class="product-img product-bar-img">
                                <div class="theme-main">
                                    <div class="theme-avtar">
                                        <span class="dash-micon img-user"><i class="ti ti-{{ $module->icon }}"></i></span>
                                        <span class="dash-mtext"></span>
                                    </div>
                                    <div class="center-text">
                                        <small class="text-muted">
                                            @if($module->is_enable == 'on')
                                            <span class="badge bg-success">{{__('Enable')}}</span>
                                            @else
                                            <span class="badge bg-danger">{{__('Disable')}}</span>
                                            @endif
                                        </small>
                                    </div>
                                </div>
                                <div class="checkbox-custom">
                                    <div class="btn-group card-option">
                                        {{--@permission('Edit Sidebar Menus')--}}
                                            <button class="btn" data-url="{{ route('sidemenu.edit', $module->id) }}" data-size="lg"
                                                data-ajax-popup="true" data-title="{{ __('Edit Side Menu') }}" data-bs-toggle="tooltip"
                                                title="{{ __('Edit') }}">
                                                <i class="ti ti-adjustments"></i>
                                            </button>
                                        {{--@endpermission
                                        @permission('Delete Sidebar Menus') --}}
                                            @if($module->link_type != 'route')
                                            {!! Form::open([
                                            'method' => 'DELETE',
                                            'route' => ['sidemenu.destroy', $module->id],
                                            'class' => 'd-inline mb-0',
                                            ]) !!}
                                                <button type="button" class="btn show_confirm" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
                                                    data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"
                                                    title="{{ __('Delete') }}">
                                                    <i class="ti ti-trash"></i>
                                                </button>
                                            {!! Form::close() !!}
                                            @endif
                                        {{--@endpermission --}}
                                    </div>
                                </div>
                            </div>
                            <div class="product-content">
                                <div class="product-content-top">
                                    <h5 class="text-capitalize">
                                        {{ $module->title }}
                                    </h5>
                                    <p class="text-muted text-sm mb-0">{{__('Created Date')}} : {{ $module->created_at->format('d-m-Y')}}
                                    </p>
                                </div>
                                <a href="{{ route('manage.child.menu',$module->id) }}" class="btn btn-outline-secondary w-100 mt-2">{{__('View Details')}}</a>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        <!-- [ sample-page ] end -->
    </div>
</div>
@endsection

@push('custom-script')
<script src="{{ asset('assets/js/plugins/dragula.min.js') }}"></script>
<script>
    ! function(a) {
        "use strict";
        var t = function() {
            this.$body = a("body")
        };
        t.prototype.init = function() {
            a('[data-toggle="dragula"]').each(function() {
                var t = a(this).data("containers"),
                    n = [];
                if (t)
                    for (var i = 0; i < t.length; i++) n.push(a("#" + t[i])[0]);
                else n = [a(this)[0]];
                var r = a(this).data("handleclass");

                r ? dragula(n, {
                    moves: function(el, container, handle) {
                        return handle.classList.contains(r);
                    }
                }) : dragula(n).on('drop', function(el, target, source, sibling) {

                    var sort = [];
                    $("#" + target.id + " > .product-card").each(function(key) {
                        sort[key] = $(this).attr('data-id');
                    });

                    $.ajax({
                        url: "{{ route('menu.order.update') }}",
                        data: {
                            order: sort,
                            _token: $('meta[name="csrf-token"]').attr('content')
                        },
                        type: 'POST',
                        success: function(data) {
                            updateSidebar();
                        },
                        error: function(data) {
                            data = data.responseJSON;
                        }
                    })

                });
            })
        }, a.Dragula = new t, a.Dragula.Constructor = t
    }(window.jQuery),
    function(a) {
        "use strict";
        a.Dragula.init();
    }(window.jQuery);

    function updateSidebar() {
        $.ajax({
            url: "{{ route('sidebar.update') }}",
            method: 'GET',
            success: function(response) {
                $('.dash-navbar').html(response.html);
            },
            error: function(error) {}
        });
    }
</script>
@endpush