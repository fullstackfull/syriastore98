<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">

    <title>ProductCompare Module - {{ config('app.name', 'Laravel') }}</title>

    <meta name="description" content="{{ $description ?? '' }}">
    <meta name="keywords" content="{{ $keywords ?? '' }}">
    <meta name="author" content="{{ $author ?? '' }}">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    {{-- Vite CSS --}}
    {{-- {{ module_vite('build-productcompare', 'resources/assets/sass/app.scss') }} --}}
</head>

<body>
    @yield('content')

    {{-- Vite JS --}}
    {{-- {{ module_vite('build-productcompare', 'resources/assets/js/app.js') }} --}}
</body>
<script>
    $(document).on('click', '.compare-globaly', function(e) {
    var product_id = $(this).attr('product_id');
    var wishlist_type = $(this).attr('in_compare');

    // var wishlist_type = $('.wishlist_type').val();
    var isAuthenticated = "{{ auth('customers')->check() ? 'true' : 'false' }}";
    

    if (!isAuthenticated) {
        var message = "Please login to continue";
        window.location.href = loginUrl;
    } else {
        var data = {
            product_id: product_id,
            wishlist_type: wishlist_type,
        }
        $.ajax({
            url: addProductCompare,
            method: 'POST',
            data: data,
            context: this,
            success: function(response) {
                $('#loader').fadeOut();
                if (response.status == 0) {
                    show_toastr('Error', response.data.message, 'error');
                } else {
                    $(this).find('i').hasClass('ti ti-heart') ? $(this).find('i')
                        .removeClass('ti ti-heart') : $(this).find('i').addClass(
                            'ti ti-heart');
                    $(this).find('i').hasClass('fa fa-heart') ? $(this).find('i')
                        .removeClass('fa fa-heart') : $(this).find('i').addClass(
                            'fa fa-heart');
                    if (wishlist_type == 'add') {
                        $(this).attr('in_wishlist', 'remove');
                    }
                    if (wishlist_type == 'remove') {
                        $(this).attr('in_wishlist', 'add');
                    }

                    show_toastr('Success', response.data.message, 'success');
                }
            }
        });
    }
});
</script>
