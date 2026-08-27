<li class="dd-item nest-menu-item mb-2" data-id="{{ $item->id }}">

    <div class="dd-handle nest-menu-handle"></div>

    <div class="nest-menu-content d-flex justify-content-between">
        <div data-update="title" class="fw-medium overflow-hidden">{{ $item->menuItemable->name ?? ($item->name ??
            ($item->menuItemable->title ?? 'Unnamed')) }}</div>
        <div class="text-end"></div>
        <a class="show-item-details" href="#">
            <svg class="icon  svg-icon-ti-ti-chevron-down" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round">
                <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                <path d="M6 9l6 6l6 -6"></path>
            </svg>
        </a>
    </div>
    @if ($item->menu_itemable_type == 'App\Models\CustomLink')
    <div class="collapse p-3 border rounded" id="collapse_{{ $item->id }}">
        <div class="input-box p-3">
            <div class="form-group">
                <label for="linkText_{{ $item->id }}" class="f-w-600 w-100">{{ __('Link Text') }}</label>
                <input type="text" id="linkText_{{ $item->id }}" class="form-control flex-1"
                    value="{{ $item->menuItemable->title }}">
            </div>
            <div class="form-group">
                <label for="url_{{ $item->id }}" class="f-w-600 w-100">{{ __('URL') }}</label>
                <input type="url" id="url_{{ $item->id }}" class="form-control flex-1" value="{{ $item->menuItemable->url }}">
            </div>
            <div class="form-group">
                <label class="f-w-600 w-100">{{ __('Icon') }}</label>
                <div class="d-flex gap-2 align-items-center">
                    <select name="icon_type" class="form-select icon-select" id="iconSelect_{{ $item->id }}" data-item-id="{{ $item->id }}">
                        <option value="">{{ __('Select Icon Type') }}</option>
                        <option value="available" {{ ($item->icon_type && $item->icon_type == 'available') ? 'selected' : '' }}>{{ __('Available Icons') }}</option>
                        <option value="custom" {{ ($item->icon_type && $item->icon_type == 'custom') ? 'selected' : '' }}>{{ __('Custom Icon') }}</option>
                    </select>
                    <button type="button" class="btn btn-sm btn-outline-primary preview-icon" data-item-id="{{ $item->id }}">
                        <i class="ti ti-eye"></i>
                    </button>
                </div>
                <div class="custom-icon-input mt-2" id="customIconInput_{{ $item->id }}" style="display: none;">
                    <input name="icon" type="text" class="form-control" placeholder="{{ __('Enter SVG icon code or class name') }}" 
                           id="customIcon_{{ $item->id }}" value="{{ ($item->icon && !in_array($item->icon, ['home', 'user', 'shopping-cart', 'category', 'image', 'settings', 'menu', 'link'])) ? $item->icon : '' }}">
                    <small class="text-muted">{{ __('Enter icon class (e.g., ti ti-home) or SVG code') }}</small>
                </div>
            </div>
            <div class="form-group">
                <div class="custom-control custom-checkbox d-flex align-items-center gap-1">
                    <input type="checkbox" id="link_target{{ $item->id }}" class="custom-control-input"
                        {{ ($item->target == ' _blank' ? 'checked' : '' ) }}>

                    <label for="link_target{{ $item->id }}"
                        class="custom-control-label ">{{ __('Open in a new tab') }}</label>
                </div>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <a href="{{ $item->menuItemable->url }}" target="_blank" aria-label="{{ $item->menuItemable->name ?? ($item->name ??
                ($item->menuItemable->title ?? 'Unnamed')) }}" class="btn btn-sm btn-info">{{ __('Visit Link')
                    }}</a>
                <button class="btn btn-sm btn-danger delete-menu-item" data-id="{{ $item->id }}">{{ __('Delete') }}</button>
                <button class="btn btn-sm btn-primary update-custom-link"
                    data-id="{{ $item->id }}">{{ __('Update') }}</button>
            </div>
        </div>
    </div>
    @else
    <div class="collapse p-3 border rounded" id="collapse_{{ $item->id }}">
        <div class="form-group">
            <label class="f-w-600 w-100">{{ __('Icon') }}</label>
            <div class="d-flex gap-2 align-items-center">
                <select name="icon_type" class="form-select icon-select" id="iconSelect_{{ $item->id }}" data-item-id="{{ $item->id }}">
                    <option value="">{{ __('Select Icon Type') }}</option>
                    <option value="available" {{ ($item->icon_type && $item->icon_type == 'available') ? 'selected' : '' }}>{{ __('Available Icons') }}</option>
                    <option value="custom" {{ ($item->icon_type && $item->icon_type == 'custom') ? 'selected' : '' }}>{{ __('Custom Icon') }}</option>
                </select>
                <button type="button" class="btn btn-sm btn-outline-primary preview-icon" data-item-id="{{ $item->id }}">
                    <i class="ti ti-eye"></i>
                </button>
            </div>
            <div class="custom-icon-input mt-2" id="customIconInput_{{ $item->id }}" style="display: none;">
                <input name="icon" type="text" class="form-control" placeholder="{{ __('Enter SVG icon code or class name') }}" 
                        id="customIcon_{{ $item->id }}" value="{{ ($item->icon && !in_array($item->icon, ['home', 'user', 'shopping-cart', 'category', 'image', 'settings', 'menu', 'link'])) ? $item->icon : '' }}">
                <small class="text-muted">{{ __('Enter icon class (e.g., ti ti-home) or SVG code') }}</small>
            </div>
        </div>    
        <div class="form-group">
            <div class="custom-control custom-checkbox d-flex align-items-center gap-1">
                <input type="checkbox" id="link_target{{ $item->id }}" class="custom-control-input"
                    {{ ($item->target == ' _blank' ? 'checked' : '' ) }}>

                <label for="link_target{{ $item->id }}"
                    class="custom-control-label ">{{ __('Open in a new tab') }}</label>
            </div>
            <div class="text-end">
                <button class="btn btn-sm btn-danger delete-menu-item px-3" data-id="{{ $item->id }}">{{ __('Delete')
            }}</button>
            </div>
        </div>
    </div>
    @endif

    <div class="clearfix"></div>

    @if ($item->children && $item->children->count())
    <ol class="dd-list">
        @foreach ($item->children as $ckey => $child)
            @include('menu.item-nest', ['item' => $child, 'key' => $ckey])
        @endforeach
    </ol>
    @endif

</li>