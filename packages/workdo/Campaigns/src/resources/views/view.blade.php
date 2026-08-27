<div class="row pb-2">

    <div class="row">
        <div class="col-md-4">
            <div class="form-control-label"><b>{{ __('User') }}</b></div>
        </div>
        <div class="col-md-6">
            <p class="mb-4">
                {{ $campaigns->users->name }}
            </p>
        </div>
    </div>
    <div class="row">
        <div class="col-md-4">
            <div class="form-control-label"><b>{{ __('Store') }}</b></div>
        </div>
        <div class="col-md-6">
            <p class="mb-4">
                {{ $campaigns->stores->name }}
            </p>
        </div>
    </div>
    <div class="row">
        <div class="col-md-4">
            <div class="form-control-label"><b>{{ __('Product') }}</b></div>
        </div>
        <div class="col-md-6">
            <p class="mb-4">
                {{ $campaigns->products->name }}
            </p>
        </div>

    </div>
    <div class="row">
        <div class="col-md-4">
            <div class="form-control-label"><b>{{ __('Start Date') }}</b></div>
        </div>
        <div class="col-md-6">
            <p class="mb-4">
                {{ $campaigns->start_date }}
            </p>
        </div>
    </div>
    <div class="row">
        <div class="col-md-4">
            <div class="form-control-label"><b>{{ __('End Date') }}</b></div>
        </div>
        <div class="col-md-6">
            <p class="mb-4">
                {{ $campaigns->end_date }}
            </p>
        </div>
    </div>
    <div class="row">
        <div class="col-md-4">
            <div class="form-control-label"><b>{{ __('Total Days') }}</b></div>
        </div>
        <div class="col-md-6">
            <p class="mb-4">
                {{ $campaigns->total_days }}
            </p>
        </div>
    </div>


</div>

<div class="modal-footer p-0 pt-3">
    <a href="{{ route('change.status.campaigns', [$campaigns->id, 1]) }}" class="btn btn-success btn-xs">
        {{ __('Approve') }}
    </a>
    <a href="{{ route('change.status.campaigns', [$campaigns->id, 2]) }}" class="btn btn-danger btn-xs">
        {{ __('Decline') }}
    </a>

</div>
