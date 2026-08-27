<form action="{{ route('coupencode.ststus.store') }}" method="POST">
    @csrf
    <input type="hidden" name="id" value="{{ $request->id }}">
    <button type="submit" class="btn btn-sm btn-success" name="status" value="accept"><i class="ti ti-check"
            data-bs-toggle="tooltip" data-bs-original-title="{{ __('Accept') }}">
        </i></button>

    <button type="submit" class="btn btn-sm btn-danger" name="status" value="reject"><i class="ti ti-x"
            data-bs-toggle="tooltip" data-bs-original-title="{{ __('Reject') }}">
        </i></button>
</form>
