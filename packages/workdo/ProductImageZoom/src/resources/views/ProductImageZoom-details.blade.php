@php
    $setting = getAdminAllSetting();
@endphp
@if (isset($setting['product_image_zoom_is_enable']) && $setting['product_image_zoom_is_enable'] == 'on')
    @push('page-script')
        <script src="{{ asset('packages/workdo/ProductImageZoom/src/resources/assets/js/zoom.js') }}"></script>
        <script>
            $(document).ready(function() {
                $('.main-image-slider .swiper-slide > div img').each(function() {
                    $(this).parent().zoom({
                        url: this.src,
                        magnify: 2,
                        on: 'mouseover'
                    });
                });
            });
        </script>
    @endpush
@endif
