<script src="{{ asset('assets/js/jquery.nestable.min.js') }}"></script>
<script>
"use strict";

$(document).ready(function () {
    initializeSortable();

    $(document).on("click", ".dd-item .nest-menu-content a.show-item-details", function (e) {
        e.preventDefault();
        const parentItem = $(this).closest('.dd-item');
        const collapseElement = parentItem.find('.collapse');
        
        $(this).toggleClass("active");
        parentItem.toggleClass("active");
        
        // Manually toggle the collapse
        if (collapseElement.is(':visible')) {
            collapseElement.hide();
        } else {
            collapseElement.show();
        }
    });

    handleSelectAll('#select-all-categories', '.category-item');
    handleSelectAll('#select-all-pages', '.page-item');
    handleSelectAll('#select-all-brands', '.brand-item');
    handleSelectAll('#select-all-products', '.product-item');
    handleSelectAll('#select-all-blogs', '.blog-item');
});

function initializeSortable() {
    $('#nestable').nestable({
        group: 1,
        maxDepth: 999
    });
}

function handleSelectAll(selectAllId, itemClass) {
    $(document).on('change', selectAllId, function () {
        $(itemClass).prop('checked', this.checked);
    });

    $(document).on('change', itemClass, function () {
        const allChecked = $(itemClass).length === $(itemClass + ':checked').length;
        $(selectAllId).prop('checked', allChecked);
    });
}

