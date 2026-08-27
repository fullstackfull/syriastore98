@push('css')
   @include('layouts.includes.datatable-css')
@endpush
@section('content')
    <div class="row">

        <div class="col-xl-12">
            <div class="tab-content" id="pills-tabContent">
                <div class="tab-pane fade @if(isset($country_active_tab) && ($country_active_tab == 'pills-country-tab')) show active @endif" id="pills-country" role="tabpanel"
                    aria-labelledby="pills-country-tab">
                    <div id="Country_Setting">
                        <div class="col-md-12">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <div class="row">
                                        <div class="col-6">
                                            <h5 class="mt-2">{{ __('Country Settings') }}</h5>
                                        </div>
                                        <div class="col-6 text-end ">
                                            <div class="">
                                                <a href="#" class="btn btn-sm btn-primary" data-ajax-popup="true"
                                                    data-size="md" data-title="{{ __('Create Country') }}"
                                                    data-url="{{ route('countries.create') }}" data-toggle="tooltip"
                                                    title="{{ __('Create Country') }}"
                                                    data-bs-original-title="{{ __('Create Country') }}">
                                                    <i class="ti ti-plus"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mt-3 g-0">
                                    <div class="card-body table-border-style">
                                    {{ $data['country_tab']->table(['width' => '100%']) }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade @if(isset($country_active_tab) && ($country_active_tab == 'pills-state-tab')) show active @endif" id="pills-state" role="tabpanel" aria-labelledby="pills-state-tab">
                    <div id="State_Setting">
                        <div class="card mb-3">
                            <div class="card-header">
                                <div class="row">
                                    <div class="col-6">
                                        <h5 class="mt-2">{{ __('State Settings') }}</h5>
                                    </div>
                                    <div class="col-6 text-end row">
                                        <form method="GET" action="{{ route('countries.index') }}" accept-charset="UTF-8"
                                            id="customer_submit">
                                            @csrf
                                            <input type="hidden" name="country_active_tab" value="pills-city-tab">
                                            <div class=" d-flex align-items-center justify-content-end">
                                                <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12 me-2">
                                                    <div class="btn-box">

                                                        
                                                    </div>
                                                </div>
                                                <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12 mr-2">
                                                    <div class="btn-box">

                                                      
                                                    </div>
                                                </div>
                                                <div class="col-xl-2 col-lg-3 col-md-6 col-sm-12 col-12 mr-2">
                                                    <a href="#" class="btn btn-sm btn-primary"
                                                        data-ajax-popup="true" data-size="md"
                                                        data-title="{{ __('Create State') }}"
                                                        data-url="{{ route('state.create') }}" data-toggle="tooltip"
                                                        title="{{ __('Create State') }}"
                                                        data-bs-original-title="{{ __('Create State') }}">
                                                        <i class="ti ti-plus"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body table-border-style">
                                <div class="table-responsive" id="stateDataTable">
                                    <table class="table mb-0  dataTable">
                                        <thead>
                                            <tr>
                                                <th>{{ __('Name') }}</th>
                                                <th class="text-center">{{ __('country') }}</th>
                                                <th class="text-end">{{ __('Action') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="font-style" >
                                           
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade @if(isset($country_active_tab) && ($country_active_tab == 'pills-city-tab')) show active @endif" id="pills-city" role="tabpanel" aria-labelledby="pills-city-tab">
                    <div id="City_Setting">
                        <div class="card mb-3">
                            <div class="card-header">
                                <div class="row">
                                    <div class="col-6">
                                        <h5 class="mt-2">{{ __('City Settings') }}</h5>
                                    </div>
                                    <div class="col-6 text-end row">
                                        <form method="GET" action="{{ route('countries.index') }}"
                                            accept-charset="UTF-8" id="state_filter_submit"> @csrf
                                            <input type="hidden" name="country_active_tab" value="pills-city-tab">
                                            <div class=" d-flex align-items-center justify-content-end">
                                                <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12 me-2">
                                                    <div class="btn-box">

                                                      
                                                    </div>
                                                </div>
                                                <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12 mr-2">
                                                    <div class="btn-box">

                                                    </div>
                                                </div>
                                                <div class="col-xl-2 col-lg-3 col-md-6 col-sm-12 col-12 mr-2">
                                                    <a href="#" class="btn btn-sm btn-primary"
                                                        data-ajax-popup="true" data-size="md"
                                                        data-title="{{ __('Create City') }}"
                                                        data-url="{{ route('city.create') }}" data-toggle="tooltip"
                                                        title="{{ __('Create City') }}"
                                                        data-bs-original-title="{{ __('Create City') }}">
                                                        <i class="ti ti-plus"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body table-border-style">
                                <div class="table-responsive" id="cityDataTable">
                                    <table class="table dataTable-6">
                                        <thead>
                                            <tr>
                                                <th>{{ __('Name') }}</th>
                                                <th class="text-center">{{ __('state') }}</th>
                                                <th class="text-end">{{ __('Action') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="font-style">
                                           
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@push('scripts')
   @include('layouts.includes.datatable-js')
   {{ $data['country_tab']->scripts() }}
@endpush