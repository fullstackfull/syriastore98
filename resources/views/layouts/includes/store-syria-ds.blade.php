{{--
    Store Syria design system — the one line a page needs to opt in:

        @push('css')
            @include('layouts.includes.store-syria-ds')
        @endpush

    It lives beside datatable-css.blade.php and is pulled in the same way.

    The script follows the host's dark layout. The host does not mark dark mode
    with a class: it swaps the stylesheet in #main-style-link to style-dark.css
    or rtl-style-dark.css (layouts/app.blade.php:102-118). This reads that same
    signal, so it cannot disagree with the layout. It runs inline in <head>,
    after #main-style-link exists and before the body paints, so there is no
    flash of light cards in a dark admin. It only sets data-ss-theme, which
    nothing but --ss-* tokens reads.

    Bump ?v= when store-syria-ds.css changes. The host's own sheets use time(),
    which re-downloads them on every request; a hand-set version caches.
--}}
<link rel="stylesheet" href="{{ asset('css/store-syria-ds.css') }}?v=p01b-2">
<script>
    (function () {
        var main = document.getElementById('main-style-link');
        if (main && /style-dark\.css/.test(main.getAttribute('href') || '')) {
            document.documentElement.setAttribute('data-ss-theme', 'dark');
        }
    })();
</script>
