@permission('Status Customer')
    @if ($customer->regiester_date != null)
        <div class="form-check form-switch">
            <input class="form-check-input page-checkbox" id="{{ $customer->id }}"
                type="checkbox" name="page_active" data-onstyle="success"
                data-offstyle="danger" data-toggle="toggle" data-on="off"
                data-off="on"
                @if ($customer->status == 1) checked="checked" @endif />
        </div>
    @endif
@endpermission