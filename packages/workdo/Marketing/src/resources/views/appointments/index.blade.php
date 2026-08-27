@extends('layouts.app')
@section('content')
@section('page-title')
    {{ __('Appointments') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item active" aria-current="page">{{ __('Appointments') }}</li>
@endsection
@section('title')
    {{ __('Appointments') }}
@endsection
@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="mt-2 " id="multiCollapseExample1">
                <div class="card">
                    <div class="card-body">
                        <div class="row align-items-center justify-content-end">
                            <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                <div class="btn-box">
                                    {{ Form::label('start_date', __('Start Date'), ['class' => 'form-label']) }}
                                    {{ Form::date('start_date', isset($_GET['start_date']) ? $_GET['start_date'] : '', ['class' => 'form-control ', 'placeholder' => 'Select Date']) }}
                                </div>
                            </div>
                            <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12 select-container">
                                <div class="btn-box">
                                    {{ Form::label('end_date', __('End Date'), ['class' => 'form-label']) }}
                                    {{ Form::date('end_date', isset($_GET['end_date']) ? $_GET['end_date'] : '', ['class' => 'form-control ', 'placeholder' => 'Select Date']) }}
                                </div>
                            </div>
                            <div class="col-auto">
                                <div class="row ">
                                    <div class="col-auto mt-4">
                                        <a class="btn btn-sm btn-primary  btn-badge" data-bs-toggle="tooltip"
                                            title="{{ __('Apply') }}" id="applyfilter"
                                            data-original-title="{{ __('apply') }}">
                                            <span class="btn-inner--icon"><i class="ti ti-search"></i></span>
                                        </a>
                                        <a href="#!" class="btn btn-sm btn-danger  btn-badge mr-1" data-bs-toggle="tooltip"
                                            title="{{ __('Reset') }}" id="clearfilter"
                                            data-original-title="{{ __('Reset') }}">
                                            <span class="btn-inner--icon"><i
                                                    class="ti ti-trash-off text-white-off "></i></span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
        <div class="row">
            <div class="col-xl-8">
                <div class="card">
                    <div class="card-header">
                        <div class="row">
                            <div class="col-lg-6">
                                <h5>{{ __('Calendar') }}</h5>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div id='calendar' class='calendar'></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4 card">
                <div class="card-body ">
                    <h4 class="">{{ __('Appointments') }}</h4>
                    <small>{{ __('This data is only for current month ') }}</small>
                    <ul class="event-cards list-group list-group-flush mt-3 w-100 ">
                        @foreach ($arrayJson as $appointment)
                            @php
                                $month = date('m', strtotime($appointment['start']));
                            @endphp
                            @if ($month == date('m'))
                                <li class="list-group-item card mb-3">
                                    <div class="row align-items-center justify-content-between">
                                        <div class="col-auto mb-3 mb-sm-0">
                                            <div class="d-flex align-items-center">
                                                <div class="theme-avtar">
                                                    <i class="ti ti-calendar"></i>
                                                </div>
                                                <div class="ms-3">
                                                    <h6 class="">{{ $appointment['title'] }}</h6>
                                                    <small class="text-muted mt-2">{{ $appointment['start'] }}</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-auto">

                                        </div>
                                    </div>
                                </li>
                            @endif
                            <input type="hidden" class="store_id" name="store_id" value="{{ $appointment['store_id'] }}">
                        @endforeach
                    </ul>
                </div>
            </div>
            <!-- [ sample-page ] end -->
        </div>
    </div>
@endsection

@push('custom-script')
    <script>
        $(document).ready(function() {
            get_calendar_data();
        });

        function get_calendar_data() {
            var is_live = $('#is_live :selected').val();
            var id = $(".store_id").val();
            var start_date = $('input[name=start_date]').val();
            var end_date = $('input[name=end_date]').val();

            $.ajax({
                url: "{{ route('get_appointment_data') }}",
                method: "POST",
                data: {
                    "id": id,
                    "_token": "{{ csrf_token() }}",
                    'is_live': is_live,
                    'start_date': start_date,
                    'end_date': end_date,

                },
                success: function(data) {
                    (function() {
                        var etitle;
                        var etype;
                        var etypeclass;
                        var calendar = new FullCalendar.Calendar(document.getElementById('calendar'), {
                            headerToolbar: {
                                left: 'prev,next today',
                                center: 'title',
                                right: 'dayGridMonth,timeGridWeek,timeGridDay'
                            },
                            buttonText: {
                                timeGridDay: "{{ __('Day') }}",
                                timeGridWeek: "{{ __('Week') }}",
                                dayGridMonth: "{{ __('Month') }}"
                            },
                            slotLabelFormat: {
                                hour: '2-digit',
                                minute: '2-digit',
                                hour12: false,
                            },
                            allDaySlot: false,
                            themeSystem: 'bootstrap',
                            navLinks: true,
                            droppable: true,
                            selectable: true,
                            selectMirror: true,
                            editable: true,
                            dayMaxEvents: true,
                            handleWindowResize: false,
                            height: 'auto',
                            events: data,
                            eventClick: function(e) {

                                e.jsEvent.preventDefault();
                                var title = e.title;
                                var url = e.el.href;
                                if (typeof url != 'undefined') {
                                    $("#commanModel .modal-title").html(e.event.title);
                                    $("#commanModel .modal-dialog").addClass('modal-md');
                                    $("#commanModel").modal('show');

                                    $.get(url, {}, function(data) {
                                        $('#commanModel .modal-body ').html(data);
                                    });
                                    return false;
                                }


                            }
                        });
                        calendar.render();
                    })();
                }
            });

        }
    </script>
@endpush
