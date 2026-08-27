<div id="frequently-view-setting">
    <div class="card mb-3">
        <form method="POST" action="{{ route('frequently-product.settings') }}" accept-charset="UTF-8">
            @csrf
            <div class="card-header">
                <div class="row">
                    <div class="col-12">
                        <h5 class="h6 md-0">{{ __('Frequently Product Setting') }}</h5>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="form-group col-md-6">
                        {{ Form::label('Frequently Product Limit', __('Frequently Product Limit'), ['class' => 'form-label']) }}
                        {{ Form::text('frequently_product_limit', isset($setting['frequently_product_limit']) ? $setting['frequently_product_limit'] : '2', ['class' => 'form-control', 'rows' => 3, 'placeholder' => __('Frequently Product Limit')]) }}
                        @error('frequently_product_limit')
                            <span class="invalid-about" role="alert">
                                <strong class="text-danger">{{ $message }}</strong>
                            </span>
                        @enderror
                    </div>
                </div>
            </div>
            <div class="card-footer text-end">
                {{ Form::submit(__('Save Changes'), ['class' => 'btn btn-xs btn-primary btn-badge']) }}
            </div>
        </form>
    </div>
</div>
