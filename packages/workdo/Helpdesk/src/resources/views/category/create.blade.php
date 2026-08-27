<form action="{{ route('helpdesk.category.store') }}" method="post">
    @csrf
    <div class="row">
        <div class="col-md-12">
            <div class="form-group">
                <label for="category_name" class="form-label">{{ __('Category Name') }}</label>
                <input type="text" id="category_name" name="category_name" class="form-control" required="required"
                    placeholder="{{ __('Enter Category') }}">
            </div>
        </div>
        <div class="form-group col-md-12">
            <label for="category_color" class="form-label">{{ __('Color') }}</label>
            <input type="color" id="category_color" name="category_color" class="form-control jscolor"
                required="required">
        </div>
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{ __('Cancel') }}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{ __('Create') }}" class="btn btn-primary btn-badge mx-1">
    </div>
</form>
