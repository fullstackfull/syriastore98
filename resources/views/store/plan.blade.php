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
                    {{--
                        LOGICAL PROPERTIES, NOT `right` AND A PHYSICAL `border-radius`.

                        This is the only live physical-property defect in the customers/stores area,
                        and it is the one kind the project's RTL mechanism CANNOT reach: the admin
                        layout swaps `custom.css` for `rtl-custom.css` (layouts/app.blade.php:181-189),
                        and a stylesheet swap cannot touch an inline `style` attribute -- an inline
                        declaration outranks any sheet rule that is not `!important`. So in Arabic
                        this badge stayed pinned to the PHYSICAL right while the card around it
                        mirrored, and its rounded corners faced the wrong way.

                        `inset-inline-end` is `right` in LTR and `left` in RTL. The two
                        `border-*-start-radius` longhands round the INLINE-START corners, which is
                        what `5px 0 0 5px` meant in LTR (top-left and bottom-left) -- the inner edge
                        facing the card, square against the card's outer edge. Both are supported in
                        every browser this admin targets.

                        AND THE ICON NO LONGER PAINTS PRIMARY-ON-PRIMARY. `text-primary` inside
                        `badge bg-primary` is the brand colour on the brand colour. Bootstrap gives
                        the badge a white foreground, so dropping the class lets the icon inherit it
                        and the dot becomes visible instead of a hole.
                    --}}
                    <span class="d-flex align-items-center badge bg-primary d-block p-2 position-absolute justify-content-center" style="top: 10px; inset-inline-end: 10px; border-start-start-radius: 5px; border-end-start-radius: 5px; border-start-end-radius: 0; border-end-end-radius: 0">
                        <i class="f-10 lh-1 ti ti-circle"></i>
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

