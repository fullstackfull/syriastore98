<span class="d-flex gap-1 justify-content-end">
<a href="{{ route('refund-request.show', \Illuminate\Support\Facades\Crypt::encrypt($refund_request->order_id)) }}"
    class="btn btn-sm btn-warning" data-bs-toggle="tooltip"
    title="{{ __('Show') }}">
    <i class="ti ti-eye"></i>
</a>
</span>
