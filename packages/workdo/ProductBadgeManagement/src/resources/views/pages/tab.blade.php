<style>
    .pro-preview-box {
        width: 200px;
        height: 200px;
        display: flex;
        flex-direction: column;
        align-items: center;
        /* Center horizontally */
        justify-content: center;
        /* Center vertically */

        border: 1px solid #ddd;
        /* Your border styles */
        position: relative;
        overflow: hidden;
    }

    .badge-preview {
        position: absolute;
        top: 0;
        left: 50%;
        transform: translateX(-50%);
    }

    .img-badge-preview {
        position: absolute;
        top: 0;
        left: 50%;
        transform: translateX(-50%);
    }

    .badge {

        font-size: 14px;
        font-weight: bold;
    }

    .img-badge {
        margin-left: -100px;
        font-size: 14px;
        font-weight: bold;

    }
</style>

<li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
    <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'badge_setting' ? 'active' : '' }}"
        data-url="{{ route('badge-setting.form') }}" data-tab="badge_setting_setting" id="pills-badge_setting-tab"
        data-bs-toggle="pill" data-bs-target="#eco-badge_setting" type="button" role="tab"
        aria-controls="pills-badge_setting" aria-selected="false">
        {{ __('Badge Setting') }}
    </a>
</li>

@push('custom-script')
    <script>
        $(document).ready(function() {
            // Function to toggle visibility of badge settings
            function toggleBadgeSettings() {
                var textBadgeSetting = $('.textbadge-setting');
                var imageBadgeSetting = $('.imagebadge-setting');
                var textBadgeRadio = $('#textbadge-outlined');
                var imageBadgeRadio = $('#imagebadge-outlined');

                // Check if the Text Badge radio button is checked
                if (textBadgeRadio.is(':checked')) {
                    textBadgeSetting.removeClass('d-none');
                    imageBadgeSetting.addClass('d-none');
                } else if (imageBadgeRadio.is(':checked')) { // Check if the Image Badge radio button is checked
                    textBadgeSetting.addClass('d-none');
                    imageBadgeSetting.removeClass('d-none');
                }
            }

            // Event listener for radio button changes
            $(document).on('change', '.btn-check', function() {
                toggleBadgeSettings();
            });

            // Initially toggle based on the selected radio button
            toggleBadgeSettings();
        });

        $(document).ready(function() {
            var bgImageInput = $('#bgimage');
            var bgPreview = $('#bg-preview');
            var uploadedImage = $('#uploaded-image');

            bgImageInput.on('change', function() {
                var file = this.files[0];
                if (file) {
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        bgPreview.attr('src', e.target.result);
                        uploadedImage.removeClass('d-none');
                    }
                    reader.readAsDataURL(file);
                } else {
                    bgPreview.attr('src', '');
                    uploadedImage.addClass('d-none');
                }
            });
        });

        $(document).ready(function() {
            // Update badge styles based on input changes
            $('input[name="textcolor"], input[name="bgcolor"], input[name="badgetxt"], input[name="position_top"], input[name="position_left"], input[name="padding_top"], input[name="padding_bottom"], input[name="padding_left"], input[name="padding_right"], input[name="radius_top_left"], input[name="radius_bottom_left"], input[name="radius_top_right"], input[name="radius_bottom_right"], input[name="opacity"]')
                .on('input', function() {
                    updateBadgeStyles();
                });

            // Update badge styles based on input changes
            $(document).on('input',
                'input[name="textcolor"], input[name="bgcolor"], input[name="badgetxt"], input[name="position_top"], input[name="position_left"], input[name="padding_top"], input[name="padding_bottom"], input[name="padding_left"], input[name="padding_right"], input[name="radius_top_left"], input[name="radius_bottom_left"], input[name="radius_top_right"], input[name="radius_bottom_right"], input[name="opacity"]',
                function() {
                    updateBadgeStyles();
                });


        });

        function updateBadgeStyles() {
            const badgePreview = $('.textbadge-preview');
            const badge = badgePreview.find('.badge');
            if (badgePreview.length && badge.length) {
                const textcolor = $('input[name="textcolor"]').val();
                const bgcolor = $('input[name="bgcolor"]').val();
                const badgetxt = $('input[name="badgetxt"]').val();
                const position_top = $('input[name="position_top"]').val() + 'px'; // Add 'px'
                const position_left = $('input[name="position_left"]').val() + 'px'; // Add 'px'
                const padding_top = $('input[name="padding_top"]').val() + 'px';
                const padding_bottom = $('input[name="padding_bottom"]').val() + 'px';
                const padding_left = $('input[name="padding_left"]').val() + 'px';
                const padding_right = $('input[name="padding_right"]').val() + 'px';
                const radius_top_left = $('input[name="radius_top_left"]').val() + 'px';
                const radius_bottom_left = $('input[name="radius_bottom_left"]').val() + 'px';
                const radius_top_right = $('input[name="radius_top_right"]').val() + 'px';
                const radius_bottom_right = $('input[name="radius_bottom_right"]').val() + 'px';
                const opacity = $('input[name="opacity"]').val();
                const absolute = "absolute";
                badge.css({
                    backgroundColor: bgcolor,
                    color: textcolor,
                    top: position_top,
                    left: position_left,
                    paddingTop: padding_top,
                    paddingBottom: padding_bottom,
                    paddingLeft: padding_left,
                    paddingRight: padding_right,
                    borderTopLeftRadius: radius_top_left,
                    borderBottomLeftRadius: radius_bottom_left,
                    borderTopRightRadius: radius_top_right,
                    borderBottomRightRadius: radius_bottom_right,
                    opacity: opacity,
                    position: absolute
                });

                badge.find('span').text(badgetxt);
            }

        }

        // imageBadgeSetting

        $(document).ready(function() {
            // Update badge styles based on input changes
            $('input[name="img_position_top"], input[name="img_position_left"], input[name="img_padding_top"], input[name="img_padding_bottom"], input[name="img_padding_left"], input[name="img_padding_right"], input[name="img_radius_top_left"], input[name="img_radius_bottom_left"], input[name="img_radius_top_right"], input[name="img_radius_bottom_right"], input[name="img_opacity"], input[name="bgimage"], input[name="img_width"]')
                .on('input', function() {
                    imgupdateBadgeStyles();
                });

            // Update badge styles based on input changes
            $(document).on('input',
                'input[name="img_position_top"], input[name="img_position_left"], input[name="img_padding_top"], input[name="img_padding_bottom"], input[name="img_padding_left"], input[name="img_padding_right"], input[name="img_radius_top_left"], input[name="img_radius_bottom_left"], input[name="img_radius_top_right"], input[name="img_radius_bottom_right"], input[name="img_opacity"], input[name="bgimage"], input[name="img_width"]',
                function() {
                    imgupdateBadgeStyles();
                });


        });

        function imgupdateBadgeStyles() {
            const badgePreview = $('.img-badge-preview');
            const badge = badgePreview.find('.img-badge');
            const bgImage = badge.find('.img-bg-preview');
            
            if (badgePreview.length && badge.length && bgImage.length) {
                var position_top = $('input[name="img_position_top"]').val() || '0';
                var position_left = $('input[name="img_position_left"]').val() || '0';
                var padding_top = $('input[name="img_padding_top"]').val() || '0';
                var padding_bottom = $('input[name="img_padding_bottom"]').val() || '0';
                var padding_left = $('input[name="img_padding_left"]').val() || '0';
                var padding_right = $('input[name="img_padding_right"]').val() || '0';
                var radius_top_left = $('input[name="img_radius_top_left"]').val() || '0';
                var radius_bottom_left = $('input[name="img_radius_bottom_left"]').val() || '0';
                var radius_top_right = $('input[name="img_radius_top_right"]').val() || '0';
                var radius_bottom_right = $('input[name="img_radius_bottom_right"]').val() || '0';
                var opacity = $('input[name="img_opacity"]').val() || '1';
                var width = $('input[name="img_width"]').val() || 'auto';
                
                badge.css({
                    top: position_top + 'px',
                    left: position_left + 'px',
                    paddingTop: padding_top + 'px',
                    paddingBottom: padding_bottom + 'px',
                    paddingLeft: padding_left + 'px',
                    paddingRight: padding_right + 'px',
                    opacity: opacity,
                    position: 'absolute'
                });
                
                bgImage.css({
                    borderTopLeftRadius: radius_top_left + 'px',
                    borderBottomLeftRadius: radius_bottom_left + 'px',
                    borderTopRightRadius: radius_top_right + 'px',
                    borderBottomRightRadius: radius_bottom_right + 'px',
                    width: width === 'auto' ? width : width + 'px'
                });
            }
        }

        function previewImage(event) {
            var output = document.getElementById('quick-view-img');
            if (output) {
                output.src = URL.createObjectURL(event.target.files[0]);
                output.onload = function() {
                    URL.revokeObjectURL(output.src) // free memory
                }
            }
        }
    </script>
@endpush