function addMenuItemToMenu(menuItem) {
    if (!menuItem) return;

    const itemTitle = menuItem.name || menuItem.title || 'Unnamed';
    const isCustomLink = menuItem.menu_itemable_type === 'App\\Models\\CustomLink';
    const url = menuItem.url || '#';
    const targetChecked = menuItem.target === '_blank' ? 'checked' : '';
    const iconType = menuItem.icon_type || '';
    const icon = menuItem.icon || '';

    let menuItemHtml = `
    <li class="dd-item nest-menu-item mb-2" data-id="${menuItem.id}">
        <div class="dd-handle nest-menu-handle"></div>
        <div class="nest-menu-content d-flex justify-content-between">
            <div data-update="title" class="fw-medium">${itemTitle}</div>
            <div class="text-end me-5"></div>
            <a class="show-item-details" href="#">
                <svg class="icon svg-icon-ti-ti-chevron-down" xmlns="http://www.w3.org/2000/svg"
                    width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                    <path d="M6 9l6 6l6 -6"></path>
                </svg>
            </a>
        </div>`;

    if (isCustomLink) {
        menuItemHtml += `
        <div class="collapse p-3 border rounded" id="collapse_${menuItem.id}">
            <div class="input-box p-3">
                <div class="form-group">
                    <label for="linkText_${menuItem.id}">{{ __('Link Text') }}</label>
                    <input type="text" id="linkText_${menuItem.id}" class="form-control" value="${itemTitle}">
                </div>
                <div class="form-group">
                    <label for="url_${menuItem.id}">{{ __('URL') }}</label>
                    <input type="url" id="url_${menuItem.id}" class="form-control" value="${url}">
                </div>
                <div class="form-group">
                    <label class="f-w-600 w-100">{{ __('Icon') }}</label>
                    <div class="d-flex gap-2 align-items-center">
                        <select name="icon_type" class="form-select icon-select" id="iconSelect_${menuItem.id}" data-item-id="${menuItem.id}">
                            <option value="">{{ __('Select Icon Type') }}</option>
                            <option value="available" ${iconType === 'available' ? 'selected' : ''}>{{ __('Available Icons') }}</option>
                            <option value="custom" ${iconType === 'custom' ? 'selected' : ''}>{{ __('Custom Icon') }}</option>
                        </select>
                        <button type="button" class="btn btn-sm btn-outline-primary preview-icon" data-item-id="${menuItem.id}">
                            <i class="ti ti-eye"></i>
                        </button>
                    </div>
                    <div class="custom-icon-input mt-2" id="customIconInput_${menuItem.id}" style="display: ${iconType === 'custom' ? 'block' : 'none'}">
                        <input name="icon" type="text" class="form-control" placeholder="{{ __('Enter SVG icon code or class name') }}" 
                               id="customIcon_${menuItem.id}" value="${icon}">
                        <small class="text-muted">{{ __('Enter icon class (e.g., ti ti-home) or SVG code') }}</small>
                    </div>
                </div>
                <div class="form-group">
                    <div class="custom-control custom-checkbox d-flex align-items-center gap-1">
                        <input type="checkbox" id="link_target${menuItem.id}" class="custom-control-input" ${targetChecked}>
                        <label for="link_target${menuItem.id}" class="custom-control-label">{{ __('Open in a new tab') }}</label>
                    </div>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <a href="${url}" target="_blank" class="btn btn-sm btn-info">{{ __('Visit Link') }}</a>
                    <button class="btn btn-sm btn-danger delete-menu-item" data-id="${menuItem.id}">{{ __('Delete') }}</button>
                    <button class="btn btn-sm btn-primary update-custom-link" data-id="${menuItem.id}">{{ __('Update') }}</button>
                </div>
            </div>
        </div>`;
    } else {
        menuItemHtml += `
        <div class="collapse p-3 border rounded" id="collapse_${menuItem.id}">
            <div class="form-group">
                <label class="f-w-600 w-100">{{ __('Icon') }}</label>
                <div class="d-flex gap-2 align-items-center">
                    <select name="icon_type" class="form-select icon-select" id="iconSelect_${menuItem.id}" data-item-id="${menuItem.id}">
                        <option value="">{{ __('Select Icon Type') }}</option>
                        <option value="available" ${iconType === 'available' ? 'selected' : ''}>{{ __('Available Icons') }}</option>
                        <option value="custom" ${iconType === 'custom' ? 'selected' : ''}>{{ __('Custom Icon') }}</option>
                    </select>
                    <button type="button" class="btn btn-sm btn-outline-primary preview-icon" data-item-id="${menuItem.id}">
                        <i class="ti ti-eye"></i>
                    </button>
                </div>
                <div class="custom-icon-input mt-2" id="customIconInput_${menuItem.id}" style="display: ${iconType === 'custom' ? 'block' : 'none'}">
                    <input name="icon" type="text" class="form-control" placeholder="{{ __('Enter SVG icon code or class name') }}" 
                           id="customIcon_${menuItem.id}" value="${icon}">
                    <small class="text-muted">{{ __('Enter icon class (e.g., ti ti-home) or SVG code') }}</small>
                </div>
            </div>
            <div class="form-group">
                <div class="custom-control custom-checkbox d-flex align-items-center gap-1">
                    <input type="checkbox" id="link_target${menuItem.id}" class="custom-control-input" ${targetChecked}>
                    <label for="link_target${menuItem.id}" class="custom-control-label">{{ __('Open in a new tab') }}</label>
                </div>
                <div class="text-end">
                    <button class="btn btn-sm btn-danger delete-menu-item px-3" data-id="${menuItem.id}">{{ __('Delete') }}</button>
                </div>
            </div>
        </div>`;
    }

    menuItemHtml += `</li>`;

    if ($('#nestable').length) {
        $('#nestable > ol').append(menuItemHtml);
    } else {
        $('.menu-accordion-wrp').html(` <div class="dd" id="nestable"><ol class="dd-list">${menuItemHtml}</ol></div>`);
        initializeSortable();
    }
}

function getMenuStructure() {
    let menuStructure = [];

    $("#nestable> ol > li").each(function(index) {
        let parentId = null;
        let menuItem = buildHierarchy($(this), parentId, index);
        menuStructure.push(menuItem);
    });

    return menuStructure;
}

function buildHierarchy(element, parentId, order) {
    let id = element.data("id");
    let target = element.find('input[type="checkbox"]:checked').length > 0 ? '_blank' : '_self';
    
    // Get icon data
    let iconType = element.find('.icon-select').val();
    let icon = element.find('input[name="icon"]').val();
    
    let children = [];
    element.children("ol").children("li").each(function(childIndex) {
        children.push(buildHierarchy($(this), id, childIndex));
    });

    return {
        id: id,
        parent_id: parentId,
        order: order,
        target: target,
        icon_type: iconType,
        icon: icon,
        children: children
    };
}

