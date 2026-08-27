<div class="col-md-4">
    <div class="input-group">
        <div class="input-group-prepend">
            <span class="input-group-text"><i class="ti ti-search"></i></span>
        </div>
        <input id="searchproductbarcode" type="text" data-url="{{ route('search.products.barcode') }}"
            placeholder="{{ __('Search by Barcode') }}" class="form-control pr-4 rounded-right">
    </div>
</div>
<script>
    $(document).ready(function() {
        if ($('#searchproductbarcode').length > 0) {
            var url = $('#searchproductbarcode').data('url');
            var store_id = $("#store_id").val();
            searchProductsSku(url, '', '0', store_id);
        }
        $(document).on('keyup', 'input#searchproductbarcode', function() {
            var url = $(this).data('url');
            var value = this.value;
            var cat = $('.cat-active').children().data('cat-id');
            var store_id = $("#store_id").val();
            searchProductsSku(url, value, cat, store_id);
        });

        function searchProductsSku(url, value, cat_id, store_id = '0') {
            var session_key = $('#empty_cart').val();
            $.ajax({
                type: 'GET',
                url: url,
                data: {
                    'search': value,
                    'cat_id': cat_id,
                    'store_id': store_id,
                    'session_key': session_key
                },
                success: function(data) {
                    $('#loader').fadeOut();
                    $('#product-listing').html(data);
                }
            });
        }
    });
</script>
