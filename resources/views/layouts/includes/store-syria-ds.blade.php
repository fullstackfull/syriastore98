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
    nothing but --ss-* tokens reads, plus a marker so it never removes a value
    a page set for itself.

    Bump ?v= when store-syria-ds.css changes. The host's own sheets use time(),
    which re-downloads them on every request; a hand-set version caches.
--}}
<link rel="stylesheet" href="{{ asset('css/store-syria-ds.css') }}?v=p01b-3">
<script>
    (function () {
        var html = document.documentElement;
        var main = document.getElementById('main-style-link');
        if (!main) return;
        // The brand-settings page swaps this href live when the dark-layout
        // switch is toggled (partision/footerlink.blade.php), so follow it.
        // Only a value this script set is ever removed again.
        var sync = function () {
            if (/style-dark\.css/.test(main.getAttribute('href') || '')) {
                html.setAttribute('data-ss-theme', 'dark');
                html.setAttribute('data-ss-theme-source', 'host');
            } else if (html.getAttribute('data-ss-theme-source') === 'host') {
                html.removeAttribute('data-ss-theme');
                html.removeAttribute('data-ss-theme-source');
            }
        };
        sync();
        if (window.MutationObserver) {
            new MutationObserver(sync).observe(main, { attributes: true, attributeFilter: ['href'] });
        }
    })();
</script>
