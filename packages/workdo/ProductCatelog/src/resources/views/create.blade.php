{{ Form::open(['route' => 'productcatelog.store', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
    <div class="row">
        @php
            $MainCategoryList = \App\Models\Category::with('product_details')->where('status', 1)
            ->where('store_id', Auth::user()->current_store)->get();
        @endphp
        <div class="form-group col-12" data_val='0'>
            <label> {{ __('Category') }}</label>
            <select class="form-control" name="category_id" id="category_id">
                <option>{{__('Select Category')}}</option>
                @foreach ($MainCategoryList as $category)
                    <option value="{{$category->id}}">{{$category->name}}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group col-12">
            <label> {{ __('Customer E-mail') }} </label>
            <input type="email" name="email" class="form-control" placeholder="shop@company.com" required>
        </div>
        <div class="form-group col-12">
            <label> {{ __('Description') }} </label>
            <textarea class="form-control" name="description" placeholder="Describe here.." rows="8"></textarea>
        </div>
        <div class="modal-footer pb-0">
            <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
            <input type="submit" value="{{__('Create')}}" class="btn btn-badge btn-primary mx-1">
        </div>
    </div>
{!! Form::close() !!}

<script>
    if ($(".multi-select").length > 0) {
        $($(".multi-select")).each(function(index, element) {
            var id = $(element).attr('id');
            var multipleCancelButton = new Choices(
                '#' + id, {
                    removeItemButton: true,
                }
            );
        });
    }
</script>
