<span class="d-flex gap-1 justify-content-end">
    <div>
        <a aria-label="{{ __('Accept :thing', ['thing' => __('Plan Request')]) }}" href="{{route('response.request',[$prequest->id,1])}}" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" data-bs-toggle="tooltip"
        title="{{ __('Accept') }}">
            <i class="fas fa-check"></i>
        </a>
        <a aria-label="{{ __('Reject :thing', ['thing' => __('Plan Request')]) }}" href="{{route('response.request',[$prequest->id,0])}}" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet" data-bs-toggle="tooltip"
        title="{{ __('Reject') }}">
            <i class="fas fa-times"></i>
        </a>
    </div>
</span>