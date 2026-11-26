@php
    $Free_shipping = App\Models\ShippingMethod::freeShipping();
@endphp
{{Form::model($shippingMethod, array('route' => array('free-shipping.update', $shippingMethod->id))) }}

<div class="row">
    <div class="form-group col-md-12">
        {!! Form::label('method_name', __('Name'), ['class' => 'form-label']) !!}
        {!! Form::text('method_name', null, ['class' => 'form-control','disabled']) !!}
    </div>
    <div class="form-group col-md-12">
        {!! Form::label('FreeShipping', __('Free shipping requires'), ['class' => 'form-label']) !!}
        {!! Form::select('shipping_requires', $Free_shipping, null, ['class' => 'form-control','id' => 'FreeShipping','placeholder' => 'Select Free Shipping Requires','aria-describedby' => 'FreeShipping-help']) !!}
        {{-- GAP-060 / WS I4. `for="FreeShipping"` matches the select's EXPLICIT `id`, not the field
             name: konekt/html returns the given id ahead of the name, and the script at the foot of
             this file drives the minimum-amount row from `#FreeShipping`. The second sentence is
             what that script does -- options 3, 4 and 5 reveal `#mini_cost` and options 1 and 2
             hide it -- so the merchant is told why a field appears and disappears. --}}
        <x-ss.field-help for="FreeShipping">{{ __('Choose what a customer must do to earn free shipping. Some choices also ask for a minimum order amount.') }}</x-ss.field-help>
    </div>
    <div class="form-group col-md-12" id="mini_cost">
        {!! Form::label('cost', __('Minimum order amount'), ['class' => 'form-label']) !!}
        {!! Form::number('cost', null, ['class' => 'form-control','min'=>'0','step'=>'0.01']) !!}
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Update')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>

{!! Form::close() !!}

<script>
    $(document).ready(function () {
        $("#FreeShipping").trigger('change');
    });

    $(document).on('change', '#FreeShipping', function()
    {
        var conceptName = $('#FreeShipping').find(":selected").val();
        if(conceptName == '1')
        {
            $('#mini_cost').addClass('d-none');
        }
        if(conceptName == '2')
        {
            $('#mini_cost').addClass('d-none');
        }
        if(conceptName == '3')
        {
            $('#mini_cost').addClass('d-block');
            $('#mini_cost').removeClass('d-none');
        }
        if(conceptName == '4')
        {
            $('#mini_cost').addClass('d-block');
            $('#mini_cost').removeClass('d-none');
        }
        if(conceptName == '5')
        {
            $('#mini_cost').addClass('d-block');
            $('#mini_cost').removeClass('d-none');
        }
    });
</script>
