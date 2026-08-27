<style> 
    .edit-icon-wrp .btn-danger ,
    .edit-icon-wrp .btn-info {
        padding: 6px !important;
    } 
</style>
<li data-id="{{ $menu->id }}" data-parent-id="{{ $menu->parent_id ?? 0 }}" class="mb-2">
    <div class="accordion accordion-flush" id="">
        <div class="accordion-item">
            <h6 class="accordion-header mt-2 rounded menu-item-bar">
                {{ $menu->title }}
                <div class="edit-icon-wrp gap-2 d-flex align-items-center">
                    @permission('Edit Sidebar Menus')
                    <a href="#" class="btn btn-sm btn-badge btn-info" data-url="{{ route('submenus.edit', [$menu->id,$parent]) }}" data-size="lg"
                        data-ajax-popup="true" data-title="{{ __('Edit  Sub Menus') }}" data-bs-toggle="tooltip"
                        title="{{ __('Edit') }}">
                        <i class="ti ti-pencil"></i>
                    </a>
                    @endpermission
                    @permission('Delete Sidebar Menus')
                    {!! Form::open([
                        'method' => 'DELETE',
                        'route' => ['sidemenu.destroy', $menu->id],
                        'class' => 'd-inline mb-0',
                    ]) !!}
                    <a href="#" class="show_confirm btn btn-sm btn-danger" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
                        data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"
                        title="{{ __('Delete') }}">
                        <i class="ti ti-trash"></i>
                    </a>
                    {!! Form::close() !!}
                    @endpermission
                </div>
            </h6>
        </div>
    </div>
    <ul class="sortable">
        @if (isset($menu->children) && count($menu->children) > 0)
            @foreach ($menu->children as $child)
                @include('sidebar-customization::super-admin.menu_item', ['menu' => $child])
            @endforeach
        @endif
    </ul>
</li>
