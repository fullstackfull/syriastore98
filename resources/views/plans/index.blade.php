@extends('layouts.app')

@section('page-title')
    {{ __('Plan') }}
@endsection

@php
    $logo = asset(Storage::url('uploads/profile/'));
@endphp

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Plan') }}</li>
@endsection

@section('action-button')
    @permission('Create Plan')
    @if (auth()->user()->type == 'super admin')
        <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
            <a href="{{ route('plan.create') }}" class="btn btn-sm btn-badge btn-primary" data-bs-toggle="tooltip"
            title="{{ __('Create New Plan') }}">
                <i class="ti ti-plus"></i>
            </a>
        </div>
    @endif
    @endpermission
@endsection

@section('content')

    <div class="row plan_card_wrp mb-4">
        @foreach ($plans as $plan)
            <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6 col-12">
                <div class="plan_card">
                    <div class="card price-card price-1 wow animate__fadeInUp" data-wow-delay="0.2s"
                        style="visibility: visible; animation-delay: 0.2s; animation-name: fadeInUp;">
                        <div class="card-body">
                            <div class="d-flex flex-wrap justify-content-between">
                                <span class="price-badge text-dark f-w-600 text-start f-16 ps-0 mb-2">{{ $plan->name }}</span>
                                @if (\Auth::user()->type == 'admin' && \Auth::user()->plan_id == $plan->id)
                                    <div class="product-content-top d-flex flex-row-reverse m-0 p-0 plan-active-status btn-primary">
                                        <span class="btn btn-sm btn-icon badges bg-success">
                                            <span class="m-2">{{ __('Active') }}</span>
                                        </span>
                                    </div>
                                @endif

                                <div class="d-flex flex-row-reverse gap-1 active-tag mb-2 align-items-center">
                                    @if (\Auth::user()->type != 'admin')
                                        @permission('Edit Plan')
                                        <div class="d-inline-flex align-items-center">
                                            <a class="btn btn-sm btn-info"
                                                href="{{ route('plan.edit', $plan->id) }}"
                                                data-bs-toggle="tooltip"
                                                title="{{ __('Edit') }}">
                                                <i class="ti ti-pencil" ></i>
                                            </a>
                                        </div>
                                        @endpermission

                                        @if ($plan->price > 0)
                                            <div class="">
                                                {!! Form::open([
                                                    'method' => 'DELETE',
                                                    'route' => ['plan.destroy', $plan->id],
                                                    'id' => 'delete-form-' . $plan->id,
                                                ]) !!}
                                                <a href="#!"
                                                    class="bs-pass-para btn btn-danger show_confirm btn-icon btn-sm" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
                                                    data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
                                                    title="{{ __('Delete') }}">
                                                    <i class="ti ti-trash"></i>
                                                </a>
                                                {!! Form::close() !!}
                                            </div>
                                        @endif
                                    @endif
                                    @if (\Auth::user()->type == 'super admin' && $plan->price > 0)
                                        <div class="form-check form-switch custom-switch-v1 float-end">
                                            <input type="checkbox" name="plan_disable"
                                                class="form-check-input input-primary is_disable" value="1"
                                                data-id='{{ $plan->id }}' data-name="{{ __('plan') }}" data-bs-toggle="tooltip"
                                                title="{{ __('Enable/Disable') }}"
                                                {{ $plan->is_disable == 1 ? 'checked' : '' }}>
                                            <label class="form-check-label" for="plan_disable"></label>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <h3 class="mb-3 f-w-600 text-start text-primary">
                                {{ !empty($setting['CURRENCY']) ? $setting['CURRENCY'] : '$' }}{{ $plan->price . ' / ' . (\App\Models\Plan::getDurationsArr()[$plan->duration]) }}</small>
                            </h3>
                            @if ($plan->price > 0 && $plan->trial != 0)
                                <p class="mb-0">
                                    {{ __('Free Trial Days : ') . __($plan->trial_days ? $plan->trial_days : 0) }}<br />
                                </p>
                            @endif
                            @if ($plan->description)
                                <p class="text-start">
                                    {!! strip_tags($plan->description) !!}<br />
                                </p>
                            @endif
                            <div class="row mb-0">
                                <div class="col-4 text-start">
                                    @if ($plan->max_products == '-1')
                                        <span class="h5 mb-0">{{ __('Unlimited') }}</span>
                                    @else
                                        <span class="h5 mb-0">{{ $plan->max_products }}</span>
                                    @endif
                                    <span class="d-block text-sm">{{ __('Products') }}</span>
                                </div>
                                <div class="col-4 text-start">
                                    <span class="h5 mb-0">
                                        @if ($plan->max_stores == '-1')
                                            <span class="h5 mb-0">{{ __('Unlimited') }}</span>
                                        @else
                                            <span class="h5 mb-0">{{ $plan->max_stores }}</span>
                                        @endif
                                    </span>
                                    <span class="d-block text-sm">{{ __('Store') }}</span>
                                </div>
                                <div class="col-4 text-start">
                                    <span class="h5 mb-0">
                                        @if ($plan->max_users == '-1')
                                            <span class="h5 mb-0">{{ __('Unlimited') }}</span>
                                        @else
                                            <span class="h5 mb-0">{{ $plan->max_users }}</span>
                                        @endif
                                    </span>
                                    <span class="d-block text-sm">{{ __('Users') }}</span>
                                </div>
                            </div>
                            <div class="plan-card-detail d-flex text-center">
                                <ul class="list-unstyled d-inline-block my-2">
                                    @if ($plan->enable_domain == 'on')
                                        <li class="d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i
                                                    class="text-primary ti ti-circle-plus"></i></span>{{ __('Custom Domain') }}
                                        </li>
                                    @else
                                        <li class="text-danger d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i
                                                    class="text-danger ti ti-circle-plus"></i></span>{{ __('Custom Domain') }}
                                        </li>
                                    @endif
                                    @if ($plan->enable_subdomain == 'on')
                                        <li class="d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i class="text-primary ti ti-circle-plus"></i></span>{{ __('Sub Domain') }}
                                        </li>
                                    @else
                                        <li class="text-danger d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i class="text-danger ti ti-circle-plus"></i></span>{{ __('Sub Domain') }}
                                        </li>
                                    @endif
                                    @if ($plan && $plan->enable_chatgpt == 'on')
                                        <li class="d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i class="text-primary ti ti-circle-plus"></i></span>{{ __('Chatgpt') }}
                                        </li>
                                    @else
                                        <li class="text-danger d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i class="text-danger ti ti-circle-plus"></i></span>{{ __('Chatgpt') }}
                                        </li>
                                    @endif
                                    @if ($plan && $plan->pwa_store == 'on')
                                        <li class="d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i class="text-primary ti ti-circle-plus"></i></span>
                                            {{ __('Progressive Web App (PWA)') }}
                                        </li>
                                    @else
                                        <li class="text-danger d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i
                                                    class="text-danger ti ti-circle-plus"></i></span>{{ __('Progressive Web App (PWA)') }}
                                        </li>
                                    @endif
                                    @if ($plan && $plan->shipping_method == 'on')
                                        <li class="d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i class="text-primary ti ti-circle-plus"></i></span>
                                            {{ __('Shipping Method') }}
                                        </li>
                                    @else
                                        <li class="text-danger d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i
                                                    class="text-danger ti ti-circle-plus"></i></span>{{ __('Shipping Method') }}
                                        </li>
                                    @endif
                                    @if ($plan && $plan->enable_tax == 'on')
                                        <li class="d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i class="text-primary ti ti-circle-plus"></i></span>{{ __('Enable Tax') }}
                                        </li>
                                    @else
                                        <li class="text-danger d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i class="text-danger ti ti-circle-plus"></i></span>{{ __('Enable Tax') }}
                                        </li>
                                    @endif
                                    @if ($plan && $plan->storage_limit != '0.00')
                                        <li class="d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i class="text-primary ti ti-circle-plus"></i></span>
                                            {{ $plan->storage_limit }}{{ __('MB Storage') }}
                                        </li>
                                    @else
                                        <li class="text-danger d-flex align-items-center">
                                            <span class="theme-avtar">
                                                <i
                                                    class="text-danger ti ti-circle-plus"></i></span>{{ __('0 MB Storage') }}
                                        </li>
                                    @endif
                                </ul>
                            </div>
                            <div class="row d-flex">
                                @if (\Auth::user()->type != 'super admin')
                                    @if (\Auth::user()->type == 'admin' && \Auth::user()->trial_expire_date)
                                        @if (\Auth::user()->type == 'admin' && \Auth::user()->trial_plan == $plan->id)
                                            <p class="display-total-time mb-0">
                                                {{ __('Plan Trial Expired : ') }}
                                                {{ !empty(\Auth::user()->trial_expire_date) ? \Auth::user()->dateFormat(\Auth::user()->trial_expire_date) : 'Unlimited' }}
                                            </p>
                                        @elseif(
                                            \Auth::user()->plan_id == $plan->id &&
                                                !empty(\Auth::user()->trial_expire_date) &&
                                                \Auth::user()->trial_expire_date < date('Y-m-d') &&
                                                $plan->price > 0)
                                            <div class="col-12">
                                                <p
                                                    class="server-plan font-bold text-center bg-primary mb-0 btn btn-primary w-100 text-success">
                                                    {{ __('Expired') }}
                                                </p>
                                            </div>
                                        @endif
                                    @else
                                        @if (\Auth::user()->type == 'admin' && \Auth::user()->plan_id == $plan->id)
                                            <p class="display-total-time mb-0">
                                                {{ __('Plan Expired : ') }}
                                                {{ !empty(\Auth::user()->plan_expire_date) ? \Auth::user()->dateFormat(\Auth::user()->plan_expire_date) : 'Unlimited' }}
                                            </p>
                                        @elseif(
                                            \Auth::user()->plan_id == $plan->id &&
                                                !empty(\Auth::user()->plan_expire_date) &&
                                                \Auth::user()->plan_expire_date < date('Y-m-d') &&
                                                $plan->price > 0)
                                            <div class="col-12">
                                                <p
                                                    class="server-plan font-bold text-center bg-primary mb-0 btn btn-primary w-100 text-success">
                                                    {{ __('Expired') }}
                                                </p>
                                            </div>
                                        @endif
                                    @endif
                                    @if ($plan->id != \Auth::user()->plan_id)
                                        @if ($plan->price > 0)
                                            <div class="{{ $plan->id == 1 ? 'col-12' : 'col-12'  }}">
                                                <div class="row">
                                                    <div class="col-9">
                                                    <a href="{{ route('stripe', \Illuminate\Support\Facades\Crypt::encrypt($plan->id)) }}"
                                                        class="btn w-100 btn-badge btn-primary d-flex align-items-center gap-2  justify-content-center">{{ __('Subscribe') }}
                                                        <i class="fas fa-arrow-right"></i>
                                                    </a>
                                                    </div>  
                                                    <div class="col-3">
                                                    @if (\Auth::user()->type != 'super admin' && \Auth::user()->plan_id != $plan->id)
                                                        @if ($plan && $plan->id != 1)
                                                            @if (\Auth::user()->requested_plan != $plan->id)
                                                                <a href="{{ route('send.request', [\Illuminate\Support\Facades\Crypt::encrypt($plan->id)]) }}"
                                                                    class="btn btn-badge btn-primary btn-icon w-100"
                                                                    data-title="{{ __('Send Request') }}" data-bs-toggle="tooltip"
                                                                    data-bs-placement="top" title="{{ __('Send Request') }}">
                                                                    <span class="btn-inner--icon"><i class="fas fa-share"></i></span>
                                                                </a>
                                                            @else
                                                                <a href="{{ route('request.cancel', \Auth::user()->id) }}"
                                                                    class="btn btn-badge btn-icon btn-danger w-100"
                                                                    data-title="{{ __('Cancel Request') }}" data-bs-toggle="tooltip"
                                                                    data-bs-placement="top" title="{{ __('Cancel Request') }}">
                                                                    <span class="btn-inner--icon"><i class="fas fa-times"></i></span>
                                                                </a>
                                                            @endif
                                                        @endif
                                                    @endif
                                                    </div>  
                                                </div>  
                                                <div class="row">  
                                                    <div class="col-12">  
                                                        @if (\Auth::user()->type != 'super admin' && \Auth::user()->plan_id != $plan->id)
                                                            @if ($plan->price > 0 && \Auth::user()->trial_plan == 0 && \Auth::user()->plan_id != $plan->id && $plan->trial == 1)
                                                                <a href="{{ route('plan.trial', \Illuminate\Support\Facades\Crypt::encrypt($plan->id)) }}"
                                                                    class="btn btn-lg btn-primary btn-badge btn-icon m-1 w-100">{{ __('Start Free Trial') }}</a>
                                                            @endif
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    @endif
                                
                                @elseif (\Auth::user()->type == 'super admin' && isset($plan->trial) && ($plan->trial == 1) && isset($plan->trial_days) && !empty($plan->trial_days))
                                    <p class="display-total-time mb-0">
                                        {{ __('Plan Expired : ') }}
                                        {{ $plan->trial_days .' '. __('Days') }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    <div class="row">
        <div class="col-md-12">
        <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection
@push('custom-script')
    <script>
        $(document).on('change', '#trial', function() {
            if ($(this).is(':checked')) {
                $('.plan_div').removeClass('d-none');
                $('#trial_days').attr("required", true);

            } else {
                $('.plan_div').addClass('d-none');
                $('#trial_days').removeAttr("required");
            }
        });
    </script>

    <script>
        $(document).on("click", ".is_disable", function() {

            var id = $(this).attr('data-id');
            var is_disable = ($(this).is(':checked')) ? $(this).val() : 0;
            $.ajax({
                url: '{{ route('plan.disable') }}',
                type: 'POST',
                data: {
                    "is_disable": is_disable,
                    "id": id,
                    "_token": "{{ csrf_token() }}",
                },
                success: function(data) {
                    $('#loader').fadeOut();
                    if (data.success) {
                        show_toastr('Success', data.success, 'success');
                    } else {
                        show_toastr('error', data.error);

                    }
                }
            });
        });
    </script>
@endpush
