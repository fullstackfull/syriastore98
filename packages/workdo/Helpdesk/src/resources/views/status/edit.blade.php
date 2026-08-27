<form method="post" action="{{ route('helpdesk.status.update', $helpdeskStatus->id) }}">
    @csrf
    <div class="row">
        <div class="col-md-12">
            <div class="form-group"> <label for="status_name" class="form-label">{{ __('Status Name') }}</label>
                <input type="text" name="status_name" id="status_name" class="form-control" required
                    placeholder="Enter Status" value="{{ $helpdeskStatus->status_name }}">
            </div>
        </div>
        <div class="form-group col-md-12"> <label for="status_color"
                class="form-label">{{ __('Color') }}</label> <input type="color" name="status_color"
                id="status_color" class="form-control jscolor" required
                value="{{ $helpdeskStatus->status_color }}">
        </div>
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-secondary btn-badge" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Save Changes')}}" class="btn btn-primary btn-badge mx-1">
    </div>
</form>
