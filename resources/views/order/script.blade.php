{{-- P2.5-WAVE-33. jQuery was loaded from `asset('js/jquery-3.6.0.min.js')` and 404d, so
     `$(window).on('load', ...)` below threw and THE SHIPPING LABEL NEVER BUILT ITS PDF. This
     partial is included by `order/shippinglabel.blade.php:330`, a standalone document with no
     layout, so nothing else supplied jQuery. MEASURED on the running application before the fix:

         window.jQuery = undefined   window.$ = undefined   window.html2pdf = function
         404: /public/js/jquery-3.6.0.min.js
         page error: ReferenceError: $ is not defined

     html2pdf was already on the correct path on the next line, which is why only jQuery was
     missing: the bug is the doubled `public/` segment, nothing else. --}}
<script src="{{ asset('js/jquery-3.6.0.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('js/html2pdf.bundle.min.js') }}"></script>
<script>
    function closeScript() {
        setTimeout(function () {
            window.open(window.location, '_self').close();
        }, 1000);
    }

    $(window).on('load', function () {
        var element = document.getElementById('boxes');
        var opt = {
            filename: '{{($order['id'])}}',
            image: {type: 'jpeg', quality: 1},
            html2canvas: {scale: 4, dpi: 72, letterRendering: true},
            jsPDF: {unit: 'in', format: 'A4'}
        };
        html2pdf().set(opt).from(element).save().then(closeScript);
    });
</script>
