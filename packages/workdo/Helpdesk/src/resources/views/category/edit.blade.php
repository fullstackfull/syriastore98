<form method="post" action="{{ route('helpdesk.category.update',$helpdeskCategory->id) }}">
    @csrf
    <div class="row">
        <div class="col-md-12">
            <div class="form-group"> <label for="category_name" class="form-label">{{ __('Category Name') }}</label>
                <input type="text" name="category_name" id="category_name" class="form-control" required
                    placeholder="Enter Category" value="{{ $helpdeskCategory->name }}">
            </div>
        </div>
        <div class="form-group col-md-12"> <label for="category_color"
                class="form-label">{{ __('Color') }}</label> <input type="color" name="category_color"
                id="category_color" class="form-control jscolor" required value="{{ $helpdeskCategory->color }}">
        </div>
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-secondary btn-badge" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Save Changes')}}" class="btn btn-primary btn-badge mx-1">
    </div>
</form>