function collectCheckedValues(className) {
    let selectedValues = [];
    $(className + ':checked').each(function() {
        selectedValues.push($(this).val());
    });
    return selectedValues;
}

$(document).on('click', '#add-custom-link', function() {
    let customLinkUrl = $('#url').val();
    let customLinkText = $('#linktext').val();

    if (customLinkUrl && customLinkText) {
        $.ajax({
            type: "POST",
            url: '{{ route("add.customlink.menu", [ $menu->id]) }}',
            data: {
                _token: "{{ csrf_token() }}",
                url: customLinkUrl,
                link_text: customLinkText
            },
            success: function(response) {
                if (response.status === true) {
                    show_toastr('Success', '{{ __("Custom Link Added Successfully") }}',
                        'success');
                    addMenuItemToMenu(response.menuItems);
                    $('#url').val('');
                    $('#linktext').val('');
                }
            },
            error: function(xhr) {
                show_toastr('Error', xhr.responseJSON?.message || 'Error adding custom link', 'error');
            }
        });
    }
});

$(document).on("click", ".update-custom-link", function() {
    var linkId = $(this).data("id");
    var updatedText = $("#linkText_" + linkId).val().trim();
    var updatedUrl = $("#url_" + linkId).val().trim();
    if (updatedText && updatedUrl) {
        var updateUrl = '{{ route("update.customlink.menu", [ ":id"]) }}'.replace(':id',
            linkId);

        $.ajax({
            type: "POST",
            url: updateUrl,
            data: {
                _token: "{{ csrf_token() }}",
                link_text: updatedText,
                url: updatedUrl
            },
            success: function(response) {
                if (response.status === true) {
                    show_toastr('Success', response.message, 'success');
                    $(`li[data-id="${linkId}"] .menu-item-bar`).first().text(updatedText);
                }
            },
            error: function(xhr) {
                show_toastr('Error', xhr.responseJSON?.message || 'Error updating custom link', 'error');
            }
        });
    } else {
        toastr.warning("Please enter both URL and Link Text.");
    }
});


$(document).on('click', '#add-categories', function() {
    let selectedCategories = collectCheckedValues('.category-item');
    if (selectedCategories.length > 0) {
        $.ajax({
            type: "POST",
            url: '{{ route("add.category.menu", [ $menu->id]) }}',
            data: {
                _token: "{{ csrf_token() }}",
                category_ids: selectedCategories
            },
            success: function(response) {
                if (response.status === true) {
                    show_toastr('Success', response.message, 'success');
                    if (response.menuItems && response.menuItems.length > 0) {
                        response.menuItems.forEach(function(item) {
                            addMenuItemToMenu(item);
                        });
                    }
                    $('.category-item').prop('checked', false);
                    $('#select-all-categories').prop('checked', false);
                }
            },
            error: function(xhr) {
                show_toastr('Error', xhr.responseJSON?.message || 'Error adding categories', 'error');
            }
        });
    }
});

$(document).on('click', '#add-pages', function() {
    let selectedPages = collectCheckedValues('.page-item');
    if (selectedPages.length > 0) {
        $.ajax({
            type: "POST",
            url: '{{ route("add.page.menu", [ $menu->id]) }}',
            data: {
                _token: "{{ csrf_token() }}",
                pages_ids: selectedPages
            },
            success: function(response) {
                if (response.status === true) {
                    show_toastr('Success', response.message, 'success');
                    if (response.menuItems && response.menuItems.length > 0) {
                        response.menuItems.forEach(function(item) {
                            addMenuItemToMenu(item);
                        });
                    }
                    $('.page-item').prop('checked', false);
                    $('#select-all-pages').prop('checked', false);
                }
            },
            error: function(xhr) {
                show_toastr('Error', xhr.responseJSON?.message || 'Error adding pages', 'error');
            }
        });
    }
});

