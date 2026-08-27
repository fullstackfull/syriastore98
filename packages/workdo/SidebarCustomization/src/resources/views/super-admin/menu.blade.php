@extends('layouts.app')
@section('page-title', __('Side Bar Menu'))
@section('breadcrumb')
<li class="breadcrumb-item"><a href="{{ route('sidemenu.index') }}">{{ __('Side Bar Menu') }}</a></li>
<li class="breadcrumb-item">{{ $sideMenu->name }}</li>
@endsection
@section('action-button')
    @permission('Create Sidebar Menus')
        <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
            <a href="#" class="btn btn-sm btn-primary" data-ajax-popup="true" data-size="lg" data-title="{{__('Add Sub Menus')}}"
                data-url="{{ route('submenus.create',$sideMenu->id) }}" data-toggle="tooltip" title="{{ __('Create Sub Menu') }}">
                <i class="ti ti-plus"></i>
            </a>
        </div>
    @endpermission
@endsection
<style>
    .menu-item-bar {
        padding: 5px 10px;
        border: 1px solid #d7d7d7;
        margin-bottom: 5px;
        width: 75%;
        width: 100%;
        cursor: move;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 15px !important;
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
    .theme-avtar {
        background-color: #fff;
        box-shadow: -3px 4px 23px rgba(0, 0, 0, 0.1);
    }
    .theme-main {
        display: inline-flex;
        align-items: center;
        gap: 10px;
    }
    .product-img h4 {
        margin: 10px 0 0 0;
    }
    .card-header {
        padding: 25px 15px;
    } 
    .menu.ui-sortable {
        max-width: 70%;
        width: 100%;
    }
    .accordion-flush .accordion-item h6{
        padding : 12px 15px !important;
    }
    .menu.ui-sortable li{
        list-style: none;
    }
</style>
@section('content')
    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-body">
                    <div class="container-fluid">
                        <div class="row" id="main-row">
                            <div class="col-sm-3">
                                <div class="card">
                                    <div class="card-header">
                                        <div class="product-img product-bar-img">
                                            <div class="theme-main">
                                                <div class="theme-avtar">
                                                    <span class="dash-micon img-user"><i class="ti ti-{{ $sideMenu->icon }}"></i></span>
                                                    <span class="dash-mtext"></span>
                                                </div>
                                                <div class="center-text">
                                                    <small class="text-muted">
                                                        @if($sideMenu->is_enable == 'on')
                                                        <span class="badge bg-success">{{__('Enable')}}</span>
                                                        @else
                                                        <span class="badge bg-danger">{{__('Disable')}}</span>
                                                        @endif
                                                    </small>
                                                </div>
                                            </div>
                                            <h4><span>{{ ucfirst($sideMenu->title) }}</span></h4>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" class="hidden" name="main_menu" value="{{$sideMenu->id}}">
                            <div class="col-sm-9 cat-view">
                                @if(!$menus)
                                    <h3><span>{{__('Manage Child Menu')}}</span></h3>
                                    <p><span>{{ __('No records found.....') }}</span></p>
                                @else
                                    <h4><span>{{ __('Manage Child Menu') }}</span></h4>
                                    <div id="menu-content">
                                        <div style="min-height: 240px;">
                                            <p>{{ __('Note: You can easily order change of menu blocks using drag & drop.') }}</p>
                                            <div class="accordion-body">
                                                <ul class="menu ui-sortable" id="menuitems">
                                                    @foreach ($menus as $menu)
                                                        @include('sidebar-customization::super-admin.menu_item', ['menu' => $menu,'parent' => $sideMenu->id])
                                                    @endforeach
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@push('custom-script')
<script src="{{ asset('js/jquery-sortable-min.js') }}"></script>
<script>
    jQuery(document).ready(function($) {
        var group = $("#menuitems").sortable({
            group: 'serialization',
            isValidTarget: function($item, container) {
                var depth = 1;
                var maxDepth = 10; 
                var children = $item.find('ol').first().find('li');
                
                if (container.el.parents('li').length > 1) {
                    depth += 1;
                }
                depth += container.el.parents('ol').length;

                while (children.length) {
                    depth++;
                    children = children.find('ol').first().find('li');
                }
                return depth <= maxDepth;
            },
            onDrop: function($item, container, _super) {
                var data = group.sortable("serialize").get();
                var jsonString = JSON.stringify(data, null, ' ');
                $('#serialize_output').text(jsonString); // Optional: for debugging
                updateParentIds(data);
                _super($item, container);
            }
        });

        function updateParentIds(data) {
            var items = [];
            $("#menuitems li").each(function(index) {
                var item = {
                    id: $(this).data('id'),
                    parent_id: $(this).closest('ul').closest('li').data('id') || 0,  // Parent ID is the closest ancestor li
                    order: index + 1  // The order based on the index of the item in the list
                };
                items.push(item);
            });

            var mainMenuValue = $('input[name="main_menu"]').val();

            $.ajax({
                url: "{{ route('sidechildmenu.update') }}", // Your update route
                type: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    items: items,
                    mainMenuValue: mainMenuValue
                },
                success: function(response) {
                    location.reload(); // Reload the page to see the updates
                },
                error: function(xhr, status, error) {
                    console.log("Error updating menu items:", error);
                }
            });
        }

        // Optional: Refresh sortable after some time
        setTimeout(function() {
            $('#menuitems').sortable('refresh');
        }, 100);
    });
</script>

@endpush