@extends('layouts.app')

@section('page-title', __('Manage Lottery Result Data'))
@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Manage Lottery Result Data') }}</li>
@endsection
<style> 
    .cinema-seats {
        display: flex;
        flex-direction: column;
        gap: 10px;
        height: auto;
    }

    .cinema-row {
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
        max-width: 100%;
    }

    .seat {
        width: 80px;
        height: 40px;
        border-radius: 7px;
        background: linear-gradient(to top, #761818, #761818, #761818, #761818, #761818, #B54041, #F3686A);
        box-shadow: 0 0 5px rgba(0, 0, 0, 0.5);
        color: white;
    }

    .seats-select {
        background: linear-gradient(to top, #184176, #184176, #184176, #184176, #184176, #184176, #184176);
        color: white;
    }

    .seat span {
        width: 80px;
        height: 40px;
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .cinema-row .clear-row {
        background: transparent;
        border: none;
    }

    .cinema-seats::-webkit-scrollbar {
        width: 5px;
        height: 5px;
    }

    .cinema-seats::-webkit-scrollbar-track {
        box-shadow: inset 0 0 5px grey;
        border-radius: 10px;
    }

    .cinema-seats::-webkit-scrollbar-thumb {
        background: #10B77F;
        border-radius: 10px;
    }

    .cinema-seats::-webkit-scrollbar-thumb:hover {
        background: #10B77F;
    }

    .cinema-row .rowno {
        display: flex;
        align-items: center;
    }

    .cinema-row .rowno span {
        display: block;
        width: 60px;

    }
    .disabled {
        opacity: 0.5; 
        pointer-events: none;
    }

</style>
@section('content')
    <div class="card mt-4">
        <div class="card-header">
            <div class="d-flex align-items-center justify-content-between flex-wrap g-2">
                <h3 class="h4 m-0">{{ __('Manage All Lottery Result Data') }}</h3>
            </div>
        </div>
        {{ Form::open(['route' => 'lotteryresult.store', 'method' => 'post', 'enctype' => 'multipart/form-data', 'id'=> 'uploadForm']) }}
            <div class="card-body">
                <div class="row">
                    <div class="form-group col-12">
                        @foreach ($lotterySettings as $setting)
                            <div class="lottery-div">
                                @php
                                    $activeLotteries = [];
                                    $endedLotteries = [];
                                    $currentDateTime = \Carbon\Carbon::now();
                                    date_default_timezone_set('Asia/Kolkata');
                                    $lotteryStartDateTime = \Carbon\Carbon::parse($setting->lottery_start_date . ' ' . $setting->lottery_start_time);
                                    $lotteryEndDateTime = \Carbon\Carbon::parse($setting->lottery_end_date . ' ' . $setting->lottery_end_time);

                                    if ($lotteryEndDateTime < $lotteryStartDateTime) {
                                        $lotteryEndDateTime->addDay();
                                    }

                                    if ($currentDateTime->between($lotteryStartDateTime, $lotteryEndDateTime)) {
                                        $activeLotteries[] = $setting->id;
                                    } elseif ($currentDateTime->greaterThan($lotteryEndDateTime)) {
                                        $endedLotteries[] = $setting->id;
                                    }
                                @endphp
                                @if(in_array($setting->id, $endedLotteries))
                                    @php
                                        $tickets = Workdo\LotteryPrizes\app\Models\PurchaseTicket::where('lottery_id', $setting->id)->where('store_id',$setting->store_id)->get();
                                        $product = App\Models\Product::find($setting->lottery_products);
                                        $win_tickets = Workdo\LotteryPrizes\app\Models\LotteryCustomer::where('lottery_id', $setting->id)
                                        ->where('status', 'win')
                                        ->where('email_sent', 1)
                                        ->pluck('lottery_code');
                                    @endphp
                                    @if ($tickets->count() > 0)
                                        <h5>{{__('Lottery Product :')}} {{ $product->name }}</h5>
                                        @if ($win_tickets->isEmpty())
                                            <p class="max-winners">{{ __('Please select ') }} {{ $setting->number_of_winner }} {{ __('Unique codes of winner customers.') }}</p>
                                        @endif
                                        <div class="theatre">
                                            <div class="cinema-seats">
                                                <div class="cinema-row">
                                                    @foreach ($tickets as $ticket)
                                                    @php
                                                    $isWinningTicket = $win_tickets->contains($ticket->lottery_code);
                                                    @endphp
                                                    <div class="seat {{ $isWinningTicket ? '' : 'seats-select' }}">
                                                        <span>{{ $ticket->lottery_code }}</span>
                                                    </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>
                                        <hr>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <input type="hidden" name="selected_codes" id="selectedCodes">
            <div class="card-footer d-flex justify-content-end flex-wrap">
                <button type="submit" id="submitLotteryForm" class="btn btn-primary ms-2">
                    {{ isset($lotterySettings) && !empty($lotterySettings) ? __('Update') : __('Create') }}
                </button>
            </div>
        {!! Form::close() !!}
    </div>
@endsection
@push('custom-script')
    <script>
        $(document).ready(function() {
            var selectedCodes = [];
            var maxWinners = 0;
            
            function updateHiddenFields() {
                $('#selectedCodes').val(selectedCodes);
            }

            function disableOtherLotteries(currentLotteryDiv) {
                $('.lottery-div').not(currentLotteryDiv).addClass('disabled');
            }

            function enableOtherLotteries() {
                $('.lottery-div').removeClass('disabled');
            }

            if ($('.max-winners').length > 0) {
                $('.cinema-seats .seat.seats-select').on('click', function(e) {
                    var seatCode = $(this).text().trim();
                    var currentLotteryDiv = $(this).closest('.lottery-div');
                    if (maxWinners === 0) {
                        var maxWinnersText = currentLotteryDiv.find('.max-winners').text();
                        maxWinners = parseInt(maxWinnersText.match(/\d+/)[0]);
                    }
                    if ($(this).hasClass('seats-select')) {
                        if (selectedCodes.length < maxWinners) {
                            $(this).removeClass('seats-select');
                            selectedCodes.push(seatCode);
                            
                            disableOtherLotteries(currentLotteryDiv);
                        } else {
                            var msg = `You can only select up to ${maxWinners} codes.`;
                            show_toastr('{{ __("Error") }}', msg, 'error');
                        }
                    } else {
                        $(this).addClass('seats-select');
                        selectedCodes = selectedCodes.filter(code => code !== seatCode);

                        if (selectedCodes.length === 0) {
                            enableOtherLotteries();
                        }
                    }
                    updateHiddenFields();
                });
            }
            updateHiddenFields();
        });
    </script>
@endpush