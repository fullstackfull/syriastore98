@php

    $url = url('/') . '/' . $productDetail->slug . '/home';
    $whatsapp_link = url('https://wa.me/?text=' . urlencode($url));
    $facebook = url('https://www.facebook.com/sharer.php?u=' . $url);
    $twitter = url('https://twitter.com/share?url=' . $url);
    $linkedin = url('https://www.linkedin.com/shareArticle?url=' . $url);
    $pinterest = url('https://pinterest.com/pin/create/bookmarklet/?media=&url=' . $url . '&description=');
    $mail = url('mailto:?subject=Share&body=Check out this site:' . $url);

@endphp

<div class="share-modal">
    <div class="share-content">
        {{ Form::label('share', __('Share this link via')) }}
        <ul class="share-icons">
            <a href="{{ $facebook }}"><i class="fab fa-facebook-f"></i></a>
            <a href="{{ $twitter }}"><i class="fab fa-twitter"></i></a>
            <a href="{{ $whatsapp_link }}"><i class="fab fa-whatsapp"></i></a>

            <a href="{{ $linkedin }}"><i class="fab fa-linkedin"></i></a>
            <a href="{{ $pinterest }}"><i class="fab fa-pinterest"></i></a>
            <a href="{{ $mail }}"><i class="fas fa-envelope"></i></a>
        </ul>

        {{ Form::label('share', __('Or copy link')) }}
        <div class="copy-text-wrp mt-2">
            <input type="text" class="form-control d-inline-block" id="myInput" value="{{ $url }}"
                readonly />
            <button type="button" class="copy-btn text-primary" id="button-addon2" onclick="myFunction()"><i
                    class="far fa-copy"></i> </button>
        </div>
    </div>
</div>
<script>
    function myFunction() {
        var copyText = document.getElementById("myInput");
        copyText.select();
        copyText.setSelectionRange(0, 99999)
        document.execCommand("copy");

    }
</script>