$(document).on('click', '#add-brands', function() {
    let selectedBrands = collectCheckedValues('.brand-item');
    if (selectedBrands.length > 0) {
        $.ajax({
            type: "POST",
            url: '{{ route("add.brand.menu", [ $menu->id]) }}',
            data: {
                _token: "{{ csrf_token() }}",
                brand_ids: selectedBrands
            },
            success: function(response) {
                if (response.status === true) {
                    show_toastr('Success', response.message, 'success');
                    if (response.menuItems && response.menuItems.length > 0) {
                        response.menuItems.forEach(function(item) {
                            addMenuItemToMenu(item);
                        });
                    }
                    $('.brand-item').prop('checked', false);
                    $('#select-all-brands').prop('checked', false);
                }
            },
            error: function(xhr) {
                show_toastr('Error', xhr.responseJSON?.message || 'Error adding brands', 'error');
            }
        });
    }
});

$(document).on('click', '#add-products', function() {
    let selectedProducts = collectCheckedValues('.product-item');
    if (selectedProducts.length > 0) {
        $.ajax({
            type: "POST",
            url: '{{ route("add.product.menu", [ $menu->id]) }}',
            data: {
                _token: "{{ csrf_token() }}",
                product_ids: selectedProducts
            },
            success: function(response) {
                if (response.status === true) {
                    show_toastr('Success', response.message, 'success');
                    if (response.menuItems && response.menuItems.length > 0) {
                        response.menuItems.forEach(function(item) {
                            addMenuItemToMenu(item);
                        });
                    }
                    $('.product-item').prop('checked', false);
                    $('#select-all-products').prop('checked', false);
                }
            },
            error: function(xhr) {
                show_toastr('Error', xhr.responseJSON?.message || 'Error adding products', 'error');
            }
        });
    }
});

$(document).on('click', '#add-blogs', function() {
    let selectedBlogs = collectCheckedValues('.blog-item');
    if (selectedBlogs.length > 0) {
        $.ajax({
            type: "POST",
            url: '{{ route("add.blog.menu", [ $menu->id]) }}',
            data: {
                _token: "{{ csrf_token() }}",
                blog_ids: selectedBlogs
            },
            success: function(response) {
                if (response.status === true) {
                    show_toastr('Success', response.message, 'success');
                    if (response.menuItems && response.menuItems.length > 0) {
                        response.menuItems.forEach(function(item) {
                            addMenuItemToMenu(item);
                        });
                    }
                    $('.blog-item').prop('checked', false);
                    $('#select-all-blogs').prop('checked', false);
                }
            },
            error: function(xhr) {
                show_toastr('Error', xhr.responseJSON?.message || 'Error adding blogs', 'error');
            }
        });
    }
});

$(document).on("click", ".delete-menu-item", function() {
    var menuItemId = $(this).data("id");
    var $menuItem = $(this).closest("li");

    var deleteUrl = '{{ route("menu.deleteItem", [ ":id"]) }}'.replace(':id', menuItemId);

    $.ajax({
        url: deleteUrl,
        type: 'DELETE',
        data: {
            _token: "{{ csrf_token() }}",
        },
        success: function(response) {
            if (response.status === true) {
                $menuItem.remove();
                show_toastr('Success', response.message, 'success');
            }
        },
        error: function(xhr) {
            show_toastr('Error', xhr.responseJSON?.message || 'Error deleting menu item', 'error');
        }
    });
});

$(document).on("click", "#saveMenu", function(event) {
    event.preventDefault();

    let menuData = getMenuStructure();
    let menuId = "{{ $menu->id }}";
    let menuName = $('#menu-name').val();

    $.ajax({
        url: '{{ route("menu.updateOrder", [ ":id"]) }}'.replace(':id', menuId),
        type: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            _token: "{{ csrf_token() }}",
            menuId: menuId,
            menuName: menuName,
            menu_structure: menuData
        }),
        success: function(response) {
            if (response.status === true) {
                show_toastr('Success', response.message, 'success');
            } else {
                show_toastr('Error', "{{ __('Failed to update menu') }}", 'error');
            }
        },
        error: function(xhr) {
            show_toastr('Error', xhr.responseJSON?.message || "{{ __('Failed to update menu') }}", 'error');
        }
    });
});

