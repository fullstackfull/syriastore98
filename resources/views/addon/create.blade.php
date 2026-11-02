@extends('layouts.app')

@section('page-title', __('Add New Theme'))

@section('action-button')

@endsection

@section('breadcrumb')
    <li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('module.index') }}">{{ __('Add-on Manager') }}</a></li>    
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Add New Theme') }}</li>
@endsection

{{-- F16: this was asset('public/...') and 404d. Not corrected but REMOVED: the admin
     layout already serves dropzone.css at app.blade.php:117, and footerlink serves the JS
     before @stack('custom-script') runs. Loading a second copy of the bundle here would
     also re-set `Dropzone.autoDiscover` to true after footerlink disarms it. --}}

@section('content')
    <div class="row justify-content-center">
        <div class="col-sm-12 col-md-10 col-xxl-8">
            <div class="card">
                <div class="card-body">
                    <SECTION>
                        <DIV id="dropzone">
                            <FORM class="dropzone needsclick" id="demo-upload">
                                <DIV class="dz-message needsclick">
                                    {{ __('Drop files here or click to upload and install.')}}<BR>
                                </DIV>
                            </FORM>
                        </DIV>
                    </SECTION>
                </div>
            </div>
        </div>
    </div>

@endsection

@push('custom-script')

    <script>
        // Dropzone has been added as a global variable.
        Dropzone.autoDiscover = false;
        var dropzone = new Dropzone('#demo-upload', {
            thumbnailHeight: 120,
            thumbnailWidth: 120,
            maxFilesize: 200,
            acceptedFiles: '.zip',
            url: "{{ route('addon.theme') }}",
            success: function(file, response) {
                if (response.status == 'success')
                {
                    show_toastr('Success', response.message, 'success');
                    setTimeout(() => {
                        window.location.href = "{{ route('module.index') }}";
                    }, 1000);
                }
                else
                {
                    show_toastr('Error', response.message, 'error');
                    setTimeout(() => {
                        window.location.href = "{{ route('module.index') }}";
                    }, 1000);
                }
            }
        });
        dropzone.on('sending', function(file, xhr, formData) {
            formData.append('_token', "{{ csrf_token() }}");
        });
    </script>
@endpush