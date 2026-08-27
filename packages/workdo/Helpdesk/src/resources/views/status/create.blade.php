<form action="{{ route('helpdesk.status.store') }}" method="post">
    @csrf
    <div class="row">
        <div class="col-md-12">
            <div class="form-group">
                <label for="status_name" class="form-label">{{ __('Status Name') }}</label>
                <input type="text" id="status_name" name="status_name" class="form-control" required="required"
                    placeholder="{{ __('Enter Status') }}">
            </div>
        </div>
        <div class="form-group col-md-12">
            <label for="status_color" class="form-label">{{ __('Color') }}</label>
            <input type="color" id="status_color" name="status_color" class="form-control jscolor"
                required="required">
        </div>
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{ __('Cancel') }}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{ __('Create') }}" class="btn btn-badge mx-1 btn-primary">
    </div>
</form>