// Icon Selection Functionality
document.addEventListener('DOMContentLoaded', function() {
    // Available icons data
    const availableIcons = [
        { value: 'home', label: 'Home', icon: 'ti ti-home' },
        { value: 'user', label: 'User', icon: 'ti ti-user' },
        { value: 'shopping-cart', label: 'Cart', icon: 'ti ti-shopping-cart' },
        { value: 'category', label: 'Category', icon: 'ti ti-category' },
        { value: 'image', label: 'Image', icon: 'ti ti-photo' },
        { value: 'settings', label: 'Settings', icon: 'ti ti-settings' },
        { value: 'menu', label: 'Menu', icon: 'ti ti-menu' },
        { value: 'link', label: 'Link', icon: 'ti ti-link' }
    ];

    // Function to show available icons modal
    function showAvailableIconsModal(itemId) {
        const modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('Select Icon') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            ${availableIcons.map(icon => `
                                <div class="col-3 col-md-2">
                                    <div class="icon-option p-2 text-center border rounded cursor-pointer" 
                                         data-icon="${icon.value}" 
                                         data-icon-class="${icon.icon}"
                                         style="cursor: pointer;">
                                        <i class="${icon.icon} fs-4"></i>
                                        <div class="small mt-1">${icon.label}</div>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
        const modalInstance = new bootstrap.Modal(modal);
        modalInstance.show();

        // Handle icon selection
        modal.querySelectorAll('.icon-option').forEach(option => {
            option.addEventListener('click', function() {
                const iconValue = this.dataset.icon;
                const iconClass = this.dataset.iconClass;
                const iconInput = document.getElementById(`customIcon_${itemId}`);
                iconInput.value = iconClass;
                modalInstance.hide();
                modal.remove();
            });
        });

        modal.addEventListener('hidden.bs.modal', function() {
            modal.remove();
        });
    }

    // Use event delegation for icon select change
    $(document).on('change', '.icon-select', function() {
        const itemId = this.dataset.itemId;
        const customIconInput = document.getElementById(`customIconInput_${itemId}`);
        const iconInput = document.getElementById(`customIcon_${itemId}`);
        
        if (this.value === 'custom') {
            customIconInput.style.display = 'block';
            iconInput.value = '';
        } else if (this.value === 'available') {
            customIconInput.style.display = 'none';
            showAvailableIconsModal(itemId);
        } else {
            customIconInput.style.display = 'none';
            iconInput.value = '';
        }
    });

    // Use event delegation for icon preview
    $(document).on('click', '.preview-icon', function() {
        const itemId = this.dataset.itemId;
        const iconInput = document.getElementById(`customIcon_${itemId}`);
        const iconValue = iconInput.value;

        if (iconValue) {
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ __('Icon Preview') }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body text-center">
                            <div class="icon-preview" style="font-size: 48px;">
                                ${iconValue.includes('<svg') ? iconValue : `<i class="${iconValue}"></i>`}
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
            modal.addEventListener('hidden.bs.modal', function() {
                modal.remove();
            });
        }
    });
});

// Update the existing update-custom-link click handler to include icon
document.querySelectorAll('.update-custom-link').forEach(function(button) {
    button.addEventListener('click', function() {
        const itemId = this.dataset.itemId;
        const linkText = document.getElementById(`linkText_${itemId}`).value;
        const url = document.getElementById(`url_${itemId}`).value;
        const iconInput = document.getElementById(`customIcon_${itemId}`);
        const icon = iconInput.value;

        const data = {
            linkText: linkText,
            url: url,
            icon: icon
        };

        $.ajax({
            url: `/menu-item/${itemId}`,
            method: 'PUT',
            data: data,
            success: function(response) {
                if (response.status === true) {
                    show_toastr('Success', response.message, 'success');
                }
            },
            error: function(xhr) {
                show_toastr('Error', xhr.responseJSON?.message || 'Error updating menu item', 'error');
            }
        });
    });
});

</script>