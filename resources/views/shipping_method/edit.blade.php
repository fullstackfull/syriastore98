
{{Form::model($shippingMethod, array('route' => array('shipping-method.update', $shippingMethod->id), 'method' => 'PUT')) }}

@php
    $value = $shippingMethod['product_cost'];
    $product_cost = json_decode($value, true);
@endphp
<div class="row">
    <div class="form-group col-md-6">
        {!! Form::label('method_name', __('Name'), ['class' => 'form-label']) !!}
        {!! Form::text('method_name', null, ['class' => 'form-control','disabled']) !!}
    </div>
    <div class="form-group col-md-6">
        {!! Form::label('cost', __('Cost'), ['class' => 'form-label']) !!}
        {!! Form::number('cost', null, ['class' => 'form-control','min'=>'0','step'=>'0.01']) !!}
    </div>
    <div class="form-group col-md-12">
        {!! Form::label('calculation_type', __('Calculation type'), ['class' => 'form-label']) !!}<span class="ss-label__required" aria-hidden="true">*</span>
        {{-- GAP-060 / WS I4. The two option TEXTS are WorkDo's and are not wrapped in `__()`, so
             they render in English on an Arabic screen. That is a separate defect from this row --
             changing an option label changes what the control says, not what it explains -- and it
             is reported rather than fixed here. The instruction below is in both languages. --}}
        {{ Form::select('calculation_type',
        ['1'=>'Per class: Charge shipping for each shipping class individually',
        '2'=>'Per order: Charge shipping for the most expensive shipping class'],
        null, array('class' => 'form-control','required'=>'required','placeholder'=>'Select calculation type','aria-describedby'=>'calculation_type-help')) }}
        <x-ss.field-help for="calculation_type">{{ __('Per class charges for each shipping class in the basket. Per order charges once, for the most expensive class.') }}</x-ss.field-help>
    </div>
    @if($shippings_count != 0)
        @foreach ($shippings as $key => $shipping)
            <div class="form-group col-md-6">
                <label for="">"{{ $shipping }}" {{ __('shipping class cost')}}</label>
            </div>
            <div class="form-group col-md-6">
                @php
                    $cost = 0;
                    if(!empty($product_cost) && array_key_exists('product_cost',$product_cost) && array_key_exists($key,$product_cost['product_cost']))
                    {
                        $cost = $product_cost['product_cost'][$key];
                    }
                @endphp
                {{ Form::number('product_cost['.$key.']',$cost, ['class' => 'form-control','min'=>'0','step'=>'0.01']) }}
            </div>
        @endforeach
        <div class="form-group col-md-6">
            <label for="">{{ __('No shipping class cost')}}</label>
        </div>
        <div class="form-group col-md-6">
            {{ Form::number('product_no_cost',(!empty($product_cost) && array_key_exists('product_no_cost',$product_cost)) ? $product_cost["product_no_cost"] : 0, ['class' => 'form-control','step'=>'0.01']) }}
        </div>
    @endif
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Update')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>

{!! Form::close() !!}
