<div class="row">
@foreach($plans as $plan)
    <div class="col-lg-4 col-md-6 mb-3">
        <div class="list-group-item  h-100 upgrade-plan-model-card">
            <div class="row align-items-center">
                <div class=" d-flex flex-column gap-2 ml-n2 align-items-start mb-3">
                    <a href="#!" class="badge f-12 p-2 d-block h6 mb-0 bg-warning">{{$plan->name}}</a>
                    <div>
                        <span class="f-20 f-w-600">{{GetCurrency().$plan->price}} {{' / '. (\App\Models\Plan::getDurationsArr()[$plan->duration])}}</span>
                    </div>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2 justify-content-between mb-3 ">
                    <div class="text-center ml-n2">
                        <div>
                            <span class="text-lg">{{$plan->max_stores}}</span>
                        </div>
                        <a href="#!" class="d-block h6 mb-0 f-14">{{__('Stores')}}</a>
                    </div>
                    <div class="text-center ml-n2">
                        <div class="text-break">
                            <span class="text-lg">{{$plan->max_products}}</span>
                        </div>
                        <a href="#!" class="d-block h6 mb-0 f-14">{{__('Products')}}</a>
                    </div>
                    <div class="text-center ml-n2">
                        <div>
                            <span class="text-lg">{{$plan->max_users}}</span>
                        </div>
                        <a href="#!" class="d-block h6 mb-0 f-14">{{__('Users')}}</a>
                    </div>
                </div>
                <div class="d-flex justify-content-center">
                    @if($user->plan_id==$plan->id)
                    <span class="d-flex align-items-center badge bg-primary d-block p-2 position-absolute justify-content-center" style="top: 10px; right: 10px; border-radius:5px 0 0 5px">
                        <i class="f-10 lh-1 fas fa-circle text-primary"></i>
                        <span class="ms-2">{{ __('Active')}}</span>
                    </span>
                    @else
                        <a href="{{route('plan.active',[$user->id,$plan->id])}}" class="btn btn-badge btn-xs btn-primary btn-icon w-100" data-toggle="tooltip" data-original-title="{{__('Click to Upgrade Plan')}}">
                            <span class="btn-inner--icon">{{ __('Upgrade Plan')}}</span>
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endforeach
</div>

