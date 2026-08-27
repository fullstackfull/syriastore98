<span class="d-flex gap-1 justify-content-end">
    <div>
        <a href="{{route('response.request',[$prequest->id,1])}}" class="btn btn-success btn-badge btn-sm" data-bs-toggle="tooltip"
        title="{{ __('Accept') }}">
            <i class="fas fa-check"></i>
        </a>
        <a href="{{route('response.request',[$prequest->id,0])}}" class="btn btn-danger btn-badge btn-sm" data-bs-toggle="tooltip"
        title="{{ __('Reject') }}">
            <i class="fas fa-times"></i>
        </a>
    </div>
</span>