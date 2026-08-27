<script src="{{ asset('js/jquery-ui.min.js') }}"></script>
<script src="{{ asset('js/repeater.js') }}"></script>
<script>
$(function() {
    "use restrict";
    
    // Destroy any existing summernote instances in repeaters to prevent duplicates
    $('.repeater-slider .summernote-simple').each(function() {
        if ($(this).next('.note-editor').length > 0) {
            $(this).summernote('destroy');
        }
    });
    // Icon picker functionality
    $(document).on('click', '.icon-picker-button', function(e) {
        e.stopPropagation();
        
        // Close any open dropdowns first
        $('.icon-dropdown').remove();
        
        var currentButton = $(this);
        var iconInput = currentButton.closest('.input-group').find('.icon-input');
        var iconPreview = currentButton.closest('.form-group').find('.icon-preview i');
        var formGroup = currentButton.closest('.form-group');
        
        // Create icon picker dropdown
        var dropdown = $('<div class="icon-dropdown position-absolute bg-white border rounded shadow p-2 mt-1" style="z-index: 1050; max-height: 300px; overflow-y: auto; width: 100%;"></div>');
        formGroup.append(dropdown);
        
        // Add search input
        var searchInput = $('<input type="text" class="form-control form-control-sm mb-2" placeholder="Search icons...">');
        dropdown.append(searchInput);
        
        // Add icons container
        var iconsContainer = $('<div class="icons-container d-flex flex-wrap"></div>');
        dropdown.append(iconsContainer);
        
        // Add Font Awesome icons
        var icons = [
            'fab fa-facebook-f', 'fab fa-twitter', 'fab fa-x-twitter', 'fab fa-instagram', 
            'fab fa-linkedin', 'fab fa-pinterest', 'fab fa-youtube', 'fab fa-tiktok',
            'fab fa-whatsapp', 'fab fa-telegram', 'fab fa-discord', 'fab fa-slack',
            'fab fa-github', 'fab fa-dribbble', 'fab fa-behance', 'fab fa-reddit',
            'fas fa-home', 'fas fa-envelope', 'fas fa-phone', 'fas fa-map-marker-alt',
            'fas fa-shopping-cart', 'fas fa-user', 'fas fa-heart', 'fas fa-star'
        ];
        
        // Get current icon value
        var currentIcon = iconInput.val();
        
        $.each(icons, function(i, icon) {
            var isSelected = (currentIcon === icon);
            var iconItem = $('<div class="icon-item p-2 border rounded text-center m-1" data-icon="' + icon + '" style="cursor: pointer; width: 80px; height: 80px; display: flex; flex-direction: column; justify-content: center; align-items: center;' + (isSelected ? ' border-primary border-2' : '') + '">' +
                '<i class="' + icon + '" style="font-size: 24px;"></i>' +
                '<div class="small mt-2" style="font-size: 10px; overflow: hidden; text-overflow: ellipsis; width: 100%;">' + icon + '</div>' +
                '</div>');
                
            iconItem.on('click', function() {
                var selectedIcon = $(this).data('icon');
                iconInput.val(selectedIcon);
                iconPreview.attr('class', selectedIcon);
                dropdown.remove();
            });
            
            iconsContainer.append(iconItem);
        });
        
        // Search functionality
        searchInput.on('keyup', function(e) {
            e.stopPropagation();
            var value = $(this).val().toLowerCase();
            dropdown.find('.icon-item').each(function() {
                var iconName = $(this).data('icon').toLowerCase();
                $(this).toggle(iconName.indexOf(value) > -1);
            });
        });
        
        // Focus search input
        setTimeout(function() {
            searchInput.focus();
        }, 100);
        
        // Close dropdown when clicking outside
        $(document).one('click', function() {
            dropdown.remove();
        });
    });
    
    // Initialize icon previews when repeater items are loaded
    function initIconPreviews() {
        $('.icon-input').each(function() {
            var iconClass = $(this).val();
            if (iconClass) {
                $(this).closest('.form-group').find('.icon-preview i').attr('class', iconClass);
            }
            
            // Fix label association
            var id = $(this).attr('id');
            if (id) {
                $(this).closest('.form-group').find('label.icon-field-label').attr('for', id);
            }
        });
    }
    
    // Initialize on page load
    initIconPreviews();
    
   var $repeater = $(".repeater-slider");
    $repeater.repeater({
        initEmpty: true,
        show: function() {
            var data = $(this).find('input,textarea,select').toArray();
            data.forEach(function(val) {
                var name = $(val).attr('name');
                var uniqueId = name ? name.replace(/\[|\]/g, '_') : 'field_' + Math.random().toString(36).substr(2, 9);
                $(val).attr('id', uniqueId);
                
                // Find the closest label within the same form group and update its for attribute
                $(val).closest('.form-group').find('label').attr('for', uniqueId);
            });
            var image = $(this).find('input[type="hidden"]').attr('name');
            // Handle image fields
            var hiddenInputs = $(this).find('input[type="hidden"]').filter(function() {
                return $(this).hasClass('selected-files');
            });

            hiddenInputs.each(function(index) {
                var inputName = $(this).attr('name');
                if (inputName) {
                    var convertedString = inputName.replace(/\[|\]/g, '_').replace(/_/g, '').replace(/_+/g, '_');
                    // Find the closest img element to this hidden input
                    $(this).closest('.choose-files').find('img').addClass(convertedString);
                }
            });
            
            // Initialize Summernote for new repeater items
            $(this).find('.summernote-simple').each(function() {
                var $textarea = $(this);
                if ($textarea.next('.note-editor').length > 0) {
                    $textarea.summernote('destroy');
                }
                $textarea.summernote({
                    dialogsInBody: true,
                    minHeight: 200,
                    toolbar: [
                        ['style', ['style']],
                        ['font', ['bold', 'italic', 'underline', 'strikethrough']],
                        ['list', ['ul', 'ol', 'paragraph']],
                        ['insert', ['link', 'unlink']],
                        ['view', ['codeview']]
                    ]
                });
            });
            
            $(this).slideDown();
            
            // Fix icon field labels specifically
            setTimeout(function() {
                $('.icon-input').each(function() {
                    var id = $(this).attr('id');
                    $(this).closest('.form-group').find('label.icon-field-label').attr('for', id);
                });
            }, 10);
        },
        hide: function(e) {
           $(this).slideUp(e);
        },
    });
    
    var input_repeater = ($('#repeaters-data').attr('data-json')) ? $('#repeaters-data').attr('data-json') : "[]";
    input_repeater = JSON.parse(input_repeater);
    if ($repeater.length > 0) {
        $repeater.setList(input_repeater);
        
        // Initialize Summernote for existing items after a short delay
        setTimeout(function() {
            $repeater.find('.summernote-simple').each(function() {
                var $textarea = $(this);
                // Destroy any existing instance
                if ($textarea.next('.note-editor').length > 0) {
                    $textarea.summernote('destroy');
                }
                // Wait a bit more for DOM to settle
                setTimeout(function() {
                    $textarea.summernote({
                        dialogsInBody: true,
                        minHeight: 200,
                        toolbar: [
                            ['style', ['style']],
                            ['font', ['bold', 'italic', 'underline', 'strikethrough']],
                            ['list', ['ul', 'ol', 'paragraph']],
                            ['insert', ['link', 'unlink']],
                            ['view', ['codeview']]
                        ]
                    });
                }, 50);
            });
        }, 200);
        
        $.each(input_repeater, function(key, item) {
            // Handle icon previews
            if (item.icon) {
                setTimeout(function() {
                    var iconInputs = $repeater.find('.icon-input');
                    iconInputs.each(function(index) {
                        if (index === key) {
                            $(this).val(item.icon);
                            $(this).closest('.form-group').find('.icon-preview i').attr('class', item.icon);
                        }
                    });
                }, 100);
            }
         
            // Handle images
            var imgSrc = item.image;
            var list = $repeater.find('[data-repeater-list]').attr('data-repeater-list');
            let liast = list.replace(/\[|\]/g, '_').replace(/_/g, '').replace(/_+/g, '_');
            var imgClasses = liast + key + 'image';
            if (imgClasses && $('.' + imgClasses).length > 0) {
                $.ajax({
                    url: '{{ route("theme.file.get") }}',
                    method: 'POST',
                    data: {
                        imgSrc: imgSrc
                    },
                    success: function(response) {
                        /** Update the image source after getting the URL from the server */
                        $('.' + imgClasses).attr('src', response);
                    },
                    error: function(xhr, status, error) {
                        try {
                            var res = JSON.parse(xhr.responseText);
                            show_toastr('Error', res.message || 'Something went wrong',
                                'error');
                        } catch (e) {
                            show_toastr('Error', 'Something went wrong', 'error');
                        }
                    }
                });
            }

            var backImgSrc = item.background_image;
            var backImgClasses = liast + key + 'backgroundimage';
            if (backImgClasses && $('.' + backImgClasses).length > 0) {
                $.ajax({
                    url: '{{ route("theme.file.get") }}',
                    method: 'POST',
                    data: {
                        imgSrc: backImgSrc
                    },
                    success: function(response) {
                        /** Update the image source after getting the URL from the server */
                        $('.' + backImgClasses).attr('src', response);
                    },
                    error: function(xhr, status, error) {
                        try {
                            var res = JSON.parse(xhr.responseText);
                            show_toastr('Error', res.message || 'Something went wrong',
                                'error');
                        } catch (e) {
                            show_toastr('Error', 'Something went wrong', 'error');
                        }
                    }
                });
            }
        });
    }

    var $repeater1 = $(".repeater1");
    $repeater1.repeater({
        initEmpty: true,
        show: function() {
            var data = $(this).find('input,textarea,select').toArray();
            data.forEach(function(val) {
                var name = $(val).attr('name');
                var uniqueId = name ? name.replace(/\[|\]/g, '_') : 'field_' + Math.random().toString(36).substr(2, 9);
                $(val).attr('id', uniqueId);
                $(val).closest('.form-group').find('label').attr('for', uniqueId);
            });
            var image = $(this).find('input[type="hidden"]').attr('name');
            var imagearray = $(this).find('input[type="hidden"]').attr('name').toArray();
            console.log('imagearray', imagearray)
            if (image && image.length > 0) {
                let convertedString = image.replace(/\[|\]/g, '_').replace(/_/g, '').replace(/_+/g,
                    '_');
                    console.log('convertedString', convertedString)
                var img = $(this).find('img').addClass(convertedString);
            }
            $(this).slideDown();
        },
        hide: function(e) {
            $(this).slideUp(e);
        },
    });

    var input_repeater = ($('#repeater-data').attr('data-json')) ? $('#repeater-data').attr('data-json') :
        "[]";
    input_repeater = JSON.parse(input_repeater);
    if ($repeater1.length > 0) {
        $repeater1.setList(input_repeater);
        $.each(input_repeater, function(key, item) {
            var imgSrc = item.image;
            var list = $repeater1.find('[data-repeater-list]').attr('data-repeater-list');
            let liast = list.replace(/\[|\]/g, '_').replace(/_/g, '').replace(/_+/g, '_');
            var imgClass = liast + key + 'image';
            if (imgClasses && $('.' + imgClasses).length > 0) {
                $.ajax({
                    url: '{{ route("theme.file.get") }}',
                    method: 'POST',
                    data: {
                        imgSrc: imgSrc
                    },
                    success: function(response) {
                        $('.' + imgClass).attr('src', response);
                    },
                    error: function(xhr, status, error) {
                        try {
                            var res = JSON.parse(xhr.responseText);
                            show_toastr('Error', res.message || 'Something went wrong', 'error');
                        } catch (e) {
                            show_toastr('Error', 'Something went wrong', 'error');
                        }
                    }
                });
            }
        });
    }
});
</script>