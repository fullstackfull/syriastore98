@if($product_image)
    <div class="card">
        <div class="card-header d-flex justify-content-between">
            <p class="mb-0"><b>{{ __('Your Digital product is here:') }}</b>
            </p>
        </div>
        <div class="card-body">
                <div class="d-flex align-items-center" style="gap:5px">
                    @foreach ($product_image as $file)
                        <a href="{{ get_file($file->image_path) }}" download="" data-original-title="{{ __('Download') }}" class="btn">{{__('Attachment')}}<i class="ti ti-download"></i></a>
                    @endforeach
                </div>
        </div>
    </div>
@endif
