<form method="post" action="{{ route('preordersetting.store') }}">
    @csrf
    <div class="row">
        <div class="form-group col-md-12">
            {{ Form::label('product_id',__('product'),array('class'=>'form-label')) }}
            {{ Form::select('product_id',$products,null,array('class'=>'form-control','placeholder'=>__('Select Product'),'required'=>'required', 'id' => 'productSelect')) }}
        </div>
        <div class="form-group col-md-12" id="variantField" style="display: none;">
            {{ Form::label('variant_id', __('Variant'), ['class' => 'form-label']) }}
            {{ Form::select('variant_id', [], null, ['class' => 'form-control', 'placeholder' => __('Select Variant'), 'id' => 'variantSelect']) }}
        </div>
        <div class="form-group col-md-12">
            {{Form::label('qty',__('Quantity'),array('class'=>'form-label'))}}
            {{Form::number('qty',null,array('class'=>'form-control','placeholder'=>__('Enter Quantity'), 'min' => '0', 'required' => 'required'))}}
        </div>
        <div class="form-group col-md-12">
            {!! Form::label('available_date', __('Available Date'), ['class' => 'form-label']) !!}
            {!! Form::date('available_date', null, ['class' => 'form-control', 'min' => now()->format('Y-m-d'), 'required']) !!}
        </div>
        <div class="form-group col-md-12">
            {!! Form::label('', __('Status'), ['class' => 'form-label']) !!}
            <div class="form-check form-switch">
                <input type="hidden" name="status" value="0">
                <input type="checkbox" class="form-check-input" name="status" id="status" value="1">
                <label class="form-check-label" for="status"></label>
            </div>
        </div>
        <div class="form-group col-12 d-flex justify-content-end col-form-label">
            <input type="button" value="{{__('Cancel')}}" class="btn btn-secondary btn-light" data-bs-dismiss="modal">
            <input type="submit" value="{{__('Create')}}" class="btn btn-primary ms-2">
        </div>
    </div>
</form>
<script>
    var baseUrl = "{{ url('/') }}";
    $(document).ready(function () {
    $('#productSelect').change(function () {
        const productId = $(this).val();

        if (productId) {
            $.ajax({
                url: baseUrl + '/preordersetting/get-product-variants/' + productId,
                type: 'GET',
                success: function (data) {
                    const variantSelect = $('#variantSelect');
                    variantSelect.empty();

                    if (data.length > 0) {
                        $('#variantField').show();
                        variantSelect.attr('required', 'required');
                        variantSelect.append(new Option('Select Variant', ''));
                        $.each(data, function (index, variant) {
                            variantSelect.append(new Option(variant.variant, variant.id));
                        });
                    } else {
                        variantSelect.removeAttr('required');
                        $('#variantField').hide();
                    }
                },
                error: function () {
                    console.error('Failed to retrieve variants');
                }
            });
        } else {
            $('#variantField').hide();
        }
    });
});
</script>