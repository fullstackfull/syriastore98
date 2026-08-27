<span class="d-flex gap-1 justify-content-end">
    <form action="{{ route('coupencode.ststus.store') }}" method="POST">
        @csrf
        <input type="hidden" name="id" value="{{ $item->id }}">

            <button type="submit" class="btn btn-sm btn-success btn-badge" name="status" value="accept" data-bs-toggle="tooltip" title="{{ __('Accept') }}"><i class="ti ti-check"
                    data-bs-original-title="{{ __('Accept') }}">
                </i></button>

            <button type="submit" class="btn btn-sm btn-danger btn-badge mx-1" name="status" value="reject" data-bs-toggle="tooltip" title="{{ __('Reject') }}" ><i class="ti ti-x"
                    data-bs-original-title="{{ __('Reject') }}">
                </i></button>
    </form>
</span>
