@extends('layouts.app')

@section('page-title', __('Lottery Setting'))
@section('breadcrumb')
<li class="breadcrumb-item">{{ __('Lottery Setting') }}</li>
@endsection

@section('content')
    <div class="card mt-4">
        <div class="card-header">
            <div class="d-flex align-items-center justify-content-between flex-wrap g-2">
                <h3 class="h4 m-0">{{ isset($lotterySettings) && !empty($lotterySettings) ? __('Edit Lottery Prizes Setting') : __('Manage Lottery Prizes Setting') }}</h3>
            </div>
        </div>
        {{ Form::model(['route' => 'lotterysetting.store', 'method' => 'post', 'enctype' => 'multipart/form-data', 'class' => 'repeater', 'id' => 'lottery-data']) }}
            <div class="card-body">
                <div class="row">
                    <div class="form-group col-12">
                        <div id="lottery_repeater_basic">
                            <div data-repeater-list="lottery_repeater_basic">
                                @if (isset($lotterySettings) && count($lotterySettings) > 0)
                                    @foreach ($lotterySettings as $lotterySetting)
                                        <div class="row" data-repeater-item>
                                            <div class="form-group col-md-3">
                                                {{ Form::label('min_ticket', __('Minimum Ticket'), ['class' => 'col-form-label']) }}
                                                <input type="number" name="min_ticket" class="form-control minimum_quantity" placeholder="{{ __('Enter Max Ticket') }}" value="{{ $lotterySetting->min_ticket }}" required>
                                            </div>
                                            <div class="form-group col-md-3">
                                                {{ Form::label('max_ticket_per_user', __('Maximum Ticket Per User'), ['class' => 'col-form-label']) }}
                                                <input type="number" name="max_ticket_per_user" class="form-control maximum_quantity" placeholder="{{ __('Enter Max Ticket') }}" value="{{ $lotterySetting->max_ticket_per_user }}" required>
                                            </div>
                                            <div class="form-group col-md-3">
                                                {{ Form::label('total_ticket', __('Total Ticket'), ['class' => 'col-form-label']) }}
                                                <input type="number" name="total_ticket" value="{{ $lotterySetting->total_ticket }}" class="form-control" placeholder="{{ __('Enter Max Ticket') }}" required>
                                            </div>
                                            <div class="form-group col-md-3">
                                                {{ Form::label('number_of_winner', __('Number Of Winner'), ['class' => 'col-form-label']) }}
                                                <input type="number" name="number_of_winner" class="form-control" placeholder="{{ __('Enter Max Ticket') }}" value="{{ $lotterySetting->number_of_winner }}" required>
                                            </div>
                                            <div class="form-group col-md-3">
                                                {{ Form::label('ticket_price', __('Ticket Price'), ['class' => 'col-form-label']) }}
                                                <input type="number" name="ticket_price" class="form-control" placeholder="{{ __('Enter Ticket Price') }}" value="{{ $lotterySetting->ticket_price }}" required>
                                            </div>
                                            <div class="form-group form-switch p-0 col-md-3">
                                                {{ Form::label('refund_ticket', __('Refund Ticket Option'), ['class' => 'col-form-label']) }}<br>
                                                <input type="checkbox" class="form-check-input" name="refund_ticket" id="refund_ticket" {{ $lotterySetting->refund_ticket == 'on' ? 'checked' : '' }} />
                                            </div>
                                            <div class="form-group col-md-3">
                                                {{ Form::label('lottery_products', __('Select Product for Lottery'), ['class' => 'col-form-label']) }}
                                                {!! Form::select('lottery_products', $products, $lotterySetting->lottery_products, [
                                                'class' => 'form-control',
                                                'id' => 'lottery_products',
                                                'placeholder' => 'Select Product'
                                                ]) !!}
                                            </div>
                                            <div class="form-group col-md-12 bg-dark">
                                                <label for="products" class="form-label mt-2">{{ __('Manage Lottery Time') }}</label>
                                            </div>
                                            <div class="form-group col-md-3">
                                                {!! Form::label('', __('Start Date'), ['class' => 'form-label']) !!}
                                                {!! Form::date('lottery_start_date', $lotterySetting->lottery_start_date, ['class' => 'form-control', 'required']) !!}
                                            </div>
                                            <div class="form-group col-md-3">
                                                {!! Form::label('', __('Start Time'), ['class' => 'form-label']) !!}
                                                {!! Form::time('lottery_start_time', $lotterySetting->lottery_start_time, ['class' => 'form-control', 'required']) !!}
                                            </div>
                                            <div class="form-group col-md-3">
                                                {!! Form::label('', __('End Date'), ['class' => 'form-label']) !!}
                                                {!! Form::date('lottery_end_date', $lotterySetting->lottery_end_date, ['class' => 'form-control', 'required']) !!}
                                            </div>
                                            <div class="form-group col-md-3">
                                                {!! Form::label('', __('End Time'), ['class' => 'form-label']) !!}
                                                {!! Form::time('lottery_end_time', $lotterySetting->lottery_end_time, ['class' => 'form-control', 'required']) !!}
                                            </div>
                                            <div class="form-group text-end">
                                                <a href="javascript:;" data-repeater-delete class="btn btn-sm btn-light-danger">
                                                    <i class="ti ti-trash"></i>{{__('Delete')}}
                                                </a>
                                            </div>
                                            <hr>
                                        </div>
                                    @endforeach
                                @else
                                    <div class="row" data-repeater-item>
                                        <div class="form-group col-md-3">
                                            {{ Form::label('min_ticket', __('Minimum Ticket'), ['class' => 'col-form-label']) }}
                                            <input type="number" name="min_ticket" class="form-control minimum_quantity" placeholder="{{ __('Enter Min Ticket') }}" required>
                                        </div>
                                        <div class="form-group col-md-3">
                                            {{ Form::label('max_ticket_per_user', __('Maximum Ticket Per User'), ['class' => 'col-form-label']) }}
                                            <input type="number" name="max_ticket_per_user" class="form-control maximum_quantity" placeholder="{{ __('Enter Max Ticket') }}" required>
                                        </div>
                                        <div class="form-group col-md-3">
                                            {{ Form::label('total_ticket', __('Total Ticket'), ['class' => 'col-form-label']) }}
                                            <input type="number" name="total_ticket" class="form-control" placeholder="{{ __('Enter Max Ticket') }}" required>
                                        </div>
                                        <div class="form-group col-md-3">
                                            {{ Form::label('number_of_winner', __('Number Of Winner'), ['class' => 'col-form-label']) }}
                                            <input type="number" name="number_of_winner" class="form-control" placeholder="{{ __('Enter Max Ticket') }}" required>
                                        </div>
                                        <div class="form-group col-md-3">
                                            {{ Form::label('ticket_price', __('Ticket Price'), ['class' => 'col-form-label']) }}
                                            <input type="number" name="ticket_price" class="form-control" placeholder="{{ __('Enter Ticket Price') }}" required>
                                        </div>
                                        <div class="form-group form-switch p-0 col-md-3">
                                            {{ Form::label('refund_ticket', __('Refund Ticket Option'), ['class' => 'col-form-label']) }}<br>
                                            <input type="checkbox" class="form-check-input" name="refund_ticket" id="refund_ticket" />
                                        </div>
                                        <div class="form-group col-md-3">
                                            {{ Form::label('lottery_products', __('Select Product for Lottery'), ['class' => 'col-form-label']) }}
                                            {!! Form::select('lottery_products', $products, null, [
                                                'class' => 'form-control',
                                                'id' => 'lottery_products',
                                                'placeholder' => 'Select Product'
                                            ]) !!}
                                        </div>
                                        <div class="form-group col-md-12 bg-dark">
                                            <label for="products" class="form-label mt-2">{{ __('Manage Lottery Time') }}</label>
                                        </div>
                                        <div class="form-group col-md-3">
                                            {!! Form::label('', __('Start Date'), ['class' => 'form-label']) !!}
                                            {!! Form::date('lottery_start_date', null, ['class' => 'form-control', 'required']) !!}
                                        </div>
                                        <div class="form-group col-md-3">
                                            {!! Form::label('', __('Start Time'), ['class' => 'form-label']) !!}
                                            {!! Form::time('lottery_start_time', null, ['class' => 'form-control', 'required']) !!}
                                        </div>
                                        <div class="form-group col-md-3">
                                            {!! Form::label('', __('End Date'), ['class' => 'form-label']) !!}
                                            {!! Form::date('lottery_end_date', null, ['class' => 'form-control', 'required']) !!}
                                        </div>
                                        <div class="form-group col-md-3">
                                            {!! Form::label('', __('End Time'), ['class' => 'form-label']) !!}
                                            {!! Form::time('lottery_end_time', null, ['class' => 'form-control', 'required']) !!}
                                        </div>
                                        <div class="form-group text-end">
                                            <a href="javascript:;" data-repeater-delete class="btn btn-sm btn-light-danger">
                                                <i class="ti ti-trash"></i>{{__('Delete')}}
                                            </a>
                                        </div>
                                    </div>
                                    <hr>
                                @endif
                                <div id="error-message" style="color: red; display: none;"></div>
                            </div>
                            <div class="form-group text-end">
                                <a href="javascript:;" data-repeater-create class="btn btn-sm btn-light-primary">
                                    <i class="ti ti-plus"></i>{{__('Add Lottery Setting')}}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end flex-wrap">
                <button type="submit" id="submitLotteryForm" class="btn btn-primary ms-2">
                    {{ isset($lotterySettings) && !empty($lotterySettings) ? __('Update') : __('Create') }}
                </button>
            </div>
        {!! Form::close() !!}
    </div>
@endsection

@push('custom-script')
<script src="{{ asset('js/repeater.js') }}"></script>
<script>
    $('#lottery_repeater_basic').repeater({
        initEmpty: false,
        defaultValues: {
            'text-input': 'foo'
        },
        show: function() {
            $(this).slideDown();
        },

        hide: function(deleteElement) {
            $(this).slideUp(deleteElement);
        }
    }); 
    $(document).on("keyup change", ".minimum_quantity, .maximum_quantity", function() {
        validateMinMax($(this).closest('[data-repeater-item]')); 
    });

    function validateMinMax($repeaterItem) {
        var minTicket = $repeaterItem.find('.minimum_quantity').val();
        var maxTicket = $repeaterItem.find('.maximum_quantity').val();
        
        var $errorMessage = $repeaterItem.find('.error-message');

        if ($errorMessage.length === 0) {
            $errorMessage = $('<div class="error-message" style="color: red;"></div>').appendTo($repeaterItem);
        }
        if (minTicket && maxTicket) {
            if (parseInt(minTicket) > parseInt(maxTicket)) {
                $errorMessage.text("Minimum Ticket cannot be greater than Maximum Ticket.").show();
                return false;
            } else {
                $errorMessage.hide(); 
            }
        }
    }
</script>
@endpush