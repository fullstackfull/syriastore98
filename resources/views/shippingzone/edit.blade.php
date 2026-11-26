@php
    $shippingMethod = \App\Models\ShippingZone::modules();
@endphp

{{Form::model($shippingzone, array('route' => array('shipping-zone.update', $shippingzone->id), 'method' => 'PUT')) }}

<div class="row">
    @if($shippingzone->zone_name != 'Locations not covered by your other zones')
        <div class="form-group col-md-12">
            {!! Form::label('zone_name', __('Name'), ['class' => 'form-label']) !!}
            {!! Form::text('zone_name', null, ['class' => 'form-control', "required"=>true]) !!}
        </div>
    @else
        <div class="form-group col-md-12">
            {!! Form::label('zone_name', __('Name'), ['class' => 'form-label']) !!}
            {!! Form::text('zone_name' , null, ['class' => 'form-control','readonly']) !!}
        </div>
    @endif
    @if($shippingzone->zone_name != 'Locations not covered by your other zones')
        <div class="form-group list_height_css col-md-12">
            <label>{{ __('Country') }}</label>
            {!! Form::label('country_id', __('Name'), ['class' => 'form-label']) !!}
            {!! Form::select('country_id', $country_option, null, ["class"=>"form-control country_change", "required"=>false]) !!}
        </div>
        <div class="form-group list_height_css col-md-12">
            <label>{{ __('State') }}</label>
            <div class="state">
            {!! Form::select('state_id', $state_option, null, ["class"=>"form-control state_name state_chage", "placeholder"=>"Select State", "required"=>false, 'data-select' => 0]) !!}
            </div>
        </div>
    @else
    @endif
    <div class="form-group col-md-12">
        {{ Form::label('shipping_method', __('Shipping Method'), ['class' => 'col-form-label']) }}
        {!! Form::select('shipping_method[]', $shippingMethod, $zone_selected, ['class' => 'form-control select2', 'id' => 'choices-multiple', 'multiple','data-role'=>'tagsinput','aria-describedby'=>'shipping_method-help']) !!}
        {{-- GAP-060 / WS I4. The same instruction as the create view, which is deliberate: the two
             forms are the same decision and a merchant who learns it once should not meet
             different words on edit. --}}
        <x-ss.field-help for="shipping_method">{{ __('Pick every shipping method a customer in this zone may choose at checkout. You can pick more than one.') }}</x-ss.field-help>
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Update')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>

{!! Form::close() !!}

<script>
    $(document).on('change', '.country_change', function(e) {
        var country_id = $(this).val();
        var data = {
            country_id: country_id
        }
        $.ajax({
            url: '{{ route('shipping-states.list') }}',
            method: 'POST',
            data: data,
            context: this,
            success: function(response) {
                $('#loader').fadeOut();
                $(this).closest('.row').find('.state_chage').html('').show();
                $(this).closest('.row').find('.nice-select.state_chage').remove();
                var state = $(this).closest('.row').find('.state_chage').attr('data-select');

                // P1-SEC-80: state names become <option> NODES (text), never concatenated markup.
                var $stateSelect = $(this).closest('.row').find('.state_chage').empty();
                $.each(response, function(i, item) {
                    $stateSelect.append($('<option>').val(i).text(item));
                });
                $(this).closest('.row').find('.state_chage').val(state);

                if (state != 0) {
                    $(this).closest('.row').find('.state_chage').trigger('change');
                }
               // getBillingdetail();
                $('select').niceSelect();
            }
        });
    });

</script>
