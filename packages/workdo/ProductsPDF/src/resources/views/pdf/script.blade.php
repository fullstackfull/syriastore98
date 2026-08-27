<script src="{{ asset('public/js/jquery-3.6.0.min.js') }}"></script>
<script type="text/javascript" src="{{ asset('js/html2pdf.bundle.min.js') }}"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.3.1/jspdf.umd.min.js"></script>
<script>
    function closeScript() {
        setTimeout(function () {
            window.open(window.location, '_self').close();
        }, 1000);
    }


    $(window).on('load', function () {
        const { jsPDF } = window.jspdf;

        var element = document.getElementById('boxes');
        var opt = {
            margin:       [0.5, 0.5, 1, 0.5], // top, left, bottom, right margins
            filename:     '{{ $product->name }}.pdf',
            image:        { type: 'jpeg', quality: 1 },
            html2canvas:  { scale: 4, dpi: 72, letterRendering: true },
            jsPDF:        { unit: 'in', format: 'A4' },
            pagebreak:    { mode: ['css', 'legacy'] },
            // custom header/footer function
            pdfCallback: function(pdf) {
                let totalPages = pdf.internal.getNumberOfPages();
                for (let i = 1; i <= totalPages; i++) {
                    pdf.setPage(i);
                    // Header
                    pdf.setFontSize(10);
                    pdf.text('Product PDF', 0.5, 0.5);

                    // Footer
                    pdf.setFontSize(10);
                    pdf.text('Page ' + String(i) + ' of ' + String(totalPages), 0.5, pdf.internal.pageSize.height - 0.5);
                }
            }
        };

        html2pdf().set(opt).from(element).save().then(closeScript);
    });
</script>
